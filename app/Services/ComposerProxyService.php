<?php

namespace App\Services;

use App\Enums\Ecosystem;

class ComposerProxyService extends UpstreamProxyService
{
    protected function ecosystem(): Ecosystem
    {
        return Ecosystem::Composer;
    }

    protected function cacheDirectory(): string
    {
        return '';
    }

    /**
     * Merge packages.json from all enabled upstreams.
     *
     * @return array<string, mixed>
     */
    public function getPackagesJson(): array
    {
        $allPackages = [];
        $hasResponse = false;

        foreach ($this->upstreams as $upstream) {
            $cachePath = $this->metadataCachePath($upstream, 'packages.json');
            $data = $this->fetchWithCache($upstream, $cachePath, '/packages.json');

            if ($data === null) {
                continue;
            }

            $hasResponse = true;
            $packages = $data['available-packages'] ?? [];
            $allPackages = array_merge($allPackages, $packages);
        }

        if (! $hasResponse) {
            return [];
        }

        return [
            'metadata-url' => url('/proxy/p2/%package%.json'),
            'available-packages' => array_values(array_unique($allPackages)),
        ];
    }

    /**
     * Get package metadata from the first upstream that has it (priority order).
     *
     * @return array<string, mixed>|null
     */
    public function getPackageMetadata(string $vendor, string $package): ?array
    {
        $upstreamPath = "/p2/{$vendor}/{$package}.json";

        foreach ($this->upstreams as $upstream) {
            $cachePath = $this->metadataCachePath($upstream, "p2/{$vendor}/{$package}.json");
            $data = $this->fetchWithCache($upstream, $cachePath, $upstreamPath);

            if ($data !== null) {
                $this->rewriteDistUrls($data);

                return $data;
            }
        }

        return null;
    }

    public function getDistFile(string $encodedUrl, string $signature): ?string
    {
        return $this->getArchiveFile($encodedUrl, $signature, 'zip');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function rewriteDistUrls(array &$data): void
    {
        if (! isset($data['packages']) || ! is_array($data['packages'])) {
            return;
        }

        foreach ($data['packages'] as &$versions) {
            if (! is_array($versions)) {
                continue;
            }

            foreach ($versions as &$version) {
                if (! is_array($version)) {
                    continue;
                }

                if (isset($version['dist']['url']) && is_string($version['dist']['url'])) {
                    [$encoded, $signature] = $this->signArchiveUrl($version['dist']['url']);
                    $version['dist']['url'] = url('/proxy/dists/'.$encoded.'/'.$signature);
                }
            }
        }
    }
}
