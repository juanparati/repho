<?php

namespace Tests\Unit;

use App\Services\NpmTarballBuilder;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Concerns\CreatesGitArchives;

class NpmTarballBuilderTest extends TestCase
{
    use CreatesGitArchives;

    private NpmTarballBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new NpmTarballBuilder;
    }

    public function test_builds_tarball_under_package_folder_from_github_style_archive(): void
    {
        $zip = $this->makeZipArchive([
            'package.json' => json_encode(['name' => '@acme/ui', 'version' => '0.0.0']),
            'index.js' => 'module.exports = 1;',
            'lib/util.js' => 'exports.x = 1;',
        ], 'acme-ui-abc123/');

        $result = $this->builder->build($zip, '@acme/ui', '1.2.3');
        $entries = $this->readTarball($result['contents']);

        $this->assertSame(['package/index.js', 'package/lib/util.js', 'package/package.json'], array_keys($entries));
        $this->assertSame('module.exports = 1;', $entries['package/index.js']);
    }

    public function test_builds_tarball_from_git_archive_without_top_level_folder(): void
    {
        $zip = $this->makeZipArchive([
            'package.json' => json_encode(['name' => 'plain', 'version' => '0.0.0']),
            'index.js' => '',
        ]);

        $entries = $this->readTarball($this->builder->build($zip, 'plain', '1.0.0')['contents']);

        $this->assertArrayHasKey('package/index.js', $entries);
    }

    public function test_rewrites_version_in_package_json(): void
    {
        $zip = $this->makeZipArchive([
            'package.json' => json_encode(['name' => 'plain', 'version' => '0.0.0-development', 'main' => 'index.js']),
            'index.js' => '',
        ]);

        $result = $this->builder->build($zip, 'plain', '3.4.5');
        $entries = $this->readTarball($result['contents']);
        $manifest = json_decode($entries['package/package.json'], true);

        $this->assertSame('3.4.5', $manifest['version']);
        $this->assertSame('3.4.5', $result['manifest']['version']);
        $this->assertSame('index.js', $manifest['main']);
    }

    public function test_accepts_legacy_uppercase_manifest_name_and_normalizes_it(): void
    {
        $zip = $this->makeZipArchive([
            'package.json' => json_encode(['name' => 'MD5', 'version' => '1.2.2']),
            'md5.js' => '',
        ]);

        $result = $this->builder->build($zip, 'md5', '1.2.2');
        $entries = $this->readTarball($result['contents']);
        $manifest = json_decode($entries['package/package.json'], true);

        $this->assertSame('md5', $manifest['name']);
        $this->assertSame('md5', $result['manifest']['name']);
    }

    public function test_rejects_manifest_with_different_name(): void
    {
        $zip = $this->makeZipArchive([
            'package.json' => json_encode(['name' => '@acme/other']),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('package.json name "@acme/other" does not match the package name "@acme/ui"');

        $this->builder->build($zip, '@acme/ui', '1.0.0');
    }

    public function test_builds_tarball_from_a_subdirectory_of_a_monorepo(): void
    {
        $zip = $this->makeZipArchive([
            'package.json' => json_encode(['name' => '@acme/monorepo', 'private' => true]),
            'packages/ui/package.json' => json_encode(['name' => '@acme/ui', 'version' => '0.0.0']),
            'packages/ui/index.js' => 'ui',
            'packages/ui/.npmignore' => 'scratch/',
            'packages/ui/scratch/notes.txt' => 'skip',
            'packages/core/index.js' => 'core',
        ], 'acme-monorepo-abc123/');

        $result = $this->builder->build($zip, '@acme/ui', '1.2.3', 'packages/ui');
        $entries = $this->readTarball($result['contents']);

        $this->assertSame(['package/index.js', 'package/package.json'], array_keys($entries));
        $this->assertSame('ui', $entries['package/index.js']);
        $this->assertSame('@acme/ui', $result['manifest']['name']);
    }

    public function test_rejects_subdirectory_without_package_json(): void
    {
        $zip = $this->makeZipArchive([
            'package.json' => json_encode(['name' => '@acme/monorepo']),
            'packages/core/index.js' => '',
        ], 'acme-monorepo-abc123/');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('package.json not found in "packages/ui" inside the archive.');

        $this->builder->build($zip, '@acme/ui', '1.0.0', 'packages/ui');
    }

    public function test_rejects_archive_without_package_json(): void
    {
        $zip = $this->makeZipArchive(['index.js' => ''], 'repo-abc/');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('package.json not found');

        $this->builder->build($zip, 'plain', '1.0.0');
    }

    public function test_rejects_invalid_zip(): void
    {
        $this->expectException(RuntimeException::class);

        $this->builder->build('not a zip', 'plain', '1.0.0');
    }

    public function test_honours_files_field_and_always_includes_special_files(): void
    {
        $zip = $this->makeZipArchive([
            'package.json' => json_encode(['name' => 'plain', 'files' => ['dist', 'cli.js'], 'main' => 'lib/main.js', 'bin' => ['plain' => 'bin/plain.js']]),
            'dist/index.js' => '',
            'dist/nested/x.js' => '',
            'cli.js' => '',
            'src/index.ts' => '',
            'lib/main.js' => '',
            'bin/plain.js' => '',
            'README.md' => '',
            'LICENSE' => '',
            'CHANGELOG.md' => '',
            'tsconfig.json' => '',
            '.npmignore' => "dist\n",
        ]);

        $entries = $this->readTarball($this->builder->build($zip, 'plain', '1.0.0')['contents']);

        $this->assertSame([
            'package/CHANGELOG.md',
            'package/LICENSE',
            'package/README.md',
            'package/bin/plain.js',
            'package/cli.js',
            'package/dist/index.js',
            'package/dist/nested/x.js',
            'package/lib/main.js',
            'package/package.json',
        ], array_keys($entries));
    }

    public function test_honours_npmignore_over_gitignore(): void
    {
        $zip = $this->makeZipArchive([
            'package.json' => json_encode(['name' => 'plain']),
            '.gitignore' => "dist/\n",
            '.npmignore' => "src/\n*.test.js\n!keep.test.js\n",
            'dist/index.js' => '',
            'src/index.ts' => '',
            'index.js' => '',
            'index.test.js' => '',
            'keep.test.js' => '',
            'docs/guide.test.js' => '',
        ]);

        $entries = $this->readTarball($this->builder->build($zip, 'plain', '1.0.0')['contents']);

        $this->assertSame([
            'package/dist/index.js',
            'package/index.js',
            'package/keep.test.js',
            'package/package.json',
        ], array_keys($entries));
    }

    public function test_falls_back_to_gitignore_when_no_npmignore(): void
    {
        $zip = $this->makeZipArchive([
            'package.json' => json_encode(['name' => 'plain']),
            '.gitignore' => "/build\n*.log\n",
            'build/out.js' => '',
            'nested/build/out.js' => '',
            'debug.log' => '',
            'index.js' => '',
        ]);

        $entries = $this->readTarball($this->builder->build($zip, 'plain', '1.0.0')['contents']);

        $this->assertSame([
            'package/index.js',
            'package/nested/build/out.js',
            'package/package.json',
        ], array_keys($entries));
    }

    public function test_always_excludes_npm_junk_files(): void
    {
        $zip = $this->makeZipArchive([
            'package.json' => json_encode(['name' => 'plain', 'files' => ['**']]),
            'index.js' => '',
            '.git/HEAD' => '',
            'node_modules/dep/index.js' => '',
            '.DS_Store' => '',
            '.npmrc' => '',
            'package-lock.json' => '',
            'yarn.lock' => '',
            'pnpm-lock.yaml' => '',
            'lib/.DS_Store' => '',
            'index.js.orig' => '',
            '.npmignore' => '',
            '.gitignore' => '',
        ]);

        $entries = $this->readTarball($this->builder->build($zip, 'plain', '1.0.0')['contents']);

        $this->assertSame(['package/index.js', 'package/package.json'], array_keys($entries));
    }

    public function test_output_is_deterministic_and_hashes_match_contents(): void
    {
        $zip = $this->makeZipArchive([
            'package.json' => json_encode(['name' => 'plain']),
            'index.js' => 'x',
        ]);

        $first = $this->builder->build($zip, 'plain', '1.0.0');
        $second = $this->builder->build($zip, 'plain', '1.0.0');

        $this->assertSame($first['contents'], $second['contents']);
        $this->assertSame(sha1($first['contents']), $first['shasum']);
        $this->assertSame('sha512-'.base64_encode(hash('sha512', $first['contents'], true)), $first['integrity']);
    }

    public function test_supports_long_paths_via_ustar_prefix(): void
    {
        $deep = str_repeat('directory-name/', 8).'file-with-a-long-name.js';

        $zip = $this->makeZipArchive([
            'package.json' => json_encode(['name' => 'plain']),
            $deep => 'deep',
        ]);

        $entries = $this->readTarball($this->builder->build($zip, 'plain', '1.0.0')['contents']);

        $this->assertSame('deep', $entries['package/'.$deep]);
    }
}
