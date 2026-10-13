<?php

namespace Tests\Feature;

use App\Jobs\SyncPackage;
use App\Models\Package;
use App\Models\Repository;
use App\Models\User;
use App\Services\PackageSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesGitArchives;
use Tests\TestCase;

class NpmPackageSyncTest extends TestCase
{
    use CreatesGitArchives;
    use RefreshDatabase;

    private string $distPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->distPath = sys_get_temp_dir().'/repho-npm-sync-'.uniqid();
        config(['repho.dist_cache_path' => $this->distPath]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->distPath);
        parent::tearDown();
    }

    /**
     * Fake the GitHub API for a repo with the given tags. Each tag has a
     * package.json with the given name and a zipball.
     *
     * @param  array<string, string>  $tags  tag => sha
     */
    private function fakeGitHub(string $manifestName, array $tags, bool $vulnerable = false): void
    {
        $responses = [
            'api.github.com/repos/acme/ui/tags*' => Http::response(array_map(
                fn (string $tag, string $sha) => ['name' => $tag, 'commit' => ['sha' => $sha]],
                array_keys($tags),
                array_values($tags),
            )),
            'api.github.com/repos/acme/ui/contents/package.json*' => Http::response([
                'content' => base64_encode(json_encode(['name' => $manifestName, 'version' => '0.0.0', 'description' => 'A UI kit', 'scripts' => ['build' => 'tsc']])),
            ]),
            'api.github.com/repos/acme/ui/zipball/*' => Http::response($this->makeZipArchive([
                'package.json' => json_encode(['name' => $manifestName, 'version' => '0.0.0']),
                'index.js' => 'ok',
            ], 'acme-ui-sha/')),
            'registry.npmjs.org/-/npm/v1/security/advisories/bulk' => Http::response($vulnerable ? [
                $manifestName => [[
                    'id' => 1001,
                    'url' => 'https://github.com/advisories/GHSA-xxxx-yyyy-zzzz',
                    'title' => 'Prototype pollution',
                    'severity' => 'high',
                    'vulnerable_versions' => '<1.1.0',
                    'cve' => ['CVE-2024-0001'],
                ]],
            ] : []),
        ];

        Http::fake($responses);
    }

    public function test_sync_stores_semver_tags_builds_tarballs_and_skips_branches(): void
    {
        $this->fakeGitHub('@acme/ui', ['v1.0.0' => 'sha1', '1.1.0' => 'sha2', 'not-a-version' => 'sha3']);

        $package = Package::factory()->npm('@acme/ui')->github()->create(['repository_url' => 'https://github.com/acme/ui']);

        app(PackageSynchronizer::class)->sync($package);

        $package->refresh();
        $this->assertNull($package->sync_error);
        $this->assertNotNull($package->last_synced_at);
        $this->assertSame('A UI kit', $package->description);

        $versions = $package->versions()->orderBy('version')->get();
        $this->assertSame(['1.0.0', '1.1.0'], $versions->pluck('version')->all());
        $this->assertSame(['1.0.0', '1.1.0'], $versions->pluck('version_normalized')->all());

        foreach ($versions as $version) {
            $this->assertFileExists("{$this->distPath}/npm/@acme/ui/{$version->reference}.tgz");
            $this->assertStringStartsWith('sha512-', $version->integrity);
            $this->assertSame(sha1((string) file_get_contents("{$this->distPath}/npm/@acme/ui/{$version->reference}.tgz")), $version->shasum);
        }

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/branches/'));
    }

    public function test_sync_fails_when_package_json_name_differs(): void
    {
        $this->fakeGitHub('@acme/other-name', ['v1.0.0' => 'sha1']);

        $package = Package::factory()->npm('@acme/ui')->github()->create(['repository_url' => 'https://github.com/acme/ui']);

        try {
            app(PackageSynchronizer::class)->sync($package);
            $this->fail('Expected the sync to fail.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('declares the name "@acme/other-name"', $e->getMessage());
        }

        $package->refresh();
        $this->assertStringContainsString('registered as "@acme/ui"', $package->sync_error);
        $this->assertSame(0, $package->versions()->count());
    }

    public function test_sync_records_npm_advisories(): void
    {
        $this->fakeGitHub('@acme/ui', ['v1.0.0' => 'sha1'], vulnerable: true);

        $package = Package::factory()->npm('@acme/ui')->github()->create(['repository_url' => 'https://github.com/acme/ui']);

        app(PackageSynchronizer::class)->sync($package);

        $this->assertDatabaseHas('security_advisories', [
            'package_id' => $package->id,
            'advisory_id' => 'GHSA-xxxx-yyyy-zzzz',
            'cve' => 'CVE-2024-0001',
            'affected_versions' => '<1.1.0',
            'severity' => 'high',
        ]);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'advisories/bulk')
            && $request['@acme/ui'] === ['1.0.0']);
    }

    public function test_npm_package_can_be_created_from_the_ui_and_queues_a_sync(): void
    {
        Queue::fake();
        $repository = Repository::factory()->create();

        $response = $this->actingAs(User::factory()->create())->post('/packages', [
            'ecosystem' => 'npm',
            'name' => '@acme/ui',
            'repository_url' => 'https://github.com/acme/ui',
            'type' => 'github',
            'repository_ids' => [$repository->id],
        ]);

        $package = Package::where('name', '@acme/ui')->firstOrFail();
        $response->assertRedirect(route('packages.show', $package));
        $this->assertSame('npm', $package->ecosystem->value);
        $this->assertTrue($package->is_syncing);
        Queue::assertPushed(SyncPackage::class);
    }

    public function test_npm_package_requires_a_valid_unique_name(): void
    {
        $repository = Repository::factory()->create();
        Package::factory()->npm('@acme/taken')->create();
        $user = User::factory()->create();

        $base = ['ecosystem' => 'npm', 'repository_url' => 'https://github.com/acme/ui', 'type' => 'github', 'repository_ids' => [$repository->id]];

        $this->actingAs($user)->post('/packages', $base)->assertSessionHasErrors('name');
        $this->actingAs($user)->post('/packages', $base + ['name' => 'Not Valid'])->assertSessionHasErrors('name');
        $this->actingAs($user)->post('/packages', $base + ['name' => '../etc'])->assertSessionHasErrors('name');
        $this->actingAs($user)->post('/packages', $base + ['name' => '@acme/taken'])->assertSessionHasErrors('name');
    }

    public function test_same_name_can_exist_in_both_ecosystems(): void
    {
        Package::factory()->create(['name' => 'acme/ui']);

        $npm = Package::factory()->npm('acme/ui')->create();

        $this->assertSame(2, Package::where('name', 'acme/ui')->count());
        $this->assertTrue($npm->isNpm());
    }

    public function test_composer_package_creation_ignores_name_field(): void
    {
        Queue::fake();
        $repository = Repository::factory()->create();
        Http::fake(['packagist.org/*' => Http::response(['advisories' => []])]);

        $this->actingAs(User::factory()->create())->post('/packages', [
            'name' => 'ignored',
            'repository_url' => 'https://github.com/acme/php-lib',
            'type' => 'github',
            'repository_ids' => [$repository->id],
        ]);

        $this->assertDatabaseHas('packages', ['name' => 'acme/php-lib', 'ecosystem' => 'composer']);
    }
}
