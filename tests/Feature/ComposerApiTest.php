<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Repository;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComposerApiTest extends TestCase
{
    use RefreshDatabase;

    private Repository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = Repository::factory()->public()->create(['slug' => 'default', 'name' => 'Default']);
    }

    public function test_packages_json_returns_metadata_url_and_empty_packages(): void
    {
        $response = $this->getJson("/repo/{$this->repository->slug}/packages.json");

        $response->assertOk()
            ->assertJsonStructure(['metadata-url', 'available-packages'])
            ->assertJson([
                'metadata-url' => url("/repo/{$this->repository->slug}/p2/%package%.json"),
                'available-packages' => [],
            ]);
    }

    public function test_packages_json_lists_synced_packages(): void
    {
        $pkg1 = Package::factory()->synced()->create(['name' => 'vendor/alpha']);
        $pkg2 = Package::factory()->synced()->create(['name' => 'vendor/beta']);
        $unsynced = Package::factory()->create(['name' => 'vendor/unsynced']);

        $this->repository->packages()->attach([$pkg1->id, $pkg2->id, $unsynced->id]);

        $response = $this->getJson("/repo/{$this->repository->slug}/packages.json");

        $response->assertOk()
            ->assertJsonFragment(['available-packages' => ['vendor/alpha', 'vendor/beta']]);
    }

    public function test_package_metadata_returns_404_for_unknown_package(): void
    {
        $response = $this->getJson("/repo/{$this->repository->slug}/p2/vendor/unknown.json");

        $response->assertNotFound();
    }

    public function test_package_not_in_repository_returns_404(): void
    {
        Package::factory()->synced()->create(['name' => 'vendor/orphan']);

        $response = $this->getJson("/repo/{$this->repository->slug}/p2/vendor/orphan.json");

        $response->assertNotFound();
    }

    public function test_package_metadata_returns_v2_format(): void
    {
        $package = Package::factory()->synced()->create(['name' => 'vendor/test-lib']);
        $this->repository->packages()->attach($package->id);

        Version::factory()->create([
            'package_id' => $package->id,
            'version' => '1.0.0',
            'version_normalized' => '1.0.0.0',
            'reference' => 'abc123def456',
            'manifest' => [
                'name' => 'vendor/test-lib',
                'description' => 'A test library',
                'require' => ['php' => '>=8.1'],
            ],
        ]);

        $response = $this->getJson("/repo/{$this->repository->slug}/p2/vendor/test-lib.json");

        $response->assertOk()
            ->assertJsonStructure([
                'minified',
                'packages' => ['vendor/test-lib'],
            ])
            ->assertJsonFragment(['minified' => 'composer/2.0']);
    }

    public function test_package_metadata_dev_suffix_returns_dev_versions(): void
    {
        $package = Package::factory()->synced()->create(['name' => 'vendor/test-lib']);
        $this->repository->packages()->attach($package->id);

        Version::factory()->create([
            'package_id' => $package->id,
            'version' => '1.0.0',
            'version_normalized' => '1.0.0.0',
        ]);
        Version::factory()->dev()->create([
            'package_id' => $package->id,
        ]);

        $response = $this->getJson("/repo/{$this->repository->slug}/p2/vendor/test-lib~dev.json");

        $response->assertOk();

        $data = $response->json('packages.vendor/test-lib');
        $this->assertNotEmpty($data);
    }

    public function test_dist_returns_404_for_unknown_package(): void
    {
        $response = $this->get("/repo/{$this->repository->slug}/dists/vendor/unknown/1.0.0/abc123.zip");

        $response->assertNotFound();
    }

    public function test_dist_returns_404_for_reference_without_published_version(): void
    {
        $package = Package::factory()->synced()->create(['name' => 'vendor/test-lib']);
        $this->repository->packages()->attach($package->id);

        Version::factory()->create([
            'package_id' => $package->id,
            'version' => '1.0.0',
            'version_normalized' => '1.0.0.0',
            'reference' => 'abc123def456',
        ]);

        $response = $this->get("/repo/{$this->repository->slug}/dists/vendor/test-lib/1.0.0/0123456789abcdef.zip");

        $response->assertNotFound();
        $this->assertDatabaseCount('download_statistics', 0);
    }

    public function test_public_repo_requires_no_auth(): void
    {
        $response = $this->getJson("/repo/{$this->repository->slug}/packages.json");

        $response->assertOk();
    }

    public function test_basic_auth_repo_returns_401_without_credentials(): void
    {
        $repo = Repository::factory()->basicAuth('user', 'pass')->create();

        $response = $this->getJson("/repo/{$repo->slug}/packages.json");

        $response->assertUnauthorized();
    }

    public function test_basic_auth_repo_succeeds_with_correct_credentials(): void
    {
        $repo = Repository::factory()->basicAuth('user', 'pass')->create();

        $response = $this->withHttpBasicAuth('user', 'pass')
            ->getJson("/repo/{$repo->slug}/packages.json");

        $response->assertOk();
    }

    public function test_basic_auth_repo_fails_with_wrong_credentials(): void
    {
        $repo = Repository::factory()->basicAuth('user', 'pass')->create();

        $response = $this->withHttpBasicAuth('user', 'wrong')
            ->getJson("/repo/{$repo->slug}/packages.json");

        $response->assertUnauthorized();
    }

    public function test_token_auth_repo_returns_401_without_token(): void
    {
        $repo = Repository::factory()->tokenAuth('secret-token')->create();

        $response = $this->getJson("/repo/{$repo->slug}/packages.json");

        $response->assertUnauthorized();
    }

    public function test_token_auth_repo_succeeds_with_bearer_token(): void
    {
        $repo = Repository::factory()->tokenAuth('secret-token')->create();

        $response = $this->withToken('secret-token')
            ->getJson("/repo/{$repo->slug}/packages.json");

        $response->assertOk();
    }

    public function test_token_auth_repo_succeeds_with_query_token(): void
    {
        $repo = Repository::factory()->tokenAuth('secret-token')->create();

        $response = $this->getJson("/repo/{$repo->slug}/packages.json?token=secret-token");

        $response->assertOk();
    }

    private function withHttpBasicAuth(string $username, string $password): static
    {
        return $this->withHeaders([
            'Authorization' => 'Basic '.base64_encode("{$username}:{$password}"),
        ]);
    }
}
