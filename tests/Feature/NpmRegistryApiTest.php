<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Repository;
use App\Models\Version;
use App\Services\GitHubPackageFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Concerns\CreatesGitArchives;
use Tests\TestCase;

class NpmRegistryApiTest extends TestCase
{
    use CreatesGitArchives;
    use RefreshDatabase;

    private Repository $repository;

    private string $distPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = Repository::factory()->public()->create(['slug' => 'js', 'name' => 'JS']);
        $this->distPath = sys_get_temp_dir().'/repho-npm-dists-'.uniqid();
        config(['repho.dist_cache_path' => $this->distPath]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->distPath);
        parent::tearDown();
    }

    public function test_ping(): void
    {
        $this->getJson('/npm/js/-/ping')->assertOk()->assertExactJson([]);
    }

    public function test_packument_returns_404_for_unknown_package(): void
    {
        $this->getJson('/npm/js/unknown')->assertNotFound()->assertJson(['error' => 'Not found']);
        $this->getJson('/npm/js/@acme/unknown')->assertNotFound();
    }

    public function test_packument_returns_404_for_package_not_in_repository(): void
    {
        Package::factory()->npm('@acme/orphan')->synced()->create();

        $this->getJson('/npm/js/@acme/orphan')->assertNotFound();
    }

    public function test_packument_does_not_serve_composer_packages(): void
    {
        $package = Package::factory()->synced()->create(['name' => 'vendor/lib']);
        $this->repository->packages()->attach($package);

        $this->getJson('/npm/js/vendor/lib')->assertNotFound();
    }

    public function test_packument_for_scoped_package_with_literal_and_encoded_slash(): void
    {
        $package = Package::factory()->npm('@acme/ui')->synced()->create();
        $this->repository->packages()->attach($package);
        Version::factory()->for($package)->npm('1.0.0', '@acme/ui')->create();

        $response = $this->getJson('/npm/js/@acme/ui');

        $response->assertOk()
            ->assertJsonPath('name', '@acme/ui')
            ->assertJsonPath('dist-tags.latest', '1.0.0');
        $this->assertSame(url('/npm/js/@acme/ui/-/ui-1.0.0.tgz'), $response->json('versions')['1.0.0']['dist']['tarball']);

        $this->getJson('/npm/js/@acme%2Fui')->assertOk()->assertJsonPath('name', '@acme/ui');
    }

    public function test_packument_for_unscoped_package(): void
    {
        $package = Package::factory()->npm('plain')->synced()->create();
        $this->repository->packages()->attach($package);
        Version::factory()->for($package)->npm('2.1.0', 'plain')->create();

        $response = $this->getJson('/npm/js/plain');

        $response->assertOk()->assertJsonPath('dist-tags.latest', '2.1.0');
        $this->assertSame(url('/npm/js/plain/-/plain-2.1.0.tgz'), $response->json('versions')['2.1.0']['dist']['tarball']);
    }

    public function test_abbreviated_packument_when_requested_via_accept_header(): void
    {
        $package = Package::factory()->npm('plain')->synced()->create();
        $this->repository->packages()->attach($package);
        Version::factory()->for($package)->npm('1.0.0', 'plain')->create();

        $response = $this->get('/npm/js/plain', ['Accept' => 'application/vnd.npm.install-v1+json; q=1.0, application/json; q=0.8, */*']);

        $response->assertOk();
        $this->assertArrayNotHasKey('description', $response->json());
        $this->assertArrayNotHasKey('description', $response->json('versions')['1.0.0']);
    }

    public function test_dist_tags_endpoint(): void
    {
        $package = Package::factory()->npm('@acme/ui')->synced()->create();
        $this->repository->packages()->attach($package);
        Version::factory()->for($package)->npm('1.0.0', '@acme/ui')->create();
        Version::factory()->for($package)->npm('1.1.0', '@acme/ui')->create();

        $this->getJson('/npm/js/-/package/@acme/ui/dist-tags')
            ->assertOk()
            ->assertExactJson(['latest' => '1.1.0']);
    }

    public function test_tarball_is_built_from_git_archive_and_cached(): void
    {
        $package = Package::factory()->npm('@acme/ui')->github()->synced()->create();
        $this->repository->packages()->attach($package);
        $version = Version::factory()->for($package)->npm('1.0.0', '@acme/ui')->create([
            'reference' => 'abc123',
            'shasum' => null,
            'integrity' => null,
        ]);

        $zip = $this->makeZipArchive([
            'package.json' => json_encode(['name' => '@acme/ui', 'version' => '0.0.0']),
            'index.js' => 'module.exports = 42;',
        ], 'acme-ui-abc123/');

        $this->mock(GitHubPackageFetcher::class, function ($mock) use ($zip) {
            $mock->shouldReceive('getDistArchive')->once()->andReturn($zip);
        });

        $response = $this->get('/npm/js/@acme/ui/-/ui-1.0.0.tgz');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/octet-stream');
        $response->assertDownload('ui-1.0.0.tgz');

        $tgz = file_get_contents($this->distPath.'/npm/@acme/ui/abc123.tgz');
        $entries = $this->readTarball($tgz);
        $this->assertSame('module.exports = 42;', $entries['package/index.js']);
        $this->assertSame('1.0.0', json_decode($entries['package/package.json'], true)['version']);

        $version->refresh();
        $this->assertSame(sha1($tgz), $version->shasum);
        $this->assertStringStartsWith('sha512-', $version->integrity);

        $this->assertDatabaseHas('download_statistics', ['package_id' => $package->id, 'version_id' => $version->id, 'downloads' => 1]);

        // Second download is served from cache without touching the fetcher.
        $this->get('/npm/js/@acme/ui/-/ui-1.0.0.tgz')->assertOk();
    }

    public function test_tarball_returns_404_for_unknown_version_or_wrong_file_name(): void
    {
        $package = Package::factory()->npm('@acme/ui')->synced()->create();
        $this->repository->packages()->attach($package);
        Version::factory()->for($package)->npm('1.0.0', '@acme/ui')->create();

        $this->get('/npm/js/@acme/ui/-/ui-9.9.9.tgz')->assertNotFound();
        $this->get('/npm/js/@acme/ui/-/other-1.0.0.tgz')->assertNotFound();
        $this->get('/npm/js/@acme/nope/-/nope-1.0.0.tgz')->assertNotFound();
    }

    public function test_tarball_returns_404_when_archive_cannot_be_fetched(): void
    {
        $package = Package::factory()->npm('plain')->github()->synced()->create();
        $this->repository->packages()->attach($package);
        Version::factory()->for($package)->npm('1.0.0', 'plain')->create(['reference' => 'missing']);

        $this->mock(GitHubPackageFetcher::class, function ($mock) {
            $mock->shouldReceive('getDistArchive')->once()->andReturn(null);
        });

        $this->get('/npm/js/plain/-/plain-1.0.0.tgz')->assertNotFound();
    }

    public function test_basic_auth_protects_registry(): void
    {
        $repository = Repository::factory()->create([
            'slug' => 'private',
            'auth_type' => 'basic',
            'auth_username' => 'user',
            'auth_password' => 'secret',
        ]);
        $package = Package::factory()->npm('plain')->synced()->create();
        $repository->packages()->attach($package);

        $this->getJson('/npm/private/plain')->assertUnauthorized();

        $this->withBasicAuth('user', 'secret')->getJson('/npm/private/plain')->assertOk();
    }

    public function test_token_auth_protects_registry(): void
    {
        $repository = Repository::factory()->create([
            'slug' => 'private',
            'auth_type' => 'token',
            'auth_token' => 'npm-token',
        ]);
        $package = Package::factory()->npm('plain')->synced()->create();
        $repository->packages()->attach($package);

        $this->getJson('/npm/private/plain')->assertUnauthorized();

        $this->withToken('npm-token')->getJson('/npm/private/plain')->assertOk();
    }

    public function test_composer_endpoints_do_not_serve_npm_packages(): void
    {
        $package = Package::factory()->npm('@acme/ui')->synced()->create();
        $this->repository->packages()->attach($package);

        $this->getJson('/repo/js/packages.json')->assertOk()->assertJson(['available-packages' => []]);
        $this->getJson('/repo/js/p2/@acme/ui.json')->assertNotFound();
    }
}
