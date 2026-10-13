<?php

namespace App\Services;

use App\Models\Package;
use App\Models\Repository;
use App\Models\Version;

/**
 * Build npm registry "packuments" (package documents) for packages hosted
 * in a repository.
 */
class NpmMetadataBuilder
{
    /** The Accept media type npm clients send for install-only metadata. */
    public const string ABBREVIATED_MEDIA_TYPE = 'application/vnd.npm.install-v1+json';

    private const array ABBREVIATED_VERSION_KEYS = [
        'name',
        'version',
        'dependencies',
        'devDependencies',
        'optionalDependencies',
        'peerDependencies',
        'peerDependenciesMeta',
        'bundleDependencies',
        'bundledDependencies',
        'bin',
        'directories',
        'dist',
        'engines',
        'os',
        'cpu',
        'deprecated',
        'funding',
        'workspaces',
        '_hasShrinkwrap',
    ];

    /**
     * @return array<string, mixed>
     */
    public function buildPackument(Repository $repository, Package $package, bool $abbreviated = false): array
    {
        $versions = $package->versions()->stable()->get();

        $entries = [];
        $time = [];

        foreach ($versions as $version) {
            $entries[$version->version] = $this->buildVersionEntry($repository, $package, $version, $abbreviated);

            if ($version->released_at !== null) {
                $time[$version->version] = $version->released_at->toIso8601ZuluString();
            }
        }

        uksort($entries, [NpmSemver::class, 'compare']);

        $latest = NpmSemver::latest(array_keys($entries));
        $modified = $package->last_synced_at ?? $package->updated_at;

        $packument = [
            'name' => $package->name,
            'dist-tags' => $latest === null ? (object) [] : ['latest' => $latest],
            'versions' => $entries === [] ? (object) [] : $entries,
            'modified' => $modified?->toIso8601ZuluString(),
        ];

        if ($abbreviated) {
            return $packument;
        }

        if ($time !== []) {
            $time['modified'] = $packument['modified'];
        }

        return $packument + [
            'description' => $package->description,
            'time' => $time === [] ? (object) [] : $time,
            'repository' => [
                'type' => 'git',
                'url' => 'git+'.$package->repository_url,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function buildDistTags(Package $package): array
    {
        $latest = NpmSemver::latest($package->versions()->stable()->pluck('version')->all());

        return $latest === null ? [] : ['latest' => $latest];
    }

    public function tarballUrl(Repository $repository, Package $package, Version $version): string
    {
        return route('npm.tarball', [
            'repository' => $repository->slug,
            'name' => $package->name,
            'file' => "{$package->shortName()}-{$version->version}",
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildVersionEntry(Repository $repository, Package $package, Version $version, bool $abbreviated): array
    {
        $manifest = $version->manifest;

        $dist = ['tarball' => $this->tarballUrl($repository, $package, $version)];

        if ($version->shasum !== null) {
            $dist['shasum'] = $version->shasum;
        }

        if ($version->integrity !== null) {
            $dist['integrity'] = $version->integrity;
        }

        $entry = array_merge($manifest, [
            'name' => $package->name,
            'version' => $version->version,
            '_id' => "{$package->name}@{$version->version}",
            'gitHead' => $version->reference,
            'dist' => $dist,
        ]);

        if (! $abbreviated) {
            return $entry;
        }

        $scripts = is_array($manifest['scripts'] ?? null) ? $manifest['scripts'] : [];
        $hasInstallScript = isset($scripts['preinstall']) || isset($scripts['install']) || isset($scripts['postinstall']);

        $entry = array_intersect_key($entry, array_flip(self::ABBREVIATED_VERSION_KEYS));

        if ($hasInstallScript) {
            $entry['hasInstallScript'] = true;
        }

        return $entry;
    }
}
