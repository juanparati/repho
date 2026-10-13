<?php

namespace App\Http\Controllers;

use App\Enums\Ecosystem;
use App\Models\DownloadStatistic;
use App\Models\Package;
use App\Models\Repository;
use App\Services\DistStorage;
use App\Services\NpmMetadataBuilder;
use App\Services\PackageFetcherResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The read side of the npm registry protocol, served per repository.
 */
class NpmRegistryController extends Controller
{
    public function __construct(
        private NpmMetadataBuilder $metadataBuilder,
        private DistStorage $dists,
        private PackageFetcherResolver $fetchers,
    ) {}

    /**
     * The repository parameter is type-hinted so route binding (and with it
     * the repository auth middleware) resolves the repository.
     */
    public function ping(Repository $repository): JsonResponse
    {
        return response()->json((object) []);
    }

    public function packument(Request $request, Repository $repository, string $name): JsonResponse
    {
        $package = $this->findPackage($repository, $name);

        if (! $package) {
            return $this->notFound();
        }

        $abbreviated = str_contains((string) $request->header('Accept'), NpmMetadataBuilder::ABBREVIATED_MEDIA_TYPE);

        return response()->json($this->metadataBuilder->buildPackument($repository, $package, $abbreviated));
    }

    public function distTags(Repository $repository, string $name): JsonResponse
    {
        $package = $this->findPackage($repository, $name);

        if (! $package) {
            return $this->notFound();
        }

        return response()->json((object) $this->metadataBuilder->buildDistTags($package));
    }

    public function tarball(Repository $repository, string $name, string $file): BinaryFileResponse|JsonResponse
    {
        $package = $this->findPackage($repository, $name);

        if (! $package) {
            return $this->notFound();
        }

        $prefix = $package->shortName().'-';

        if (! str_starts_with($file, $prefix)) {
            return $this->notFound();
        }

        $version = $package->versions()
            ->where('version', substr($file, strlen($prefix)))
            ->first();

        if ($version === null) {
            return $this->notFound();
        }

        $path = $this->dists->store($package, $version, $this->fetchers->for($package));

        if ($path === null) {
            return $this->notFound();
        }

        DownloadStatistic::recordDownload($package->id, $version->id);

        return response()->download($path, "{$file}.tgz", [
            'Content-Type' => $this->dists->contentType($package),
        ]);
    }

    private function findPackage(Repository $repository, string $name): ?Package
    {
        return $repository->packages()
            ->where('ecosystem', Ecosystem::Npm)
            ->where('name', $name)
            ->first();
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
    }
}
