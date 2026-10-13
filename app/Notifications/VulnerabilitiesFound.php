<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\BlockKit\Blocks\SectionBlock;
use Illuminate\Notifications\Slack\SlackMessage;

class VulnerabilitiesFound extends Notification
{
    /**
     * @param  array<string, array<int, array{advisoryId: string, title: string, link: string, cve: string|null, affectedVersions: string, reportedAt: string|null, severity: string|null}>>  $vulnerabilities
     */
    public function __construct(
        public array $vulnerabilities,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = [];

        if (config('repho.audit.mail_to')) {
            $channels[] = 'mail';
        }

        if (config('repho.audit.slack_channel')) {
            $channels[] = 'slack';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $totalCount = array_sum(array_map('count', $this->vulnerabilities));

        $message = (new MailMessage)
            ->subject("Security Audit: {$totalCount} vulnerability(ies) found")
            ->greeting('Security Vulnerability Report')
            ->line("The package audit found {$totalCount} vulnerability(ies) across ".count($this->vulnerabilities).' package(s).');

        foreach ($this->vulnerabilities as $packageName => $advisories) {
            $message->line("**{$packageName}** — ".count($advisories).' advisory(ies):');

            foreach ($advisories as $advisory) {
                $cve = $advisory['cve'] ?? 'N/A';
                $severity = $advisory['severity'] ?? 'unknown';
                $message->line("- [{$advisory['advisoryId']}]({$advisory['link']}) ({$cve}, {$severity}): {$advisory['title']} — affected: {$advisory['affectedVersions']}");
            }
        }

        return $message;
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        $totalCount = array_sum(array_map('count', $this->vulnerabilities));

        $slackMessage = (new SlackMessage)
            ->text("Security audit found {$totalCount} vulnerability(ies)")
            ->headerBlock("Security Audit: {$totalCount} vulnerability(ies) found");

        foreach ($this->vulnerabilities as $packageName => $advisories) {
            $slackMessage->dividerBlock();
            $slackMessage->sectionBlock(function (SectionBlock $block) use ($packageName, $advisories) {
                $lines = ["*{$packageName}* — ".count($advisories)." advisory(ies)\n"];

                foreach ($advisories as $advisory) {
                    $cve = $advisory['cve'] ?? 'N/A';
                    $severity = $advisory['severity'] ?? 'unknown';
                    $lines[] = "• <{$advisory['link']}|{$advisory['advisoryId']}> ({$cve}, {$severity}): {$advisory['title']}";
                }

                $block->text(implode("\n", $lines))->markdown();
            });
        }

        return $slackMessage;
    }
}
