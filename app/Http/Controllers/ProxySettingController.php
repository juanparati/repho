<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateProxySettingRequest;
use App\Models\ProxySetting;
use App\Models\ProxyUpstream;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ProxySettingController extends Controller
{
    public function edit(): Response
    {
        $settings = ProxySetting::instance();

        return Inertia::render('proxy/edit', [
            'settings' => [
                'composer_enabled' => $settings->composer_enabled,
                'npm_enabled' => $settings->npm_enabled,
                'auth_type' => $settings->auth_type->value,
                'auth_username' => $settings->auth_username,
                'metadata_cache_ttl' => $settings->metadata_cache_ttl,
            ],
            'upstreams' => ProxyUpstream::query()
                ->orderBy('sort_order')
                ->get()
                ->map(fn (ProxyUpstream $upstream) => [
                    'id' => $upstream->id,
                    'enabled' => $upstream->enabled,
                    'ecosystem' => $upstream->ecosystem->value,
                    'name' => $upstream->name,
                    'upstream_url' => $upstream->upstream_url,
                    'auth_type' => $upstream->auth_type->value,
                    'sort_order' => $upstream->sort_order,
                    'created_at' => $upstream->created_at->toDateTimeString(),
                ]),
        ]);
    }

    public function update(UpdateProxySettingRequest $request): RedirectResponse
    {
        $settings = ProxySetting::instance();
        $settings->update($request->validated());

        return redirect()->route('proxy.edit')
            ->with('success', 'Proxy settings updated successfully.');
    }
}
