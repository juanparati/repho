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
     * When $subdirectory is given, the package lives there and the repository
     * root carries a monorepo package.json with a different name.
     *
     * @param  array<string, string>  $tags  tag => sha
     */
    private function fakeGitHub(string $manifestName, array $tags, bool $vulnerable = false, string $subdirectory = ''): void
    {
        $manifestDir = $subdirectory === '' ? '' : $subdirectory.'/';
        $archiveFiles = [
            $manifestDir.'package.json' => json_encode(['name' => $manifestName, 'version' => '0.0.0']),
            $manifestDir.'index.js' => 'ok',
        ];

        if ($subdirectory !== '') {
            $archiveFiles['package.json'] = json_encode(['name' => '@acme/monorepo', 'private' => true]);
            $archiveFiles['packages/other/index.js'] = 'other';
        }

        $responses = [
            'api.github.com/repos/acme/ui' => Http::response(['default_branch' => 'main']),
            'api.github.com/repos/acme/ui/tags*' => Http::response(array_map(
                fn (string $tag, string $sha) => ['name' => $tag, 'commit' => ['sha' => $sha]],
                array_keys($tags),
                array_values($tags),
            )),
            "api.github.com/repos/acme/ui/contents/{$manifestDir}package.json*" => Http::response([
                'content' => base64_encode(json_encode(['name' => $manifestName, 'version' => '0.0.0', 'description' => 'A UI kit', 'scripts' => ['build' => 'tsc']])),
            ]),
            'api.github.com/repos/acme/ui/zipball/*' => Http::response($this->makeZipArchive($archiveFiles, 'acme-ui-sha/')),
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

    public function test_sync_reads_manifest_and_builds_tarball_from_subdirectory(): void
    {
        $this->fakeGitHub('@acme/ui', ['v1.0.0' => 'sha1'], subdirectory: 'packages/ui');

        $package = Package::factory()->npm('@acme/ui')->github()->create([
            'repository_url' => 'https://github.com/acme/ui',
            'subdirectory' => 'packages/ui',
        ]);

        app(PackageSynchronizer::class)->sync($package);

        $package->refresh();
        $this->assertNull($package->sync_error);
        $version = $package->versions()->sole();
        $this->assertSame('1.0.0', $version->version);

        $entries = $this->readTarball((string) file_get_contents("{$this->distPath}/npm/@acme/ui/{$version->reference}.tgz"));
        $this->assertSame(['package/index.js', 'package/package.json'], array_keys($entries));
        $this->assertSame('@acme/ui', json_decode($entries['package/package.json'], true)['name']);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'contents/packages/ui/package.json'));
    }

    public function test_sync_accepts_legacy_uppercase_package_json_name(): void
    {
        $this->fakeGitHub('MD5', ['v1.2.2' => 'sha1']);

        $package = Package::factory()->npm('md5')->github()->create(['repository_url' => 'https://github.com/acme/ui']);

        app(PackageSynchronizer::class)->sync($package);

        $package->refresh();
        $this->assertNull($package->sync_error);
        $this->assertSame(['1.2.2'], $package->versions()->pluck('version')->all());
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

        $this->actingAs($user)->post('/packages', $base + ['name' => 'Not Valid'])->assertSessionHasErrors('name');
        $this->actingAs($user)->post('/packages', $base + ['name' => '../etc'])->assertSessionHasErrors('name');
        $this->actingAs($user)->post('/packages', $base + ['name' => '@acme/taken'])->assertSessionHasErrors('name');
    }

    public function test_npm_package_name_is_read_from_package_json_when_left_blank(): void
    {
        Queue::fake();
        $this->fakeGitHub('@Acme/UI', []);
        $repository = Repository::factory()->create();

        $response = $this->actingAs(User::factory()->create())->post('/packages', [
            'ecosystem' => 'npm',
            'name' => '',
            'repository_url' => 'https://github.com/acme/ui',
            'type' => 'github',
            'repository_ids' => [$repository->id],
        ]);

        $package = Package::where('name', '@acme/ui')->firstOrFail();
        $response->assertRedirect(route('packages.show', $package));
        $this->assertTrue($package->isNpm());
        Queue::assertPushed(SyncPackage::class);
    }

    public function test_blank_npm_name_is_read_from_the_subdirectory_package_json(): void
    {
        Queue::fake();
        $this->fakeGitHub('@acme/ui', [], subdirectory: 'packages/ui');
        $repository = Repository::factory()->create();

        $this->actingAs(User::factory()->create())->post('/packages', [
            'ecosystem' => 'npm',
            'repository_url' => 'https://github.com/acme/ui',
            'subdirectory' => '/packages/ui/',
            'type' => 'github',
            'repository_ids' => [$repository->id],
        ])->assertRedirect();

        $this->assertDatabaseHas('packages', ['name' => '@acme/ui', 'ecosystem' => 'npm', 'subdirectory' => 'packages/ui']);
        Queue::assertPushed(SyncPackage::class);
    }

    public function test_npm_subdirectory_must_be_a_safe_relative_path(): void
    {
        $repository = Repository::factory()->create();
        $user = User::factory()->create();

        $base = ['ecosystem' => 'npm', 'name' => '@acme/ui', 'repository_url' => 'https://github.com/acme/ui', 'type' => 'github', 'repository_ids' => [$repository->id]];

        $this->actingAs($user)->post('/packages', $base + ['subdirectory' => '../etc'])->assertSessionHasErrors('subdirectory');
        $this->actingAs($user)->post('/packages', $base + ['subdirectory' => 'packages/../x'])->assertSessionHasErrors('subdirectory');
        $this->actingAs($user)->post('/packages', $base + ['subdirectory' => 'packages/ui;rm'])->assertSessionHasErrors('subdirectory');
    }

    public function test_typed_npm_package_name_takes_precedence_over_package_json(): void
    {
        Queue::fake();
        $this->fakeGitHub('@acme/other', []);
        $repository = Repository::factory()->create();

        $this->actingAs(User::factory()->create())->post('/packages', [
            'ecosystem' => 'npm',
            'name' => '@acme/ui',
            'repository_url' => 'https://github.com/acme/ui',
            'type' => 'github',
            'repository_ids' => [$repository->id],
        ]);

        $this->assertDatabaseHas('packages', ['name' => '@acme/ui', 'ecosystem' => 'npm']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'contents/package.json'));
    }

    public function test_blank_npm_name_fails_when_package_json_name_is_already_taken(): void
    {
        Queue::fake();
        $this->fakeGitHub('@acme/ui', []);
        Package::factory()->npm('@acme/ui')->create();
        $repository = Repository::factory()->create();

        $this->actingAs(User::factory()->create())->post('/packages', [
            'ecosystem' => 'npm',
            'repository_url' => 'https://github.com/acme/ui',
            'type' => 'github',
            'repository_ids' => [$repository->id],
        ])->assertSessionHasErrors('name');

        $this->assertDatabaseCount('packages', 1);
        Queue::assertNothingPushed();
    }

    public function test_blank_npm_name_fails_when_package_json_cannot_be_read(): void
    {
        Queue::fake();
        Http::fake([
            'api.github.com/repos/acme/ui' => Http::response(['default_branch' => 'main']),
            'api.github.com/repos/acme/ui/contents/*' => Http::response(null, 404),
        ]);
        $repository = Repository::factory()->create();

        $this->actingAs(User::factory()->create())->post('/packages', [
            'ecosystem' => 'npm',
            'repository_url' => 'https://github.com/acme/ui',
            'type' => 'github',
            'repository_ids' => [$repository->id],
        ])->assertSessionHasErrors('name');

        $this->assertDatabaseCount('packages', 0);
        Queue::assertNothingPushed();
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
