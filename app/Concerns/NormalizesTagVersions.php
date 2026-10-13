<?php

namespace App\Concerns;

use App\Models\Package;
use App\Services\NpmSemver;
use Composer\Semver\VersionParser;

trait NormalizesTagVersions
{
    /**
     * Resolve a Git tag into the version it represents for the package's
     * ecosystem, or null when the tag is not a version. Composer keeps the
     * tag as the version string; npm uses the cleaned semver.
     *
     * @return array{version: string, version_normalized: string}|null
     */
    protected function resolveTagVersion(Package $package, string $tag): ?array
    {
        if ($package->isNpm()) {
            $semver = NpmSemver::normalize($tag);

            return $semver === null ? null : ['version' => $semver, 'version_normalized' => $semver];
        }

        try {
            return ['version' => $tag, 'version_normalized' => (new VersionParser)->normalize($tag)];
        } catch (\UnexpectedValueException) {
            return null;
        }
    }
}
