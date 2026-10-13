<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProxyUpstreamRequest;
use App\Http\Requests\UpdateProxyUpstreamRequest;
use App\Models\ProxyUpstream;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ProxyUpstreamController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('proxy/upstreams/create');
    }

    public function store(StoreProxyUpstreamRequest $request): RedirectResponse
    {
        ProxyUpstream::create($request->validated());

        return redirect()->route('proxy.edit')
            ->with('success', 'Upstream added successfully.');
    }

    public function edit(ProxyUpstream $upstream): Response
    {
        return Inertia::render('proxy/upstreams/edit', [
            'upstream' => [
                'id' => $upstream->id,
                'enabled' => $upstream->enabled,
                'ecosystem' => $upstream->ecosystem->value,
                'name' => $upstream->name,
                'upstream_url' => $upstream->upstream_url,
                'auth_type' => $upstream->auth_type->value,
                'auth_username' => $upstream->auth_username,
                'sort_order' => $upstream->sort_order,
            ],
        ]);
    }

    public function update(UpdateProxyUpstreamRequest $request, ProxyUpstream $upstream): RedirectResponse
    {
        $upstream->update($request->validated());

        return redirect()->route('proxy.edit')
            ->with('success', 'Upstream updated successfully.');
    }

    public function destroy(ProxyUpstream $upstream): RedirectResponse
    {
        $upstream->delete();

        return redirect()->route('proxy.edit')
            ->with('success', 'Upstream removed successfully.');
    }
}
