<?php

namespace App\Http\Controllers;

use App\Enums\Ecosystem;
use App\Enums\PackageType;
use App\Http\Requests\StorePackageRequest;
use App\Http\Requests\UpdatePackageRequest;
use App\Jobs\SyncPackage;
use App\Models\Credential;
use App\Models\DownloadStatistic;
use App\Models\Package;
use App\Models\Repository;
use App\Models\Version;
use App\Services\DistStorage;
use App\Services\NpmPackageNameResolver;
use App\Services\NpmSemver;
use App\Services\SecurityAdvisoryChecker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PackageController extends Controller
{
    public function __construct(
        private DistStorage $dists,
    ) {}

    public function index(): Response
    {
        return Inertia::render('packages/index', [
            'packages' => Package::query()
                ->withCount('versions')
                ->latest()
                ->get()
                ->map(fn (Package $package) => [
                    'id' => $package->id,
                    'name' => $package->name,
                    'ecosystem' => $package->ecosystem->value,
                    'repository_url' => $package->repository_url,
                    'type' => $package->type->value,
                    'description' => $package->description,
                    'versions_count' => $package->versions_count,
                    'last_synced_at' => $package->last_synced_at?->toDateTimeString(),
                    'sync_error' => $package->sync_error,
                    'is_syncing' => $package->is_syncing,
                    'sync_progress' => $package->sync_progress,
                    'created_at' => $package->created_at->toDateTimeString(),
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('packages/create', [
            'credentials' => Credential::all(['id', 'name', 'type']),
            'repositories' => Repository::all(['id', 'name', 'slug']),
        ]);
    }

    public function store(StorePackageRequest $request, SecurityAdvisoryChecker $advisoryChecker, NpmPackageNameResolver $nameResolver): RedirectResponse
    {
        $validated = $request->validated();
        $repositoryIds = $validated['repository_ids'] ?? [];
        unset($validated['repository_ids']);

        $ecosystem = Ecosystem::from($validated['ecosystem']);

        try {
            $packageName = match (true) {
                $ecosystem !== Ecosystem::Npm => $this->guessPackageName($validated['repository_url']),
                filled($validated['name'] ?? null) => $validated['name'],
                default => $nameResolver->resolve(
                    PackageType::from($validated['type']),
                    $validated['repository_url'],
                    $validated['credential_id'] ?? null,
                    $validated['subdirectory'] ?? null,
                ),
            };
        } catch (\RuntimeException $e) {
            return redirect()->route('packages.create')
                ->withErrors(['name' => $e->getMessage()]);
        }

        $advisories = $advisoryChecker->check($packageName, $ecosystem);

        if (! empty($advisories)) {
            $messages = array_map(
                fn (array $advisory) => sprintf(
                    '%s%s (affects %s)',
                    $advisory['title'],
                    $advisory['cve'] ? " [{$advisory['cve']}]" : '',
                    $advisory['affectedVersions'],
                ),
                $advisories,
            );

            return redirect()->route('packages.create')
                ->withErrors(['repository_url' => 'This package has known security vulnerabilities: '.implode('; ', $messages)]);
        }

        $package = Package::create([
            ...$validated,
            'name' => $packageName,
        ]);

        if (! empty($repositoryIds)) {
            $package->repositories()->attach($repositoryIds);
        }

        $package->update(['is_syncing' => true]);
        SyncPackage::dispatch($package);

        return redirect()->route('packages.show', $package)
            ->with('success', 'Package added successfully.');
    }

    public function show(Package $package): Response
    {
        $package->load('repositories');

        return Inertia::render('packages/show', [
            'package' => [
                'id' => $package->id,
                'name' => $package->name,
                'ecosystem' => $package->ecosystem->value,
                'repository_url' => $package->repository_url,
                'subdirectory' => $package->subdirectory,
                'type' => $package->type->value,
                'download_dists' => $package->download_dists,
                'description' => $package->description,
                'last_synced_at' => $package->last_synced_at?->toDateTimeString(),
                'sync_error' => $package->sync_error,
                'is_syncing' => $package->is_syncing,
                'sync_progress' => $package->sync_progress,
                'created_at' => $package->created_at->toDateTimeString(),
                'repositories' => $package->repositories->map(fn ($r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'slug' => $r->slug,
                ]),
            ],
            'versions' => $package->versions()
                ->get()
                ->sort(fn ($a, $b) => $package->isNpm()
                    ? NpmSemver::compare($b->version_normalized, $a->version_normalized)
                    : version_compare($b->version_normalized, $a->version_normalized))
                ->values()
                ->map(fn ($v) => [
                    'id' => $v->id,
                    'version' => $v->version,
                    'version_normalized' => $v->version_normalized,
                    'reference' => $v->reference,
                    'integrity' => $v->integrity,
                    'released_at' => $v->released_at?->toDateTimeString(),
                    'created_at' => $v->created_at->toDateTimeString(),
                    'dist_url' => $this->dists->exists($package, $v->reference)
                        ? route('packages.versions.download', [$package, $v])
                        : null,
                ]),
            'availableRepositories' => Repository::query()
                ->whereNotIn('id', $package->repositories->pluck('id'))
                ->get(['id', 'name', 'slug']),
            'downloadStats' => $this->getDownloadStats($package),
        ]);
    }

    public function edit(Package $package): Response|RedirectResponse
    {
        if ($package->is_syncing) {
            return redirect()->route('packages.show', $package)
                ->with('error', 'Cannot edit while the package is syncing.');
        }

        return Inertia::render('packages/edit', [
            'package' => [
                'id' => $package->id,
                'name' => $package->name,
                'ecosystem' => $package->ecosystem->value,
                'repository_url' => $package->repository_url,
                'type' => $package->type->value,
                'credential_id' => $package->credential_id,
                'download_dists' => $package->download_dists,
            ],
            'credentials' => Credential::all(['id', 'name', 'type']),
        ]);
    }

    public function update(UpdatePackageRequest $request, Package $package): RedirectResponse
    {
        if ($package->is_syncing) {
            return redirect()->route('packages.show', $package)
                ->with('error', 'Cannot update while the package is syncing.');
        }

        $package->update($request->validated());

        return redirect()->route('packages.show', $package)
            ->with('success', 'Package updated successfully.');
    }

    public function destroy(Package $package): RedirectResponse
    {
        $package->delete();

        return redirect()->route('packages.index')
            ->with('success', 'Package deleted successfully.');
    }

    public function sync(Package $package): RedirectResponse
    {
        if ($package->is_syncing) {
            return redirect()->route('packages.show', $package)
                ->with('error', 'Package is already syncing.');
        }

        $package->update(['is_syncing' => true]);
        SyncPackage::dispatch($package);

        return redirect()->route('packages.show', $package)
            ->with('success', 'Package sync started.');
    }

    public function attachRepository(Request $request, Package $package): RedirectResponse
    {
        $request->validate([
            'repository_id' => ['required', 'exists:repositories,id'],
        ]);

        $package->repositories()->syncWithoutDetaching([$request->input('repository_id')]);

        return redirect()->route('packages.show', $package);
    }

    public function detachRepository(Package $package, Repository $repository): RedirectResponse
    {
        $package->repositories()->detach($repository->id);

        return redirect()->route('packages.show', $package);
    }

    public function downloadVersion(Package $package, Version $version): BinaryFileResponse
    {
        abort_unless($version->package_id === $package->id, 404);

        abort_unless($this->dists->exists($package, $version->reference), 404);

        return response()->download(
            $this->dists->path($package, $version->reference),
            $this->dists->downloadName($package, $version->version),
            ['Content-Type' => $this->dists->contentType($package)],
        );
    }

    /**
     * @return array<int, array{date: string, downloads: int}>
     */
    private function getDownloadStats(Package $package): array
    {
        $startDate = now()->subDays(29)->startOfDay();

        $downloads = DownloadStatistic::query()
            ->where('package_id', $package->id)
            ->where('date', '>=', $startDate)
            ->select(DB::raw('date, SUM(downloads) as total'))
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('total', 'date')
            ->all();

        $chartData = [];
        for ($i = 29; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $chartData[] = [
                'date' => $date,
                'downloads' => (int) ($downloads[$date] ?? 0),
            ];
        }

        return $chartData;
    }

    private function guessPackageName(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $path = trim($path, '/');
        $path = preg_replace('/\.git$/', '', $path);

        return strtolower($path);
    }
}
