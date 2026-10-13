<?php

namespace Tests\Unit;

use App\Models\Package;
use App\Models\Repository;
use App\Models\Version;
use App\Services\NpmMetadataBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NpmMetadataBuilderTest extends TestCase
{
    use RefreshDatabase;

    private NpmMetadataBuilder $builder;

    private Repository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new NpmMetadataBuilder;
        $this->repository = Repository::factory()->create(['slug' => 'js']);
    }

    public function test_packument_lists_versions_with_dist_and_latest_tag(): void
    {
        $package = Package::factory()->npm('@acme/ui')->synced()->create();
        Version::factory()->for($package)->npm('1.0.0', '@acme/ui')->create(['reference' => 'aaa', 'shasum' => 'abc', 'integrity' => 'sha512-xyz']);
        Version::factory()->for($package)->npm('2.0.0-beta.1', '@acme/ui')->create(['reference' => 'bbb']);
        Version::factory()->for($package)->npm('1.10.0', '@acme/ui')->create(['reference' => 'ccc']);

        $packument = $this->builder->buildPackument($this->repository, $package);

        $this->assertSame('@acme/ui', $packument['name']);
        $this->assertSame(['latest' => '1.10.0'], $packument['dist-tags']);
        $this->assertSame(['1.0.0', '1.10.0', '2.0.0-beta.1'], array_keys($packument['versions']));

        $entry = $packument['versions']['1.0.0'];
        $this->assertSame('@acme/ui@1.0.0', $entry['_id']);
        $this->assertSame('aaa', $entry['gitHead']);
        $this->assertSame(url('/npm/js/@acme/ui/-/ui-1.0.0.tgz'), $entry['dist']['tarball']);
        $this->assertSame('abc', $entry['dist']['shasum']);
        $this->assertSame('sha512-xyz', $entry['dist']['integrity']);
        $this->assertSame(['lodash' => '^4.17.21'], $entry['dependencies']);
        $this->assertSame('git+'.$package->repository_url, $packument['repository']['url']);
    }

    public function test_packument_omits_missing_hashes(): void
    {
        $package = Package::factory()->npm('plain')->synced()->create();
        Version::factory()->for($package)->npm('1.0.0', 'plain')->create(['shasum' => null, 'integrity' => null]);

        $entry = $this->builder->buildPackument($this->repository, $package)['versions']['1.0.0'];

        $this->assertArrayNotHasKey('shasum', $entry['dist']);
        $this->assertArrayNotHasKey('integrity', $entry['dist']);
    }

    public function test_packument_without_versions_has_empty_objects(): void
    {
        $package = Package::factory()->npm('plain')->create();

        $packument = $this->builder->buildPackument($this->repository, $package);

        $this->assertSame('{}', json_encode($packument['dist-tags']));
        $this->assertSame('{}', json_encode($packument['versions']));
    }

    public function test_abbreviated_packument_only_contains_install_fields(): void
    {
        $package = Package::factory()->npm('plain')->synced()->create();
        Version::factory()->for($package)->npm('1.0.0', 'plain')->create([
            'manifest' => [
                'name' => 'plain',
                'description' => 'Not needed for install',
                'readme' => 'Long text',
                'dependencies' => ['a' => '1'],
                'scripts' => ['postinstall' => 'node setup.js'],
                'bin' => ['plain' => 'cli.js'],
            ],
        ]);

        $packument = $this->builder->buildPackument($this->repository, $package, abbreviated: true);

        $this->assertArrayNotHasKey('description', $packument);
        $this->assertArrayNotHasKey('time', $packument);

        $entry = $packument['versions']['1.0.0'];
        $this->assertArrayNotHasKey('description', $entry);
        $this->assertArrayNotHasKey('readme', $entry);
        $this->assertArrayNotHasKey('scripts', $entry);
        $this->assertTrue($entry['hasInstallScript']);
        $this->assertSame(['a' => '1'], $entry['dependencies']);
        $this->assertSame(['plain' => 'cli.js'], $entry['bin']);
        $this->assertArrayHasKey('tarball', $entry['dist']);
    }

    public function test_dist_tags(): void
    {
        $package = Package::factory()->npm('plain')->create();
        Version::factory()->for($package)->npm('0.1.0', 'plain')->create();
        Version::factory()->for($package)->npm('0.2.0', 'plain')->create();

        $this->assertSame(['latest' => '0.2.0'], $this->builder->buildDistTags($package));
    }
}
