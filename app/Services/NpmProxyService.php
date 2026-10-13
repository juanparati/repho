<?php

namespace App\Services;

use App\Enums\Ecosystem;

class NpmProxyService extends UpstreamProxyService
{
    protected function ecosystem(): Ecosystem
    {
        return Ecosystem::Npm;
    }

    protected function cacheDirectory(): string
    {
        return 'npm/';
    }

    /**
     * Get a package document from the first upstream that has it (priority
     * order), with tarball URLs rewritten to go through this proxy. The
     * abbreviated (install-only) form is cached separately from the full one.
     *
     * @return array<string, mixed>|null
     */
    public function getPackument(string $name, bool $abbreviated = false): ?array
    {
        $upstreamPath = '/'.str_replace('/', '%2F', $name);
        $cacheFile = $name.($abbreviated ? '.abbreviated.json' : '.json');
        $headers = $abbreviated ? ['Accept' => NpmMetadataBuilder::ABBREVIATED_MEDIA_TYPE] : [];

        foreach ($this->upstreams as $upstream) {
            $cachePath = $this->metadataCachePath($upstream, $cacheFile);
            $data = $this->fetchWithCache($upstream, $cachePath, $upstreamPath, $headers);

            if ($data !== null) {
                $this->rewriteTarballUrls($data);

                return $data;
            }
        }

        return null;
    }

    public function getTarballFile(string $encodedUrl, string $signature): ?string
    {
        return $this->getArchiveFile($encodedUrl, $signature, 'tgz');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function rewriteTarballUrls(array &$data): void
    {
        if (! isset($data['versions']) || ! is_array($data['versions'])) {
            return;
        }

        foreach ($data['versions'] as &$version) {
            if (! is_array($version)) {
                continue;
            }

            if (isset($version['dist']['tarball']) && is_string($version['dist']['tarball'])) {
                [$encoded, $signature] = $this->signArchiveUrl($version['dist']['tarball']);
                $version['dist']['tarball'] = url('/proxy/npm/-/tarball/'.$encoded.'/'.$signature);
            }
        }
    }
}
