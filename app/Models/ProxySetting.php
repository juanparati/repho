<?php

namespace App\Models;

use App\Enums\Ecosystem;
use App\Enums\RepositoryAuthType;
use Database\Factories\ProxySettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'composer_enabled',
    'npm_enabled',
    'auth_type',
    'auth_username',
    'auth_password',
    'auth_token',
    'metadata_cache_ttl',
])]
class ProxySetting extends Model
{
    /** @use HasFactory<ProxySettingFactory> */
    use HasFactory;

    protected $table = 'proxy_settings';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'composer_enabled' => 'boolean',
            'npm_enabled' => 'boolean',
            'auth_type' => RepositoryAuthType::class,
            'auth_password' => 'encrypted',
            'auth_token' => 'encrypted',
            'metadata_cache_ttl' => 'integer',
        ];
    }

    public static function instance(): self
    {
        return self::firstOrCreate([], [
            'composer_enabled' => false,
            'npm_enabled' => false,
            'auth_type' => RepositoryAuthType::None,
            'metadata_cache_ttl' => 3600,
        ]);
    }

    public function isEnabled(Ecosystem $ecosystem = Ecosystem::Composer): bool
    {
        return match ($ecosystem) {
            Ecosystem::Composer => $this->composer_enabled,
            Ecosystem::Npm => $this->npm_enabled,
        };
    }
}
