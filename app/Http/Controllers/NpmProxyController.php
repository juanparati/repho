<?php

namespace App\Http\Controllers;

use App\Services\NpmMetadataBuilder;
use App\Services\NpmProxyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class NpmProxyController extends Controller
{
    public function __construct(
        private NpmProxyService $proxyService,
    ) {}

    public function ping(): JsonResponse
    {
        return response()->json((object) []);
    }

    public function packument(Request $request, string $name): JsonResponse
    {
        $abbreviated = str_contains((string) $request->header('Accept'), NpmMetadataBuilder::ABBREVIATED_MEDIA_TYPE);

        $data = $this->proxyService->getPackument($name, $abbreviated);

        if ($data === null) {
            return response()->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        return response()->json($data);
    }

    public function tarball(string $encodedUrl, string $signature): BinaryFileResponse
    {
        $path = $this->proxyService->getTarballFile($encodedUrl, $signature);

        if ($path === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return response()->download($path, 'package.tgz', [
            'Content-Type' => 'application/octet-stream',
        ]);
    }
}
