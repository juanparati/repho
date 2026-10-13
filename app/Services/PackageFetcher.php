<?php

namespace App\Services;

use App\Models\Package;

interface PackageFetcher
{
    /**
     * Fetch all versions from the remote repository: tags, plus the default
     * branch as a dev version for Composer packages. The manifest is the
     * decoded composer.json or package.json of that revision.
     *
     * @return array<int, array{version: string, version_normalized: string, reference: string, manifest: array<string, mixed>, released_at: string|null}>
     */
    public function fetchVersions(Package $package): array;

    /**
     * Get the raw Git archive (zip) for a specific version.
     *
     * @return resource|string|null
     */
    public function getDistArchive(Package $package, string $reference): mixed;
}
