<?php

namespace App\Services;

use App\Concerns\NormalizesTagVersions;
use App\Models\Package;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class GitHubPackageFetcher implements PackageFetcher
{
    use NormalizesTagVersions;

    public function fetchVersions(Package $package): array
    {
        [$owner, $repo] = $this->parseOwnerRepo($package->repository_url);
        $client = $this->buildClient($package);
        $manifestFile = $package->ecosystem->manifestFile();
        $versions = [];

        $tags = $this->fetchAllTags($client, $owner, $repo);

        foreach ($tags as $tag) {
            $tagName = $tag['name'];
            $resolved = $this->resolveTagVersion($package, $tagName);

            if ($resolved === null) {
                continue;
            }

            $manifest = $this->fetchManifest($client, $owner, $repo, $tagName, $manifestFile);

            if ($manifest === null) {
                continue;
            }

            $versions[] = [
                ...$resolved,
                'reference' => $tag['commit']['sha'],
                'manifest' => $manifest,
                'released_at' => null,
            ];
        }

        if ($package->isComposer()) {
            $devVersion = $this->fetchDevVersion($client, $owner, $repo, $manifestFile);
            if ($devVersion !== null) {
                $versions[] = $devVersion;
            }
        }

        return $versions;
    }

    public function getDistArchive(Package $package, string $reference): mixed
    {
        [$owner, $repo] = $this->parseOwnerRepo($package->repository_url);
        $client = $this->buildClient($package);

        $response = $client->withOptions(['allow_redirects' => false])
            ->get("https://api.github.com/repos/{$owner}/{$repo}/zipball/{$reference}");

        if ($response->redirect()) {
            $redirectUrl = $response->header('Location');

            if (! $this->isAllowedRedirectUrl($redirectUrl)) {
                return null;
            }

            return Http::withOptions(['sink' => null])->get($redirectUrl)->body();
        }

        if ($response->successful()) {
            return $response->body();
        }

        return null;
    }

    private function isAllowedRedirectUrl(?string $url): bool
    {
        if ($url === null) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if ($host === null) {
            return false;
        }

        $allowedDomains = ['github.com', 'githubusercontent.com', 'objects.githubusercontent.com'];

        foreach ($allowedDomains as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parseOwnerRepo(string $url): array
    {
        $path = parse_url($url, PHP_URL_PATH);
        $path = trim($path, '/');
        $path = preg_replace('/\.git$/', '', $path);
        $parts = explode('/', $path);

        return [$parts[0], $parts[1]];
    }

    private function buildClient(Package $package): PendingRequest
    {
        $client = Http::baseUrl('https://api.github.com')
            ->accept('application/vnd.github.v3+json');

        if ($package->credential?->token) {
            $client = $client->withToken($package->credential->token);
        }

        return $client;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchAllTags(PendingRequest $client, string $owner, string $repo): array
    {
        $tags = [];
        $page = 1;

        do {
            $response = $client->get("/repos/{$owner}/{$repo}/tags", [
                'per_page' => 100,
                'page' => $page,
            ]);

            if (! $response->successful()) {
                if ($page === 1) {
                    throw new \RuntimeException("Failed to fetch tags from GitHub: HTTP {$response->status()} — {$response->body()}");
                }

                break;
            }

            $batch = $response->json();
            if (empty($batch)) {
                break;
            }

            $tags = array_merge($tags, $batch);
            $page++;
        } while (count($batch) === 100);

        return $tags;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchManifest(PendingRequest $client, string $owner, string $repo, string $ref, string $file): ?array
    {
        $response = $client->get("/repos/{$owner}/{$repo}/contents/{$file}", [
            'ref' => $ref,
        ]);

        if (! $response->successful()) {
            return null;
        }

        $content = $response->json('content');
        if ($content === null) {
            return null;
        }

        $decoded = base64_decode($content);
        $json = json_decode($decoded, true);

        return is_array($json) ? $json : null;
    }

    /**
     * @return array{version: string, version_normalized: string, reference: string, manifest: array<string, mixed>, released_at: string|null}|null
     */
    private function fetchDevVersion(PendingRequest $client, string $owner, string $repo, string $manifestFile): ?array
    {
        $response = $client->get("/repos/{$owner}/{$repo}");

        if (! $response->successful()) {
            return null;
        }

        $defaultBranch = $response->json('default_branch', 'main');

        $branchResponse = $client->get("/repos/{$owner}/{$repo}/branches/{$defaultBranch}");

        if (! $branchResponse->successful()) {
            return null;
        }

        $sha = $branchResponse->json('commit.sha');
        $manifest = $this->fetchManifest($client, $owner, $repo, $defaultBranch, $manifestFile);

        if ($manifest === null) {
            return null;
        }

        return [
            'version' => "dev-{$defaultBranch}",
            'version_normalized' => "dev-{$defaultBranch}",
            'reference' => $sha,
            'manifest' => $manifest,
            'released_at' => null,
        ];
    }
}
