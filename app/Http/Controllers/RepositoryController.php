<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRepositoryRequest;
use App\Http\Requests\UpdateRepositoryRequest;
use App\Models\Package;
use App\Models\Repository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RepositoryController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('repositories/index', [
            'repositories' => Repository::query()
                ->withCount('packages')
                ->latest()
                ->get()
                ->map(fn (Repository $repo) => [
                    'id' => $repo->id,
                    'name' => $repo->name,
                    'slug' => $repo->slug,
                    'auth_type' => $repo->auth_type->value,
                    'packages_count' => $repo->packages_count,
                    'created_at' => $repo->created_at->toDateTimeString(),
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('repositories/create');
    }

    public function store(StoreRepositoryRequest $request): RedirectResponse
    {
        $repository = Repository::create($request->validated());

        return redirect()->route('repositories.show', $repository)
            ->with('success', 'Repository created successfully.');
    }

    public function show(Repository $repository): Response
    {
        return Inertia::render('repositories/show', [
            'repository' => [
                'id' => $repository->id,
                'name' => $repository->name,
                'slug' => $repository->slug,
                'auth_type' => $repository->auth_type->value,
                'auth_username' => $repository->auth_username,
                'created_at' => $repository->created_at->toDateTimeString(),
            ],
            'packages' => $repository->packages()
                ->withCount('versions')
                ->get()
                ->map(fn (Package $pkg) => [
                    'id' => $pkg->id,
                    'name' => $pkg->name,
                    'ecosystem' => $pkg->ecosystem->value,
                    'versions_count' => $pkg->versions_count,
                ]),
            'availablePackages' => Package::query()
                ->whereDoesntHave('repositories', fn ($q) => $q->where('repositories.id', $repository->id))
                ->get(['id', 'name', 'ecosystem'])
                ->map(fn (Package $pkg) => [
                    'id' => $pkg->id,
                    'name' => $pkg->name,
                    'ecosystem' => $pkg->ecosystem->value,
                ]),
        ]);
    }

    public function edit(Repository $repository): Response
    {
        return Inertia::render('repositories/edit', [
            'repository' => [
                'id' => $repository->id,
                'name' => $repository->name,
                'slug' => $repository->slug,
                'auth_type' => $repository->auth_type->value,
                'auth_username' => $repository->auth_username,
            ],
        ]);
    }

    public function update(UpdateRepositoryRequest $request, Repository $repository): RedirectResponse
    {
        $repository->update($request->validated());

        return redirect()->route('repositories.show', $repository)
            ->with('success', 'Repository updated successfully.');
    }

    public function destroy(Repository $repository): RedirectResponse
    {
        if ($repository->slug === 'default') {
            return redirect()->route('repositories.index')
                ->with('error', 'The default repository cannot be deleted.');
        }

        $repository->delete();

        return redirect()->route('repositories.index')
            ->with('success', 'Repository deleted successfully.');
    }

    public function attachPackage(Repository $repository, Request $request): RedirectResponse
    {
        $request->validate([
            'package_id' => ['required', 'exists:packages,id'],
        ]);

        $repository->packages()->syncWithoutDetaching([$request->input('package_id')]);

        return redirect()->route('repositories.show', $repository)
            ->with('success', 'Package added to repository.');
    }

    public function detachPackage(Repository $repository, Package $package): RedirectResponse
    {
        $repository->packages()->detach($package->id);

        return redirect()->route('repositories.show', $repository)
            ->with('success', 'Package removed from repository.');
    }
}
