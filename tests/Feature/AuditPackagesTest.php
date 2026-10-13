<?php

namespace Tests\Feature;

use App\Enums\Ecosystem;
use App\Models\Package;
use App\Notifications\VulnerabilitiesFound;
use App\Services\SecurityAdvisoryChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuditPackagesTest extends TestCase
{
    use RefreshDatabase;

    /** @var array{advisoryId: string, title: string, link: string, cve: string|null, affectedVersions: string, reportedAt: string, severity: string|null} */
    private array $sampleAdvisory = [
        'advisoryId' => 'PKSA-1234',
        'title' => 'SQL Injection vulnerability',
        'link' => 'https://example.com/advisory/1234',
        'cve' => 'CVE-2026-1234',
        'affectedVersions' => '>=1.0,<1.5',
        'reportedAt' => '2026-01-15',
        'severity' => 'high',
    ];

    public function test_audit_command_with_no_packages(): void
    {
        $this->artisan('package:audit')
            ->expectsOutput('No packages to audit.')
            ->assertExitCode(0);
    }

    public function test_audit_command_for_package_with_no_vulnerabilities(): void
    {
        Package::factory()->create(['name' => 'vendor/safe-pkg']);

        $this->mock(SecurityAdvisoryChecker::class, function ($mock) {
            $mock->shouldReceive('check')
                ->with('vendor/safe-pkg', Ecosystem::Composer, [])
                ->once()
                ->andReturn([]);
        });

        $this->artisan('package:audit', ['package' => 'vendor/safe-pkg'])
            ->expectsOutput('No vulnerabilities found for vendor/safe-pkg.')
            ->assertExitCode(0);
    }

    public function test_audit_command_for_package_with_vulnerabilities(): void
    {
        Package::factory()->create(['name' => 'vendor/vuln-pkg']);

        $this->mock(SecurityAdvisoryChecker::class, function ($mock) {
            $mock->shouldReceive('check')
                ->with('vendor/vuln-pkg', Ecosystem::Composer, [])
                ->once()
                ->andReturn([$this->sampleAdvisory]);
        });

        $this->artisan('package:audit', ['package' => 'vendor/vuln-pkg'])
            ->assertExitCode(1);
    }

    public function test_audit_command_fails_for_unknown_package(): void
    {
        $this->artisan('package:audit', ['package' => 'vendor/nonexistent'])
            ->expectsOutput('Package not found: vendor/nonexistent')
            ->assertExitCode(1);
    }

    public function test_audit_command_finds_package_by_id(): void
    {
        $package = Package::factory()->create(['name' => 'vendor/by-id']);

        $this->mock(SecurityAdvisoryChecker::class, function ($mock) {
            $mock->shouldReceive('check')
                ->with('vendor/by-id', Ecosystem::Composer, [])
                ->once()
                ->andReturn([]);
        });

        $this->artisan('package:audit', ['package' => $package->id])
            ->assertExitCode(0);
    }

    public function test_audit_all_packages_with_no_vulnerabilities(): void
    {
        Package::factory()->create(['name' => 'vendor/pkg-a']);
        Package::factory()->create(['name' => 'vendor/pkg-b']);

        $this->mock(SecurityAdvisoryChecker::class, function ($mock) {
            $mock->shouldReceive('check')->twice()->andReturn([]);
        });

        $this->artisan('package:audit')
            ->expectsOutput('No vulnerabilities found in any package.')
            ->assertExitCode(0);
    }

    public function test_audit_all_packages_reports_total_vulnerabilities(): void
    {
        Package::factory()->create(['name' => 'vendor/pkg-a']);
        Package::factory()->create(['name' => 'vendor/pkg-b']);

        $this->mock(SecurityAdvisoryChecker::class, function ($mock) {
            $mock->shouldReceive('check')
                ->with('vendor/pkg-a', Ecosystem::Composer, [])
                ->once()
                ->andReturn([$this->sampleAdvisory]);
            $mock->shouldReceive('check')
                ->with('vendor/pkg-b', Ecosystem::Composer, [])
                ->once()
                ->andReturn([]);
        });

        $this->artisan('package:audit')
            ->assertExitCode(1);
    }

    public function test_audit_all_packages_continues_after_failed_check_and_still_notifies(): void
    {
        Notification::fake();
        config(['repho.audit.mail_to' => 'admin@example.com']);

        Package::factory()->create(['name' => 'vendor/pkg-a']);
        Package::factory()->create(['name' => 'vendor/pkg-b']);

        $this->mock(SecurityAdvisoryChecker::class, function ($mock) {
            $mock->shouldReceive('check')
                ->with('vendor/pkg-a', Ecosystem::Composer, [])
                ->once()
                ->andThrow(new \RuntimeException('Connection timeout'));
            $mock->shouldReceive('check')
                ->with('vendor/pkg-b', Ecosystem::Composer, [])
                ->once()
                ->andReturn([$this->sampleAdvisory]);
        });

        $this->artisan('package:audit')
            ->expectsOutput('Failed to check vendor/pkg-a: Connection timeout')
            ->expectsOutput('1 package(s) could not be checked.')
            ->assertExitCode(1);

        Notification::assertSentOnDemand(VulnerabilitiesFound::class);
    }

    public function test_audit_all_packages_fails_without_false_all_clear_when_checks_fail(): void
    {
        Package::factory()->create(['name' => 'vendor/pkg-a']);

        $this->mock(SecurityAdvisoryChecker::class, function ($mock) {
            $mock->shouldReceive('check')
                ->with('vendor/pkg-a', Ecosystem::Composer, [])
                ->once()
                ->andThrow(new \RuntimeException('API down'));
        });

        $this->artisan('package:audit')
            ->doesntExpectOutput('No vulnerabilities found in any package.')
            ->assertExitCode(1);
    }

    public function test_audit_command_handles_checker_exception(): void
    {
        Package::factory()->create(['name' => 'vendor/fail-pkg']);

        $this->mock(SecurityAdvisoryChecker::class, function ($mock) {
            $mock->shouldReceive('check')
                ->with('vendor/fail-pkg', Ecosystem::Composer, [])
                ->once()
                ->andThrow(new \RuntimeException('Connection timeout'));
        });

        $this->artisan('package:audit', ['package' => 'vendor/fail-pkg'])
            ->expectsOutput('Failed to check vendor/fail-pkg: Connection timeout')
            ->assertExitCode(1);
    }

    public function test_audit_sends_mail_notification_when_configured(): void
    {
        Notification::fake();
        config(['repho.audit.mail_to' => 'admin@example.com']);

        Package::factory()->create(['name' => 'vendor/vuln-pkg']);

        $this->mock(SecurityAdvisoryChecker::class, function ($mock) {
            $mock->shouldReceive('check')
                ->with('vendor/vuln-pkg', Ecosystem::Composer, [])
                ->once()
                ->andReturn([$this->sampleAdvisory]);
        });

        $this->artisan('package:audit', ['package' => 'vendor/vuln-pkg'])
            ->assertExitCode(1);

        Notification::assertSentOnDemand(VulnerabilitiesFound::class, function ($notification, $channels, $notifiable) {
            return in_array('mail', $channels)
                && $notifiable->routes['mail'] === 'admin@example.com';
        });
    }

    public function test_audit_sends_slack_notification_when_configured(): void
    {
        Notification::fake();
        config(['repho.audit.slack_channel' => '#security-alerts']);

        Package::factory()->create(['name' => 'vendor/vuln-pkg']);

        $this->mock(SecurityAdvisoryChecker::class, function ($mock) {
            $mock->shouldReceive('check')
                ->with('vendor/vuln-pkg', Ecosystem::Composer, [])
                ->once()
                ->andReturn([$this->sampleAdvisory]);
        });

        $this->artisan('package:audit', ['package' => 'vendor/vuln-pkg'])
            ->assertExitCode(1);

        Notification::assertSentOnDemand(VulnerabilitiesFound::class, function ($notification, $channels, $notifiable) {
            return in_array('slack', $channels)
                && $notifiable->routes['slack'] === '#security-alerts';
        });
    }

    public function test_audit_sends_both_mail_and_slack_when_both_configured(): void
    {
        Notification::fake();
        config([
            'repho.audit.mail_to' => 'admin@example.com',
            'repho.audit.slack_channel' => '#security-alerts',
        ]);

        Package::factory()->create(['name' => 'vendor/vuln-pkg']);

        $this->mock(SecurityAdvisoryChecker::class, function ($mock) {
            $mock->shouldReceive('check')
                ->with('vendor/vuln-pkg', Ecosystem::Composer, [])
                ->once()
                ->andReturn([$this->sampleAdvisory]);
        });

        $this->artisan('package:audit', ['package' => 'vendor/vuln-pkg'])
            ->assertExitCode(1);

        Notification::assertSentOnDemand(VulnerabilitiesFound::class, function ($notification, $channels) {
            return in_array('mail', $channels) && in_array('slack', $channels);
        });
    }

    public function test_audit_does_not_send_notification_when_not_configured(): void
    {
        Notification::fake();
        config([
            'repho.audit.mail_to' => null,
            'repho.audit.slack_channel' => null,
        ]);

        Package::factory()->create(['name' => 'vendor/vuln-pkg']);

        $this->mock(SecurityAdvisoryChecker::class, function ($mock) {
            $mock->shouldReceive('check')
                ->with('vendor/vuln-pkg', Ecosystem::Composer, [])
                ->once()
                ->andReturn([$this->sampleAdvisory]);
        });

        $this->artisan('package:audit', ['package' => 'vendor/vuln-pkg'])
            ->assertExitCode(1);

        Notification::assertNothingSent();
    }

    public function test_audit_does_not_send_notification_when_no_vulnerabilities(): void
    {
        Notification::fake();
        config(['repho.audit.mail_to' => 'admin@example.com']);

        Package::factory()->create(['name' => 'vendor/safe-pkg']);

        $this->mock(SecurityAdvisoryChecker::class, function ($mock) {
            $mock->shouldReceive('check')
                ->with('vendor/safe-pkg', Ecosystem::Composer, [])
                ->once()
                ->andReturn([]);
        });

        $this->artisan('package:audit', ['package' => 'vendor/safe-pkg'])
            ->assertExitCode(0);

        Notification::assertNothingSent();
    }

    public function test_audit_notification_contains_vulnerability_data(): void
    {
        Notification::fake();
        config(['repho.audit.mail_to' => 'admin@example.com']);

        Package::factory()->create(['name' => 'vendor/vuln-pkg']);

        $this->mock(SecurityAdvisoryChecker::class, function ($mock) {
            $mock->shouldReceive('check')
                ->with('vendor/vuln-pkg', Ecosystem::Composer, [])
                ->once()
                ->andReturn([$this->sampleAdvisory]);
        });

        $this->artisan('package:audit', ['package' => 'vendor/vuln-pkg'])
            ->assertExitCode(1);

        Notification::assertSentOnDemand(VulnerabilitiesFound::class, function (VulnerabilitiesFound $notification) {
            return isset($notification->vulnerabilities['vendor/vuln-pkg'])
                && count($notification->vulnerabilities['vendor/vuln-pkg']) === 1
                && $notification->vulnerabilities['vendor/vuln-pkg'][0]['advisoryId'] === 'PKSA-1234';
        });
    }
}
