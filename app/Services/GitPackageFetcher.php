<?php

namespace App\Services;

use App\Concerns\NormalizesTagVersions;
use App\Models\Package;
use Illuminate\Support\Facades\Process;

class GitPackageFetcher implements PackageFetcher
{
    use NormalizesTagVersions;

    public function fetchVersions(Package $package): array
    {
        $repoPath = $this->ensureCloned($package);
        $manifestFile = $package->ecosystem->manifestFile();
        $versions = [];

        $tagsResult = Process::path($repoPath)->run('git tag');

        if (! $tagsResult->successful()) {
            throw new \RuntimeException('Failed to list tags: '.$tagsResult->errorOutput());
        }

        $tags = array_filter(explode("\n", trim($tagsResult->output())));

        foreach ($tags as $tagName) {
            $resolved = $this->resolveTagVersion($package, $tagName);

            if ($resolved === null) {
                continue;
            }

            $manifest = $this->readManifest($repoPath, $tagName, $manifestFile);

            if ($manifest === null) {
                continue;
            }

            $sha = $this->getReference($repoPath, $tagName);
            if ($sha === null) {
                continue;
            }

            $versions[] = [
                ...$resolved,
                'reference' => $sha,
                'manifest' => $manifest,
                'released_at' => $this->getTagDate($repoPath, $tagName),
            ];
        }

        if ($package->isComposer()) {
            $devVersion = $this->fetchDevVersion($repoPath, $manifestFile);
            if ($devVersion !== null) {
                $versions[] = $devVersion;
            }
        }

        return $versions;
    }

    public function getDistArchive(Package $package, string $reference): mixed
    {
        $repoPath = $this->getRepoPath($package);

        if (! is_dir($repoPath)) {
            return null;
        }

        $result = Process::path($repoPath)
            ->run(['git', 'archive', '--format=zip', '--', $reference]);

        if (! $result->successful()) {
            return null;
        }

        return $result->output();
    }

    private function getRepoPath(Package $package): string
    {
        return config('repho.git_clone_path').'/'.$package->id;
    }

    private function ensureCloned(Package $package): string
    {
        $repoPath = $this->getRepoPath($package);

        if (is_dir($repoPath)) {
            // Heal clones created before credentials were passed per invocation:
            // they carry the token embedded in the persisted remote URL.
            Process::path($repoPath)->run(['git', 'remote', 'set-url', 'origin', $package->repository_url]);

            $result = Process::path($repoPath)->run($this->gitCommand($package, ['fetch', '--tags', '--prune']));

            if (! $result->successful()) {
                throw new \RuntimeException('Failed to fetch: '.$result->errorOutput());
            }

            return $repoPath;
        }

        $parentDir = dirname($repoPath);
        if (! is_dir($parentDir)) {
            mkdir($parentDir, 0755, true);
        }

        $result = Process::run($this->gitCommand($package, ['clone', '--bare', '--', $package->repository_url, $repoPath]));

        if (! $result->successful()) {
            throw new \RuntimeException('Failed to clone: '.$result->errorOutput());
        }

        return $repoPath;
    }

    /**
     * Build a git command that supplies the package's credential per
     * invocation, so the current token is always used and never written
     * to the clone's config on disk.
     *
     * @param  list<string>  $args
     * @return list<string>
     */
    private function gitCommand(Package $package, array $args): array
    {
        $command = ['git'];

        if ($package->credential?->token) {
            $command[] = '-c';
            $command[] = 'http.extraHeader=Authorization: Basic '.base64_encode("oauth2:{$package->credential->token}");
        }

        return array_merge($command, $args);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readManifest(string $repoPath, string $ref, string $file): ?array
    {
        $result = Process::path($repoPath)->run(['git', 'show', $ref.':'.$file]);

        if (! $result->successful()) {
            return null;
        }

        $json = json_decode($result->output(), true);

        return is_array($json) ? $json : null;
    }

    private function getReference(string $repoPath, string $ref): ?string
    {
        $result = Process::path($repoPath)->run(['git', 'rev-parse', $ref.'^{}']);

        if (! $result->successful()) {
            return null;
        }

        return trim($result->output());
    }

    private function getTagDate(string $repoPath, string $tag): ?string
    {
        $result = Process::path($repoPath)->run(['git', 'log', '-1', '--format=%aI', '--', $tag]);

        if (! $result->successful()) {
            return null;
        }

        return trim($result->output()) ?: null;
    }

    /**
     * @return array{version: string, version_normalized: string, reference: string, manifest: array<string, mixed>, released_at: string|null}|null
     */
    private function fetchDevVersion(string $repoPath, string $manifestFile): ?array
    {
        $result = Process::path($repoPath)->run('git symbolic-ref HEAD');

        if (! $result->successful()) {
            return null;
        }

        $headRef = trim($result->output());
        $branch = str_replace('refs/heads/', '', $headRef);

        $sha = $this->getReference($repoPath, 'HEAD');
        if ($sha === null) {
            return null;
        }

        $manifest = $this->readManifest($repoPath, 'HEAD', $manifestFile);
        if ($manifest === null) {
            return null;
        }

        return [
            'version' => "dev-{$branch}",
            'version_normalized' => "dev-{$branch}",
            'reference' => $sha,
            'manifest' => $manifest,
            'released_at' => null,
        ];
    }
}
