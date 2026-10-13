<?php

namespace App\Models;

use App\Enums\Ecosystem;
use App\Enums\RepositoryAuthType;
use Database\Factories\ProxyUpstreamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'enabled',
    'ecosystem',
    'name',
    'upstream_url',
    'auth_type',
    'auth_username',
    'auth_password',
    'auth_token',
    'sort_order',
])]
class ProxyUpstream extends Model
{
    /** @use HasFactory<ProxyUpstreamFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'ecosystem' => Ecosystem::class,
            'auth_type' => RepositoryAuthType::class,
            'auth_password' => 'encrypted',
            'auth_token' => 'encrypted',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Enabled upstreams of one ecosystem, highest priority first.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActiveFor(Builder $query, Ecosystem $ecosystem): Builder
    {
        return $query
            ->where('enabled', true)
            ->where('ecosystem', $ecosystem)
            ->orderBy('sort_order');
    }
}
