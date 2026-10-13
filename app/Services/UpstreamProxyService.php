<?php

namespace App\Services;

use App\Enums\Ecosystem;
use App\Enums\RepositoryAuthType;
use App\Models\ProxySetting;
use App\Models\ProxyUpstream;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Shared plumbing for proxying an upstream registry: cached metadata
 * fetches, upstream authentication, and signed, cached archive downloads.
 */
abstract class UpstreamProxyService
{
    protected ProxySetting $settings;

    /** @var Collection<int, ProxyUpstream> */
    protected Collection $upstreams;

    public function __construct()
    {
        $this->settings = ProxySetting::instance();
        $this->upstreams = ProxyUpstream::query()->activeFor($this->ecosystem())->get();
    }

    abstract protected function ecosystem(): Ecosystem;

    /**
     * Directory under the proxy cache that holds this ecosystem's files.
     */
    abstract protected function cacheDirectory(): string;

    /**
     * Download (or serve from cache) an archive whose URL was issued by
     * rewriteArchiveUrl. Returns the local path, or null when the signature
     * is invalid or the upstream fails.
     */
    public function getArchiveFile(string $encodedUrl, string $signature, string $extension): ?string
    {
        if (! hash_equals($this->sign($encodedUrl), $signature)) {
            return null;
        }

        $originalUrl = base64_decode(strtr($encodedUrl, '-_', '+/'), true);

        if ($originalUrl === false || ! in_array(parse_url($originalUrl, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return null;
        }

        $hash = hash('sha256', $originalUrl);
        $cachePath = $this->archiveCachePath("{$hash}.{$extension}");

        if (file_exists($cachePath)) {
            return $cachePath;
        }

        try {
            $response = Http::withOptions(['timeout' => 120])->get($originalUrl);

            if ($response->failed()) {
                return null;
            }

            File::ensureDirectoryExists(dirname($cachePath));
            file_put_contents($cachePath, $response->body());

            return $cachePath;
        } catch (ConnectionException) {
            return null;
        }
    }

    /**
     * Fetch JSON from an upstream, honouring the metadata cache TTL and
     * falling back to a stale copy when the upstream is unreachable.
     *
     * @param  array<string, string>  $headers
     * @return array<string, mixed>|null
     */
    protected function fetchWithCache(ProxyUpstream $upstream, string $cachePath, string $upstreamPath, array $headers = []): ?array
    {
        $hasCache = file_exists($cachePath);
        $cacheExpired = ! $hasCache || $this->isCacheExpired($cachePath);

        if ($cacheExpired) {
            try {
                $response = $this->buildUpstreamClient($upstream)
                    ->withHeaders($headers)
                    ->get(rtrim($upstream->upstream_url, '/').$upstreamPath);

                if ($response->successful()) {
                    File::ensureDirectoryExists(dirname($cachePath));
                    file_put_contents($cachePath, $response->body());

                    return $response->json();
                }
            } catch (ConnectionException) {
                // Fall through to serve stale cache if available
            }
        }

        if ($hasCache) {
            $contents = file_get_contents($cachePath);

            return $contents !== false ? json_decode($contents, true) : null;
        }

        return null;
    }

    protected function buildUpstreamClient(ProxyUpstream $upstream): PendingRequest
    {
        $client = Http::withOptions(['timeout' => 30])
            ->acceptJson();

        return match ($upstream->auth_type) {
            RepositoryAuthType::None => $client,
            RepositoryAuthType::Basic => $client->withBasicAuth(
                (string) $upstream->auth_username,
                (string) $upstream->auth_password,
            ),
            RepositoryAuthType::Token => $client->withToken(
                (string) $upstream->auth_token,
            ),
        };
    }

    /**
     * Encode and sign an upstream archive URL so the matching download
     * endpoint only ever fetches URLs this service issued.
     *
     * @return array{0: string, 1: string} The encoded URL and its signature.
     */
    protected function signArchiveUrl(string $url): array
    {
        $encoded = rtrim(strtr(base64_encode($url), '+/', '-_'), '=');

        return [$encoded, $this->sign($encoded)];
    }

    protected function metadataCachePath(ProxyUpstream $upstream, string $relativePath): string
    {
        return config('repho.proxy_cache_path').'/'.$this->cacheDirectory()."metadata/{$upstream->id}/".$relativePath;
    }

    protected function archiveCachePath(string $relativePath): string
    {
        return config('repho.proxy_cache_path').'/'.$this->cacheDirectory().'dists/'.$relativePath;
    }

    private function sign(string $encodedUrl): string
    {
        return hash_hmac('sha256', $encodedUrl, (string) config('app.key'));
    }

    private function isCacheExpired(string $cachePath): bool
    {
        $mtime = filemtime($cachePath);

        if ($mtime === false) {
            return true;
        }

        return (time() - $mtime) > $this->settings->metadata_cache_ttl;
    }
}
