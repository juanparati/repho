<?php

namespace App\Services;

use App\Models\Package;
use App\Models\SecurityAdvisory;
use App\Models\Version;

class PackageSynchronizer
{
    public function __construct(
        private PackageFetcherResolver $fetchers,
        private DistStorage $dists,
        private SecurityAdvisoryChecker $advisoryChecker,
    ) {}

    public function sync(Package $package): void
    {
        $fetcher = $this->fetchers->for($package);

        try {
            $package->update(['sync_progress' => 10]);

            $versions = $fetcher->fetchVersions($package);

            if ($package->isNpm()) {
                $this->assertManifestNamesMatch($package, $versions);
            }

            $previousReferences = $package->versions()->pluck('reference', 'id')->all();

            $package->update(['sync_progress' => 25]);

            $this->storeVersions($package, $versions);

            $package->update(['sync_progress' => 35]);

            if ($package->storesDistsOnSync()) {
                $this->downloadDists($package, $previousReferences, $fetcher);
            }

            $this->syncAdvisories($package);

            $description = $this->extractDescription($versions);

            $package->update([
                'last_synced_at' => now(),
                'sync_error' => null,
                'sync_progress' => 100,
                'description' => $description ?? $package->description,
            ]);
        } catch (\Throwable $e) {
            $package->update(['sync_error' => $e->getMessage()]);

            throw $e;
        }
    }

    /**
     * The npm client requests metadata by the exact name in package.json, so
     * a package whose manifest carries a different name would never resolve.
     * The comparison ignores case because tags published before npm enforced
     * lowercase names (e.g. "MD5" for md5@1.x) still carry the old spelling.
     *
     * @param  array<int, array{version: string, version_normalized: string, reference: string, manifest: array<string, mixed>, released_at: string|null}>  $versions
     */
    private function assertManifestNamesMatch(Package $package, array $versions): void
    {
        foreach ($versions as $version) {
            $manifestName = $version['manifest']['name'] ?? null;

            if (! is_string($manifestName) || strtolower($manifestName) !== $package->name) {
                throw new \RuntimeException(sprintf(
                    'package.json at tag %s declares the name "%s", but this package is registered as "%s".',
                    $version['version'],
                    is_string($manifestName) ? $manifestName : '(missing)',
                    $package->name,
                ));
            }
        }
    }

    /**
     * @param  array<int, array{version: string, version_normalized: string, reference: string, manifest: array<string, mixed>, released_at: string|null}>  $versions
     */
    private function storeVersions(Package $package, array $versions): void
    {
        if (empty($versions)) {
            return;
        }

        $rows = array_map(fn (array $v) => [
            'package_id' => $package->id,
            'version' => $v['version'],
            'version_normalized' => $v['version_normalized'],
            'reference' => $v['reference'],
            'manifest' => json_encode($v['manifest']),
            'released_at' => $v['released_at'],
        ], $versions);

        Version::upsert(
            $rows,
            ['package_id', 'version_normalized'],
            ['version', 'reference', 'manifest', 'released_at'],
        );

        $normalizedVersions = array_column($versions, 'version_normalized');
        $package->versions()
            ->whereNotIn('version_normalized', $normalizedVersions)
            ->delete();
    }

    /**
     * @param  array<int, string>  $previousReferences
     */
    private function downloadDists(Package $package, array $previousReferences, PackageFetcher $fetcher): void
    {
        $currentVersions = $package->versions()->get();
        $previousReferenceValues = array_values($previousReferences);

        // npm tarballs are mandatory, so a version whose build failed earlier
        // is retried on the next sync instead of being left without a dist.
        $newVersions = $currentVersions->filter(
            fn (Version $version) => ! in_array($version->reference, $previousReferenceValues)
                || ($package->isNpm() && ! $this->dists->exists($package, $version->reference)),
        );

        $currentReferences = $currentVersions->pluck('reference')->all();
        $removedReferences = array_diff($previousReferenceValues, $currentReferences);

        foreach ($removedReferences as $reference) {
            $this->dists->delete($package, $reference);
        }

        if ($newVersions->isEmpty()) {
            return;
        }

        $completed = 0;
        $total = $newVersions->count();

        foreach ($newVersions as $version) {
            $this->dists->store($package, $version, $fetcher);
            $completed++;
            $progress = 35 + (int) round(($completed / $total) * 60);
            $package->update(['sync_progress' => $progress]);
        }
    }

    private function syncAdvisories(Package $package): void
    {
        try {
            $advisories = $this->advisoryChecker->check(
                $package->name,
                $package->ecosystem,
                $package->versions()->pluck('version')->all(),
            );
        } catch (\Throwable) {
            return;
        }

        $existingIds = $package->securityAdvisories()->pluck('advisory_id')->all();

        foreach ($advisories as $advisory) {
            if (in_array($advisory['advisoryId'], $existingIds)) {
                continue;
            }

            SecurityAdvisory::create([
                'package_id' => $package->id,
                'advisory_id' => $advisory['advisoryId'],
                'title' => $advisory['title'],
                'link' => $advisory['link'],
                'cve' => $advisory['cve'],
                'affected_versions' => $advisory['affectedVersions'],
                'severity' => $advisory['severity'],
                'reported_at' => $advisory['reportedAt'],
            ]);
        }

        $activeIds = array_column($advisories, 'advisoryId');
        $package->securityAdvisories()
            ->whereNotIn('advisory_id', $activeIds)
            ->delete();
    }

    /**
     * @param  array<int, array{version: string, version_normalized: string, reference: string, manifest: array<string, mixed>, released_at: string|null}>  $versions
     */
    private function extractDescription(array $versions): ?string
    {
        foreach ($versions as $version) {
            if (isset($version['manifest']['description'])) {
                return $version['manifest']['description'];
            }
        }

        return null;
    }
}
