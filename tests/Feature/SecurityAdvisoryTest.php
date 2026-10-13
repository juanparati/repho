<?php

namespace Tests\Feature;

use App\Enums\Ecosystem;
use App\Models\Repository;
use App\Models\User;
use App\Services\SecurityAdvisoryChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SecurityAdvisoryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_package_with_vulnerabilities_is_rejected(): void
    {
        $repo = Repository::factory()->create();

        $this->mock(SecurityAdvisoryChecker::class, function ($mock) {
            $mock->shouldReceive('check')
                ->with('vendor/vulnerable-package', Ecosystem::Composer)
                ->once()
                ->andReturn([
                    [
                        'advisoryId' => 'PKSA-1234',
                        'title' => 'SQL Injection vulnerability',
                        'link' => 'https://example.com/advisory/1',
                        'cve' => 'CVE-2024-1234',
                        'affectedVersions' => '>=1.0,<1.5',
                        'reportedAt' => '2024-01-15',
                        'severity' => 'high',
                    ],
                ]);
        });

        $response = $this->actingAs($this->user)->post('/packages', [
            'repository_url' => 'https://github.com/vendor/vulnerable-package',
            'type' => 'github',
            'repository_ids' => [$repo->id],
        ]);

        $response->assertRedirect(route('packages.create'));
        $response->assertSessionHasErrors('repository_url');
        $this->assertDatabaseMissing('packages', [
            'name' => 'vendor/vulnerable-package',
        ]);
    }

    public function test_package_with_multiple_vulnerabilities_shows_all(): void
    {
        $repo = Repository::factory()->create();

        $this->mock(SecurityAdvisoryChecker::class, function ($mock) {
            $mock->shouldReceive('check')
                ->once()
                ->andReturn([
                    [
                        'advisoryId' => 'PKSA-1',
                        'title' => 'XSS vulnerability',
                        'link' => 'https://example.com/1',
                        'cve' => 'CVE-2024-0001',
                        'affectedVersions' => '>=2.0,<2.3',
                        'reportedAt' => '2024-01-01',
                        'severity' => 'medium',
                    ],
                    [
                        'advisoryId' => 'PKSA-2',
                        'title' => 'RCE vulnerability',
                        'link' => 'https://example.com/2',
                        'cve' => null,
                        'affectedVersions' => '>=1.0,<1.8',
                        'reportedAt' => '2024-02-01',
                        'severity' => 'critical',
                    ],
                ]);
        });

        $response = $this->actingAs($this->user)->post('/packages', [
            'repository_url' => 'https://github.com/vendor/multi-vuln',
            'type' => 'github',
            'repository_ids' => [$repo->id],
        ]);

        $response->assertRedirect(route('packages.create'));
        $response->assertSessionHasErrors('repository_url');

        $errors = session('errors')->get('repository_url');
        $this->assertStringContainsString('XSS vulnerability', $errors[0]);
        $this->assertStringContainsString('CVE-2024-0001', $errors[0]);
        $this->assertStringContainsString('RCE vulnerability', $errors[0]);
    }

    public function test_package_without_vulnerabilities_is_allowed(): void
    {
        Queue::fake();

        $repo = Repository::factory()->create();

        $this->mock(SecurityAdvisoryChecker::class, function ($mock) {
            $mock->shouldReceive('check')
                ->with('vendor/safe-package', Ecosystem::Composer)
                ->once()
                ->andReturn([]);
        });

        $response = $this->actingAs($this->user)->post('/packages', [
            'repository_url' => 'https://github.com/vendor/safe-package',
            'type' => 'github',
            'repository_ids' => [$repo->id],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('packages', [
            'name' => 'vendor/safe-package',
        ]);
    }

    public function test_advisory_without_cve_formats_correctly(): void
    {
        $repo = Repository::factory()->create();

        $this->mock(SecurityAdvisoryChecker::class, function ($mock) {
            $mock->shouldReceive('check')
                ->once()
                ->andReturn([
                    [
                        'advisoryId' => 'PKSA-5678',
                        'title' => 'Path traversal vulnerability',
                        'link' => 'https://example.com/advisory/5',
                        'cve' => null,
                        'affectedVersions' => '>=3.0,<3.2',
                        'reportedAt' => '2024-06-01',
                        'severity' => 'high',
                    ],
                ]);
        });

        $response = $this->actingAs($this->user)->post('/packages', [
            'repository_url' => 'https://github.com/vendor/vuln-no-cve',
            'type' => 'github',
            'repository_ids' => [$repo->id],
        ]);

        $response->assertSessionHasErrors('repository_url');
        $errors = session('errors')->get('repository_url');
        $this->assertStringContainsString('Path traversal vulnerability', $errors[0]);
        $this->assertStringNotContainsString('CVE-', $errors[0]);
        $this->assertStringContainsString('affects >=3.0,<3.2', $errors[0]);
    }
}
