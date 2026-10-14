<?php

namespace App\Services;

use App\Models\Package;
use App\Models\Version;
use Illuminate\Support\Facades\File;

/**
 * Where dist archives live on disk and how they get there. Composer dists
 * are the raw Git archive; npm dists are tarballs built by Rephó.
 */
class DistStorage
{
    public function __construct(
        private NpmTarballBuilder $tarballBuilder,
    ) {}

    public function path(Package $package, string $reference): string
    {
        $reference = basename($reference);

        if ($package->isNpm()) {
            return config('repho.dist_cache_path')."/npm/{$package->vendor()}/{$package->shortName()}/{$reference}.tgz";
        }

        return config('repho.dist_cache_path')."/{$package->vendor()}/{$package->shortName()}/{$reference}.zip";
    }

    public function exists(Package $package, string $reference): bool
    {
        return file_exists($this->path($package, $reference));
    }

    public function downloadName(Package $package, string $version): string
    {
        $extension = $package->isNpm() ? 'tgz' : 'zip';

        return "{$package->shortName()}-{$version}.{$extension}";
    }

    public function contentType(Package $package): string
    {
        return $package->isNpm() ? 'application/octet-stream' : 'application/zip';
    }

    /**
     * Ensure the dist for a version is on disk, fetching and (for npm)
     * building it when missing. Returns the path, or null when the source
     * archive could not be obtained.
     */
    public function store(Package $package, Version $version, PackageFetcher $fetcher): ?string
    {
        $path = $this->path($package, $version->reference);

        if (file_exists($path)) {
            if ($package->isNpm() && $version->integrity === null) {
                $this->recordHashes($version, (string) file_get_contents($path));
            }

            return $path;
        }

        $archive = $fetcher->getDistArchive($package, $version->reference);

        if ($archive === null) {
            return null;
        }

        $contents = is_resource($archive) ? (string) stream_get_contents($archive) : (string) $archive;

        if ($package->isNpm()) {
            $built = $this->tarballBuilder->build($contents, $package->name, $version->version, $package->subdirectory);
            $contents = $built['contents'];
        }

        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, $contents);

        if ($package->isNpm()) {
            $this->recordHashes($version, $contents);
        }

        return $path;
    }

    public function delete(Package $package, string $reference): void
    {
        $path = $this->path($package, $reference);

        if (file_exists($path)) {
            File::delete($path);
        }
    }

    private function recordHashes(Version $version, string $contents): void
    {
        $version->update([
            'shasum' => sha1($contents),
            'integrity' => 'sha512-'.base64_encode(hash('sha512', $contents, true)),
        ]);
    }
}
