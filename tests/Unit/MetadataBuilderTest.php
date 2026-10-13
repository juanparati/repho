<?php

namespace Tests\Unit;

use App\Models\Package;
use App\Models\Repository;
use App\Models\Version;
use App\Services\MetadataBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetadataBuilderTest extends TestCase
{
    use RefreshDatabase;

    private MetadataBuilder $builder;

    private Repository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new MetadataBuilder;
        $this->repository = Repository::factory()->create(['slug' => 'test-repo']);
    }

    public function test_build_packages_json_returns_correct_structure(): void
    {
        $result = $this->builder->buildPackagesJson($this->repository);

        $this->assertArrayHasKey('metadata-url', $result);
        $this->assertArrayHasKey('available-packages', $result);
        $this->assertEquals(url('/repo/test-repo/p2/%package%.json'), $result['metadata-url']);
        $this->assertIsArray($result['available-packages']);
    }

    public function test_build_packages_json_only_includes_synced_packages(): void
    {
        $synced = Package::factory()->synced()->create(['name' => 'vendor/synced']);
        $unsynced = Package::factory()->create(['name' => 'vendor/unsynced']);

        $this->repository->packages()->attach([$synced->id, $unsynced->id]);

        $result = $this->builder->buildPackagesJson($this->repository);

        $this->assertContains('vendor/synced', $result['available-packages']);
        $this->assertNotContains('vendor/unsynced', $result['available-packages']);
    }

    public function test_build_packages_json_scopes_to_repository(): void
    {
        $inRepo = Package::factory()->synced()->create(['name' => 'vendor/in-repo']);
        $notInRepo = Package::factory()->synced()->create(['name' => 'vendor/not-in-repo']);

        $this->repository->packages()->attach($inRepo->id);

        $result = $this->builder->buildPackagesJson($this->repository);

        $this->assertContains('vendor/in-repo', $result['available-packages']);
        $this->assertNotContains('vendor/not-in-repo', $result['available-packages']);
    }

    public function test_build_package_metadata_returns_minified_format(): void
    {
        $package = Package::factory()->synced()->create([
            'name' => 'vendor/test',
            'repository_url' => 'https://github.com/vendor/test',
        ]);

        Version::factory()->create([
            'package_id' => $package->id,
            'version' => '1.0.0',
            'version_normalized' => '1.0.0.0',
            'reference' => 'abc123',
            'manifest' => [
                'name' => 'vendor/test',
                'require' => ['php' => '>=8.1'],
            ],
        ]);

        $result = $this->builder->buildPackageMetadata($this->repository, $package);

        $this->assertEquals('composer/2.0', $result['minified']);
        $this->assertArrayHasKey('vendor/test', $result['packages']);

        $versions = $result['packages']['vendor/test'];
        $this->assertNotEmpty($versions);
    }

    public function test_build_package_metadata_includes_source_and_dist(): void
    {
        $package = Package::factory()->synced()->create([
            'name' => 'vendor/test',
            'repository_url' => 'https://github.com/vendor/test',
        ]);

        Version::factory()->create([
            'package_id' => $package->id,
            'version' => '2.0.0',
            'version_normalized' => '2.0.0.0',
            'reference' => 'def456',
            'manifest' => [
                'name' => 'vendor/test',
                'require' => ['php' => '>=8.2'],
            ],
        ]);

        $result = $this->builder->buildPackageMetadata($this->repository, $package);
        $versionData = $result['packages']['vendor/test'][0];

        $this->assertArrayHasKey('source', $versionData);
        $this->assertEquals('git', $versionData['source']['type']);
        $this->assertEquals('def456', $versionData['source']['reference']);

        $this->assertArrayHasKey('dist', $versionData);
        $this->assertEquals('zip', $versionData['dist']['type']);
        $this->assertStringContainsString('def456', $versionData['dist']['url']);
        $this->assertStringContainsString('test-repo', $versionData['dist']['url']);
    }

    public function test_build_package_metadata_dev_only_flag(): void
    {
        $package = Package::factory()->synced()->create(['name' => 'vendor/test']);

        Version::factory()->create([
            'package_id' => $package->id,
            'version' => '1.0.0',
            'version_normalized' => '1.0.0.0',
        ]);

        Version::factory()->dev()->create([
            'package_id' => $package->id,
        ]);

        $stableResult = $this->builder->buildPackageMetadata($this->repository, $package, devOnly: false);
        $devResult = $this->builder->buildPackageMetadata($this->repository, $package, devOnly: true);

        $stableVersions = $stableResult['packages']['vendor/test'];
        $devVersions = $devResult['packages']['vendor/test'];

        $this->assertNotEmpty($stableVersions);
        $this->assertNotEmpty($devVersions);
    }
}
