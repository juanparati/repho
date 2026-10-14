<?php

namespace Tests\Unit;

use App\Models\Credential;
use App\Models\Package;
use App\Services\GitPackageFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class GitPackageFetcherTest extends TestCase
{
    use RefreshDatabase;

    private string $clonePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clonePath = sys_get_temp_dir().'/repho-test-clones-'.uniqid();
        config(['repho.git_clone_path' => $this->clonePath]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->clonePath);
        parent::tearDown();
    }

    public function test_clone_passes_credential_per_invocation_without_embedding_it(): void
    {
        Process::fake();

        $credential = Credential::factory()->git()->create(['token' => 'secret-token']);
        $package = Package::factory()->git()->create([
            'repository_url' => 'https://git.example.com/vendor/pkg.git',
            'credential_id' => $credential->id,
        ]);

        (new GitPackageFetcher)->fetchVersions($package);

        $expectedHeader = 'http.extraHeader=Authorization: Basic '.base64_encode('oauth2:secret-token');

        Process::assertRan(function (PendingProcess $process) use ($expectedHeader) {
            $command = $process->command;

            return is_array($command)
                && in_array('clone', $command, true)
                && in_array('https://git.example.com/vendor/pkg.git', $command, true)
                && in_array($expectedHeader, $command, true)
                && ! str_contains(implode(' ', $command), 'secret-token@');
        });
    }

    public function test_fetch_resets_remote_url_and_passes_current_credential(): void
    {
        Process::fake();

        $credential = Credential::factory()->git()->create(['token' => 'rotated-token']);
        $package = Package::factory()->git()->create([
            'repository_url' => 'https://git.example.com/vendor/pkg.git',
            'credential_id' => $credential->id,
        ]);

        mkdir("{$this->clonePath}/{$package->id}", 0755, true);

        (new GitPackageFetcher)->fetchVersions($package);

        Process::assertRan(function (PendingProcess $process) {
            return $process->command === ['git', 'remote', 'set-url', 'origin', 'https://git.example.com/vendor/pkg.git'];
        });

        $expectedHeader = 'http.extraHeader=Authorization: Basic '.base64_encode('oauth2:rotated-token');

        Process::assertRan(function (PendingProcess $process) use ($expectedHeader) {
            $command = $process->command;

            return is_array($command)
                && in_array('fetch', $command, true)
                && in_array($expectedHeader, $command, true);
        });
    }

    public function test_default_branch_manifest_uses_a_temporary_shallow_clone(): void
    {
        Process::fake([
            '*git*show*HEAD:package.json*' => Process::result(json_encode(['name' => '@acme/ui'])),
            '*' => Process::result(''),
        ]);

        $credential = Credential::factory()->git()->create(['token' => 'secret-token']);
        $package = new Package([
            'ecosystem' => 'npm',
            'type' => 'git',
            'repository_url' => 'https://git.example.com/vendor/pkg.git',
            'credential_id' => $credential->id,
        ]);

        $manifest = (new GitPackageFetcher)->fetchDefaultBranchManifest($package);

        $this->assertSame(['name' => '@acme/ui'], $manifest);

        $expectedHeader = 'http.extraHeader=Authorization: Basic '.base64_encode('oauth2:secret-token');

        Process::assertRan(function (PendingProcess $process) use ($expectedHeader) {
            $command = $process->command;

            return is_array($command)
                && in_array('clone', $command, true)
                && in_array('--depth', $command, true)
                && in_array($expectedHeader, $command, true)
                && str_starts_with(end($command), $this->clonePath.'/tmp-');
        });

        $this->assertSame([], File::directories($this->clonePath));
    }

    public function test_clone_without_credential_omits_auth_header(): void
    {
        Process::fake();

        $package = Package::factory()->git()->create([
            'repository_url' => 'https://git.example.com/vendor/pkg.git',
            'credential_id' => null,
        ]);

        (new GitPackageFetcher)->fetchVersions($package);

        Process::assertRan(function (PendingProcess $process) {
            $command = $process->command;

            return is_array($command)
                && in_array('clone', $command, true)
                && ! str_contains(implode(' ', $command), 'http.extraHeader');
        });
    }
}
