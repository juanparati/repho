<?php

namespace App\Services;

use App\Enums\Ecosystem;
use App\Enums\PackageType;
use App\Models\Package;

class NpmPackageNameResolver
{
    public function __construct(private PackageFetcherResolver $fetchers) {}

    /**
     * Derive the npm package name from the repository's default-branch
     * package.json, lowercased and checked against the npm name pattern and
     * uniqueness among npm packages.
     *
     * @param  string|null  $subdirectory  Path of the package inside the repository, for monorepos.
     *
     * @throws \RuntimeException with a user-facing message
     */
    public function resolve(PackageType $type, string $repositoryUrl, ?int $credentialId, ?string $subdirectory = null): string
    {
        $package = new Package([
            'ecosystem' => Ecosystem::Npm,
            'type' => $type,
            'repository_url' => $repositoryUrl,
            'credential_id' => $credentialId,
            'subdirectory' => $subdirectory,
        ]);

        try {
            $manifest = $this->fetchers->for($package)->fetchDefaultBranchManifest($package);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Could not read package.json from the repository: '.$e->getMessage(), previous: $e);
        }

        $name = $manifest['name'] ?? null;

        if (! is_string($name) || $name === '') {
            throw new \RuntimeException(sprintf(
                'Could not read a package name from %s on the default branch. Check the repository URL, subdirectory and credential, or enter the name manually.',
                $package->manifestPath(),
            ));
        }

        $name = strtolower($name);

        if (preg_match('/^'.Package::NPM_NAME_PATTERN.'$/', $name) !== 1) {
            throw new \RuntimeException(sprintf('The name "%s" in package.json is not a valid npm package name.', $name));
        }

        if (Package::query()->where('ecosystem', Ecosystem::Npm)->where('name', $name)->exists()) {
            throw new \RuntimeException(sprintf('An npm package named "%s" already exists.', $name));
        }

        return $name;
    }
}
