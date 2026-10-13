<?php

namespace App\Console\Commands;

use App\Models\Package;
use App\Notifications\VulnerabilitiesFound;
use App\Services\SecurityAdvisoryChecker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

#[Signature('package:audit {package? : Package name or ID to audit}')]
#[Description('Check packages for known security vulnerabilities')]
class AuditPackages extends Command
{
    /** @var array<string, array<int, array{advisoryId: string, title: string, link: string, cve: string|null, affectedVersions: string, reportedAt: string|null, severity: string|null}>> */
    private array $vulnerabilities = [];

    public function handle(SecurityAdvisoryChecker $checker): int
    {
        if ($identifier = $this->argument('package')) {
            $package = Package::where('id', $identifier)
                ->orWhere('name', $identifier)
                ->first();

            if (! $package) {
                $this->error("Package not found: {$identifier}");

                return self::FAILURE;
            }

            $count = $this->auditPackage($checker, $package);

            if ($count > 0) {
                $this->sendNotification();
            }

            return $count > 0 || $count === -1 ? self::FAILURE : self::SUCCESS;
        }

        $packages = Package::all();

        if ($packages->isEmpty()) {
            $this->info('No packages to audit.');

            return self::SUCCESS;
        }

        $totalAdvisories = 0;
        $failedChecks = 0;

        foreach ($packages as $package) {
            $count = $this->auditPackage($checker, $package, showSuccessMessage: false);

            if ($count === -1) {
                $failedChecks++;

                continue;
            }

            $totalAdvisories += $count;
        }

        if ($totalAdvisories === 0 && $failedChecks === 0) {
            $this->info('No vulnerabilities found in any package.');
        }

        if ($totalAdvisories > 0) {
            $this->warn("{$totalAdvisories} vulnerability(ies) found across all packages.");
            $this->sendNotification();
        }

        if ($failedChecks > 0) {
            $this->warn("{$failedChecks} package(s) could not be checked.");
        }

        return $totalAdvisories > 0 || $failedChecks > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Audit a single package. Returns the number of advisories found, or -1 on error.
     */
    private function auditPackage(SecurityAdvisoryChecker $checker, Package $package, bool $showSuccessMessage = true): int
    {
        try {
            $advisories = $checker->check(
                $package->name,
                $package->ecosystem,
                $package->versions()->pluck('version')->all(),
            );
        } catch (\Throwable $e) {
            $this->error("Failed to check {$package->name}: {$e->getMessage()}");

            return -1;
        }

        if ($advisories === []) {
            if ($showSuccessMessage) {
                $this->info("No vulnerabilities found for {$package->name}.");
            }

            return 0;
        }

        $this->vulnerabilities[$package->name] = $advisories;

        $this->warn('Found '.count($advisories)." vulnerability(ies) for {$package->name}:");

        $this->table(
            ['Advisory', 'CVE', 'Affected Versions', 'Severity', 'Title'],
            array_map(fn (array $advisory): array => [
                $advisory['advisoryId'],
                $advisory['cve'] ?? '-',
                $advisory['affectedVersions'],
                $advisory['severity'] ?? '-',
                $advisory['title'],
            ], $advisories),
        );

        return count($advisories);
    }

    private function sendNotification(): void
    {
        $routes = [];

        if ($mailTo = config('repho.audit.mail_to')) {
            $routes['mail'] = $mailTo;
        }

        if ($slackChannel = config('repho.audit.slack_channel')) {
            $routes['slack'] = $slackChannel;
        }

        if ($routes === []) {
            return;
        }

        Notification::routes($routes)->notify(new VulnerabilitiesFound($this->vulnerabilities));
    }
}
