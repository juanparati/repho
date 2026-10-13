<?php

namespace App\Models;

use App\Enums\Ecosystem;
use App\Enums\PackageType;
use Database\Factories\PackageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'ecosystem', 'repository_url', 'type', 'credential_id', 'download_dists', 'description', 'last_synced_at', 'sync_error', 'is_syncing', 'sync_progress'])]
class Package extends Model
{
    /** @use HasFactory<PackageFactory> */
    use HasFactory;

    /**
     * Valid npm package names: optional @scope/ prefix, lowercase, URL-safe,
     * never starting with a dot, underscore or dash.
     */
    public const string NPM_NAME_PATTERN = '(@[a-z0-9][a-z0-9._-]*\/)?[a-z0-9][a-z0-9._-]*';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ecosystem' => Ecosystem::class,
            'type' => PackageType::class,
            'download_dists' => 'boolean',
            'is_syncing' => 'boolean',
            'sync_progress' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Version, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(Version::class);
    }

    /**
     * @return BelongsTo<Credential, $this>
     */
    public function credential(): BelongsTo
    {
        return $this->belongsTo(Credential::class);
    }

    /**
     * @return HasMany<SecurityAdvisory, $this>
     */
    public function securityAdvisories(): HasMany
    {
        return $this->hasMany(SecurityAdvisory::class);
    }

    /**
     * @return BelongsToMany<Repository, $this>
     */
    public function repositories(): BelongsToMany
    {
        return $this->belongsToMany(Repository::class);
    }

    public function isNpm(): bool
    {
        return $this->ecosystem === Ecosystem::Npm;
    }

    public function isComposer(): bool
    {
        return $this->ecosystem === Ecosystem::Composer;
    }

    /**
     * The vendor (Composer) or scope (npm) part of the name. Unscoped npm
     * packages return their own name.
     */
    public function vendor(): string
    {
        $vendor = explode('/', $this->name)[0];

        return basename($vendor);
    }

    public function shortName(): string
    {
        $name = explode('/', $this->name)[1] ?? $this->name;

        return basename($name);
    }

    /**
     * Whether npm tarballs must always be stored at sync time. Composer dists
     * are optional and lazily fetched, npm tarballs are built by Rephó and
     * advertised with an integrity hash, so they are always kept.
     */
    public function storesDistsOnSync(): bool
    {
        return $this->isNpm() || $this->download_dists;
    }
}
