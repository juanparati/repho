<?php

namespace Database\Factories;

use App\Enums\RepositoryAuthType;
use App\Models\ProxySetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProxySetting>
 */
class ProxySettingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'composer_enabled' => false,
            'npm_enabled' => false,
            'auth_type' => RepositoryAuthType::None,
            'auth_username' => null,
            'auth_password' => null,
            'auth_token' => null,
            'metadata_cache_ttl' => 3600,
        ];
    }

    /**
     * Enable the proxy for every ecosystem.
     */
    public function enabled(): static
    {
        return $this->state([
            'composer_enabled' => true,
            'npm_enabled' => true,
        ]);
    }

    public function disabled(): static
    {
        return $this->state([
            'composer_enabled' => false,
            'npm_enabled' => false,
        ]);
    }

    public function composerEnabled(): static
    {
        return $this->state([
            'composer_enabled' => true,
        ]);
    }

    public function npmEnabled(): static
    {
        return $this->state([
            'npm_enabled' => true,
        ]);
    }

    public function basicAuth(string $username = 'user', string $password = 'secret'): static
    {
        return $this->state([
            'auth_type' => RepositoryAuthType::Basic,
            'auth_username' => $username,
            'auth_password' => $password,
        ]);
    }

    public function tokenAuth(string $token = 'test-token-123'): static
    {
        return $this->state([
            'auth_type' => RepositoryAuthType::Token,
            'auth_token' => $token,
        ]);
    }
}
