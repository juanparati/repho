<?php

namespace App\Http\Controllers;

use App\Enums\Ecosystem;
use App\Models\DownloadStatistic;
use App\Models\Package;
use App\Models\Repository;
use App\Services\DistStorage;
use App\Services\MetadataBuilder;
use App\Services\PackageFetcherResolver;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class ComposerController extends Controller
{
    public function __construct(
        private MetadataBuilder $metadataBuilder,
        private DistStorage $dists,
        private PackageFetcherResolver $fetchers,
    ) {}

    public function packagesJson(Repository $repository): JsonResponse
    {
        return response()->json($this->metadataBuilder->buildPackagesJson($repository));
    }

    public function packageMetadata(Repository $repository, string $vendor, string $packageName): JsonResponse
    {
        $devOnly = false;
        if (str_ends_with($packageName, '~dev')) {
            $packageName = substr($packageName, 0, -4);
            $devOnly = true;
        }

        $package = $this->findPackage($repository, "{$vendor}/{$packageName}");

        if (! $package) {
            return response()->json(['error' => 'Package not found'], Response::HTTP_NOT_FOUND);
        }

        return response()->json(
            $this->metadataBuilder->buildPackageMetadata($repository, $package, $devOnly)
        );
    }

    public function dist(Repository $repository, string $vendor, string $packageName, string $version, string $ref): BinaryFileResponse|Response
    {
        $package = $this->findPackage($repository, "{$vendor}/{$packageName}");

        if (! $package) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $versionModel = $package->versions()->where('reference', $ref)->first();

        if ($versionModel === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        DownloadStatistic::recordDownload($package->id, $versionModel->id);

        $path = $this->dists->store($package, $versionModel, $this->fetchers->for($package));

        if ($path === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return response()->download($path, $this->dists->downloadName($package, $version), [
            'Content-Type' => $this->dists->contentType($package),
        ]);
    }

    private function findPackage(Repository $repository, string $fullName): ?Package
    {
        return $repository->packages()
            ->where('ecosystem', Ecosystem::Composer)
            ->where('name', $fullName)
            ->first();
    }
}
