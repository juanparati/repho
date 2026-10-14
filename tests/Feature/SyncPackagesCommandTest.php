<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Repository;
use App\Models\SecurityAdvisory;
use App\Services\GitHubPackageFetcher;
use App\Services\PackageSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncPackagesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_command_with_no_packages(): void
    {
        $this->artisan('package:sync')
            ->expectsOutput('No packages to sync.')
            ->assertExitCode(0);
    }

    public function test_sync_command_syncs_specific_package(): void
    {
        $package = Package::factory()->create(['name' => 'vendor/test']);

        $this->mock(PackageSynchronizer::class, function ($mock) {
            $mock->shouldReceive('sync')->once();
        });

        $this->artisan('package:sync', ['package' => 'vendor/test'])
            ->assertExitCode(0);
    }

    public function test_sync_command_fails_for_unknown_package(): void
    {
        $this->artisan('package:sync', ['package' => 'vendor/nonexistent'])
            ->expectsOutput('Package not found: vendor/nonexistent')
            ->assertExitCode(1);
    }

    public function test_add_command_creates_and_syncs_package(): void
    {
        $this->mock(PackageSynchronizer::class, function ($mock) {
            $mock->shouldReceive('sync')->once();
        });

        $this->artisan('package:add', ['url' => 'https://github.com/vendor/new-pkg'])
            ->assertExitCode(0);

        $this->assertDatabaseHas('packages', [
            'name' => 'vendor/new-pkg',
            'type' => 'github',
        ]);
    }

    public function test_add_command_reads_npm_name_from_package_json_when_name_is_omitted(): void
    {
        $this->mock(PackageSynchronizer::class, function ($mock) {
            $mock->shouldReceive('sync')->once();
        });
        Http::fake([
            'api.github.com/repos/vendor/pkg' => Http::response(['default_branch' => 'main']),
            'api.github.com/repos/vendor/pkg/contents/package.json*' => Http::response([
                'content' => base64_encode(json_encode(['name' => '@Vendor/Pkg', 'version' => '1.0.0'])),
            ]),
        ]);

        $this->artisan('package:add', ['url' => 'https://github.com/vendor/pkg', '--ecosystem' => 'npm'])
            ->assertExitCode(0);

        $this->assertDatabaseHas('packages', ['name' => '@vendor/pkg', 'ecosystem' => 'npm']);
    }

    public function test_add_command_reads_npm_name_from_the_given_subdirectory(): void
    {
        $this->mock(PackageSynchronizer::class, function ($mock) {
            $mock->shouldReceive('sync')->once();
        });
        Http::fake([
            'api.github.com/repos/vendor/pkg' => Http::response(['default_branch' => 'main']),
            'api.github.com/repos/vendor/pkg/contents/packages/ui/package.json*' => Http::response([
                'content' => base64_encode(json_encode(['name' => '@vendor/ui'])),
            ]),
        ]);

        $this->artisan('package:add', ['url' => 'https://github.com/vendor/pkg', '--ecosystem' => 'npm', '--path' => 'packages/ui'])
            ->assertExitCode(0);

        $this->assertDatabaseHas('packages', ['name' => '@vendor/ui', 'ecosystem' => 'npm', 'subdirectory' => 'packages/ui']);
    }

    public function test_add_command_fails_when_npm_name_cannot_be_read_from_package_json(): void
    {
        $this->mock(PackageSynchronizer::class, function ($mock) {
            $mock->shouldNotReceive('sync');
        });
        Http::fake([
            'api.github.com/repos/vendor/pkg' => Http::response(['default_branch' => 'main']),
            'api.github.com/repos/vendor/pkg/contents/*' => Http::response(null, 404),
        ]);

        $this->artisan('package:add', ['url' => 'https://github.com/vendor/pkg', '--ecosystem' => 'npm'])
            ->assertExitCode(1);

        $this->assertDatabaseCount('packages', 0);
    }

    public function test_add_command_detects_gitlab_type(): void
    {
        $this->mock(PackageSynchronizer::class, function ($mock) {
            $mock->shouldReceive('sync')->once();
        });

        $this->artisan('package:add', ['url' => 'https://gitlab.com/vendor/pkg'])
            ->assertExitCode(0);

        $this->assertDatabaseHas('packages', [
            'type' => 'gitlab',
        ]);
    }

    public function test_add_command_defaults_to_git_type(): void
    {
        $this->mock(PackageSynchronizer::class, function ($mock) {
            $mock->shouldReceive('sync')->once();
        });

        $this->artisan('package:add', ['url' => 'https://gitea.example.com/vendor/pkg'])
            ->assertExitCode(0);

        $this->assertDatabaseHas('packages', [
            'type' => 'git',
        ]);
    }

    public function test_add_command_attaches_to_default_repo(): void
    {
        $repo = Repository::factory()->create(['slug' => 'default']);

        $this->mock(PackageSynchronizer::class, function ($mock) {
            $mock->shouldReceive('sync')->once();
        });

        $this->artisan('package:add', ['url' => 'https://github.com/vendor/repo-test'])
            ->assertExitCode(0);

        $package = Package::where('name', 'vendor/repo-test')->first();
        $this->assertTrue($repo->packages()->where('packages.id', $package->id)->exists());
    }

    public function test_add_command_attaches_to_specified_repo(): void
    {
        $repo = Repository::factory()->create(['slug' => 'custom']);

        $this->mock(PackageSynchronizer::class, function ($mock) {
            $mock->shouldReceive('sync')->once();
        });

        $this->artisan('package:add', [
            'url' => 'https://github.com/vendor/custom-pkg',
            '--repo' => ['custom'],
        ])->assertExitCode(0);

        $package = Package::where('name', 'vendor/custom-pkg')->first();
        $this->assertTrue($repo->packages()->where('packages.id', $package->id)->exists());
    }

    public function test_sync_preserves_advisories_when_packagist_api_fails(): void
    {
        $package = Package::factory()->create(['name' => 'vendor/test']);
        SecurityAdvisory::factory()->create(['package_id' => $package->id]);

        $this->mock(GitHubPackageFetcher::class, function ($mock) {
            $mock->shouldReceive('fetchVersions')->once()->andReturn([]);
        });

        Http::fake([
            'packagist.org/api/security-advisories/*' => Http::response(null, 500),
        ]);

        app(PackageSynchronizer::class)->sync($package);

        $this->assertDatabaseCount('security_advisories', 1);
    }

    public function test_sync_removes_stale_advisories_when_api_reports_none(): void
    {
        $package = Package::factory()->create(['name' => 'vendor/test']);
        SecurityAdvisory::factory()->create(['package_id' => $package->id]);

        $this->mock(GitHubPackageFetcher::class, function ($mock) {
            $mock->shouldReceive('fetchVersions')->once()->andReturn([]);
        });

        Http::fake([
            'packagist.org/api/security-advisories/*' => Http::response(['advisories' => []]),
        ]);

        app(PackageSynchronizer::class)->sync($package);

        $this->assertDatabaseCount('security_advisories', 0);
    }
}
