<?php

namespace App\Services;

use App\Enums\Ecosystem;
use App\Models\Package;
use App\Models\Repository;
use App\Models\Version;
use Composer\MetadataMinifier\MetadataMinifier;

class MetadataBuilder
{
    /**
     * @return array{metadata-url: string, available-packages: array<int, string>}
     */
    public function buildPackagesJson(Repository $repository): array
    {
        return [
            'metadata-url' => url("/repo/{$repository->slug}/p2/%package%.json"),
            'available-packages' => $repository->packages()
                ->where('ecosystem', Ecosystem::Composer)
                ->whereNotNull('last_synced_at')
                ->pluck('name')
                ->all(),
        ];
    }

    /**
     * @return array{packages: array<string, array<int, array<string, mixed>>>, minified: string}
     */
    public function buildPackageMetadata(Repository $repository, Package $package, bool $devOnly = false): array
    {
        $query = $package->versions();

        if ($devOnly) {
            $query->dev();
        } else {
            $query->stable();
        }

        $versions = $query->orderBy('released_at', 'desc')->get();

        $versionData = $versions
            ->map(fn (Version $v) => $this->buildVersionEntry($repository, $package, $v))
            ->all();

        return [
            'minified' => 'composer/2.0',
            'packages' => [
                $package->name => MetadataMinifier::minify($versionData),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildVersionEntry(Repository $repository, Package $package, Version $version): array
    {
        $composerJson = $version->manifest;

        $entry = array_merge($composerJson, [
            'name' => $package->name,
            'version' => $version->version,
            'version_normalized' => $version->version_normalized,
            'source' => [
                'type' => 'git',
                'url' => $package->repository_url,
                'reference' => $version->reference,
            ],
            'dist' => [
                'type' => 'zip',
                'url' => route('composer.dist', [
                    'repository' => $repository->slug,
                    'vendor' => $package->vendor(),
                    'package' => $package->shortName(),
                    'version' => $version->version,
                    'ref' => $version->reference,
                ]),
                'reference' => $version->reference,
                'shasum' => '',
            ],
            'uid' => hash('crc32b', "{$package->name}:{$version->version}"),
        ]);

        return $entry;
    }
}
