<?php

namespace Tests\Feature;

use App\Jobs\DownloadVersionDist;
use App\Models\Package;
use App\Models\Repository;
use App\Models\User;
use App\Models\Version;
use App\Services\GitHubPackageFetcher;
use App\Services\PackageSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DownloadVersionDistTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_downloads_and_caches_dist_archive(): void
    {
        $package = Package::factory()->github()->create(['name' => 'vendor/pkg']);
        $version = Version::factory()->for($package)->create(['reference' => 'abc123']);

        $cachePath = config('repho.dist_cache_path').'/vendor/pkg/abc123.zip';

        $this->mock(GitHubPackageFetcher::class, function ($mock) {
            $mock->shouldReceive('getDistArchive')
                ->once()
                ->andReturn('fake-zip-content');
        });

        $job = new DownloadVersionDist($version);
        app()->call([$job, 'handle']);

        $this->assertFileExists($cachePath);
        $this->assertSame('fake-zip-content', file_get_contents($cachePath));

        // Cleanup
        File::delete($cachePath);
    }

    public function test_job_skips_download_when_cache_exists(): void
    {
        $package = Package::factory()->github()->create(['name' => 'vendor/pkg']);
        $version = Version::factory()->for($package)->create(['reference' => 'existing123']);

        $cachePath = config('repho.dist_cache_path').'/vendor/pkg/existing123.zip';
        $cacheDir = dirname($cachePath);

        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        file_put_contents($cachePath, 'already-cached');

        $this->mock(GitHubPackageFetcher::class, function ($mock) {
            $mock->shouldNotReceive('getDistArchive');
        });

        $job = new DownloadVersionDist($version);
        app()->call([$job, 'handle']);

        $this->assertSame('already-cached', file_get_contents($cachePath));

        // Cleanup
        File::delete($cachePath);
    }

    public function test_job_handles_null_archive_gracefully(): void
    {
        $package = Package::factory()->github()->create(['name' => 'vendor/pkg']);
        $version = Version::factory()->for($package)->create(['reference' => 'nullref']);

        $this->mock(GitHubPackageFetcher::class, function ($mock) {
            $mock->shouldReceive('getDistArchive')
                ->once()
                ->andReturn(null);
        });

        $job = new DownloadVersionDist($version);
        app()->call([$job, 'handle']);

        $cachePath = config('repho.dist_cache_path').'/vendor/pkg/nullref.zip';
        $this->assertFileDoesNotExist($cachePath);
    }

    public function test_sync_dispatches_jobs_when_download_dists_is_enabled(): void
    {
        $package = Package::factory()->github()->withDistDownloads()->create();

        $this->mock(GitHubPackageFetcher::class, function ($mock) {
            $mock->shouldReceive('fetchVersions')
                ->once()
                ->andReturn([
                    [
                        'version' => 'v1.0.0',
                        'version_normalized' => '1.0.0.0',
                        'reference' => 'ref1',
                        'manifest' => ['name' => 'vendor/pkg'],
                        'released_at' => '2026-01-01 00:00:00',
                    ],
                    [
                        'version' => 'v2.0.0',
                        'version_normalized' => '2.0.0.0',
                        'reference' => 'ref2',
                        'manifest' => ['name' => 'vendor/pkg'],
                        'released_at' => '2026-02-01 00:00:00',
                    ],
                ]);
            $mock->shouldReceive('getDistArchive')->twice()->andReturn('fake-zip-content');
        });

        app(PackageSynchronizer::class)->sync($package);

        $this->assertSame(2, $package->versions()->count());
    }

    public function test_sync_does_not_dispatch_jobs_when_download_dists_is_disabled(): void
    {
        Bus::fake([DownloadVersionDist::class]);

        $package = Package::factory()->github()->create(['download_dists' => false]);

        $this->mock(GitHubPackageFetcher::class, function ($mock) {
            $mock->shouldReceive('fetchVersions')
                ->once()
                ->andReturn([
                    [
                        'version' => 'v1.0.0',
                        'version_normalized' => '1.0.0.0',
                        'reference' => 'ref1',
                        'manifest' => ['name' => 'vendor/pkg'],
                        'released_at' => '2026-01-01 00:00:00',
                    ],
                ]);
        });

        app(PackageSynchronizer::class)->sync($package);

        Bus::assertNotDispatched(DownloadVersionDist::class);
    }

    public function test_sync_only_dispatches_for_new_or_changed_versions(): void
    {
        $package = Package::factory()->github()->withDistDownloads()->create();

        // Pre-existing version with same reference — should NOT be re-downloaded
        Version::factory()->for($package)->create([
            'version' => 'v1.0.0',
            'version_normalized' => '1.0.0.0',
            'reference' => 'existing-ref',
        ]);

        $this->mock(GitHubPackageFetcher::class, function ($mock) {
            $mock->shouldReceive('fetchVersions')
                ->once()
                ->andReturn([
                    [
                        'version' => 'v1.0.0',
                        'version_normalized' => '1.0.0.0',
                        'reference' => 'existing-ref',
                        'manifest' => ['name' => 'vendor/pkg'],
                        'released_at' => '2026-01-01 00:00:00',
                    ],
                    [
                        'version' => 'v2.0.0',
                        'version_normalized' => '2.0.0.0',
                        'reference' => 'new-ref',
                        'manifest' => ['name' => 'vendor/pkg'],
                        'released_at' => '2026-02-01 00:00:00',
                    ],
                ]);
            $mock->shouldReceive('getDistArchive')->once()->andReturn('fake-zip-content');
        });

        app(PackageSynchronizer::class)->sync($package);

        $this->assertSame(2, $package->versions()->count());
    }

    public function test_sync_cleans_up_dists_for_removed_versions(): void
    {
        $package = Package::factory()->github()->withDistDownloads()->create(['name' => 'vendor/cleanup']);

        Version::factory()->for($package)->create([
            'version' => 'v1.0.0',
            'version_normalized' => '1.0.0.0',
            'reference' => 'old-ref',
        ]);

        // Create cached zip for the version that will be removed
        $cachePath = config('repho.dist_cache_path').'/vendor/cleanup/old-ref.zip';
        $cacheDir = dirname($cachePath);
        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        file_put_contents($cachePath, 'old-zip');

        $this->mock(GitHubPackageFetcher::class, function ($mock) {
            $mock->shouldReceive('fetchVersions')
                ->once()
                ->andReturn([
                    [
                        'version' => 'v2.0.0',
                        'version_normalized' => '2.0.0.0',
                        'reference' => 'new-ref',
                        'manifest' => ['name' => 'vendor/cleanup'],
                        'released_at' => '2026-02-01 00:00:00',
                    ],
                ]);
            $mock->shouldReceive('getDistArchive')->once()->andReturn('fake-zip-content');
        });

        app(PackageSynchronizer::class)->sync($package);

        $this->assertFileDoesNotExist($cachePath);

        // Cleanup
        $newCachePath = config('repho.dist_cache_path').'/vendor/cleanup';
        if (is_dir($newCachePath)) {
            File::deleteDirectory($newCachePath);
        }
    }

    public function test_package_can_be_created_with_download_dists_enabled(): void
    {
        $user = User::factory()->create();
        $repo = Repository::factory()->create();

        $this->mock(PackageSynchronizer::class, function ($mock) {
            $mock->shouldReceive('sync')->once();
        });

        $response = $this->actingAs($user)->post('/packages', [
            'repository_url' => 'https://github.com/vendor/with-dists',
            'type' => 'github',
            'download_dists' => '1',
            'repository_ids' => [$repo->id],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('packages', [
            'repository_url' => 'https://github.com/vendor/with-dists',
            'download_dists' => true,
        ]);
    }

    public function test_download_version_returns_zip_when_dist_exists(): void
    {
        $user = User::factory()->create();
        $package = Package::factory()->github()->create(['name' => 'vendor/dlpkg']);
        $version = Version::factory()->for($package)->create(['reference' => 'dlref123']);

        $cachePath = config('repho.dist_cache_path').'/vendor/dlpkg/dlref123.zip';
        $cacheDir = dirname($cachePath);
        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        file_put_contents($cachePath, 'zip-content');

        $response = $this->actingAs($user)
            ->get(route('packages.versions.download', [$package, $version]));

        $response->assertOk();
        $response->assertDownload('dlpkg-'.$version->version.'.zip');

        File::delete($cachePath);
    }

    public function test_download_version_returns_404_when_dist_missing(): void
    {
        $user = User::factory()->create();
        $package = Package::factory()->github()->create(['name' => 'vendor/nopkg']);
        $version = Version::factory()->for($package)->create(['reference' => 'noref']);

        $response = $this->actingAs($user)
            ->get(route('packages.versions.download', [$package, $version]));

        $response->assertNotFound();
    }

    public function test_download_version_returns_404_for_mismatched_package(): void
    {
        $user = User::factory()->create();
        $package1 = Package::factory()->github()->create(['name' => 'vendor/pkg1']);
        $package2 = Package::factory()->github()->create(['name' => 'vendor/pkg2']);
        $version = Version::factory()->for($package2)->create();

        $response = $this->actingAs($user)
            ->get(route('packages.versions.download', [$package1, $version]));

        $response->assertNotFound();
    }

    public function test_show_page_includes_dist_url_for_cached_versions(): void
    {
        $user = User::factory()->create();
        $package = Package::factory()->github()->create(['name' => 'vendor/showpkg']);
        $version = Version::factory()->for($package)->create(['reference' => 'showref']);

        $cachePath = config('repho.dist_cache_path').'/vendor/showpkg/showref.zip';
        $cacheDir = dirname($cachePath);
        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        file_put_contents($cachePath, 'zip-content');

        $response = $this->actingAs($user)->get(route('packages.show', $package));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('packages/show')
            ->where('versions.0.dist_url', route('packages.versions.download', [$package, $version]))
        );

        File::delete($cachePath);
    }

    public function test_show_page_has_null_dist_url_when_no_cache(): void
    {
        $user = User::factory()->create();
        $package = Package::factory()->github()->create(['name' => 'vendor/nocache']);
        Version::factory()->for($package)->create(['reference' => 'noref']);

        $response = $this->actingAs($user)->get(route('packages.show', $package));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('packages/show')
            ->where('versions.0.dist_url', null)
        );
    }

    public function test_package_defaults_download_dists_to_false(): void
    {
        $user = User::factory()->create();
        $repo = Repository::factory()->create();

        $this->mock(PackageSynchronizer::class, function ($mock) {
            $mock->shouldReceive('sync')->once();
        });

        $response = $this->actingAs($user)->post('/packages', [
            'repository_url' => 'https://github.com/vendor/no-dists',
            'type' => 'github',
            'repository_ids' => [$repo->id],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('packages', [
            'repository_url' => 'https://github.com/vendor/no-dists',
            'download_dists' => false,
        ]);
    }
}
