<?php

namespace Database\Factories;

use App\Enums\Ecosystem;
use App\Enums\RepositoryAuthType;
use App\Models\ProxyUpstream;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProxyUpstream>
 */
class ProxyUpstreamFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'enabled' => true,
            'ecosystem' => Ecosystem::Composer,
            'name' => fake()->unique()->words(2, true),
            'upstream_url' => 'https://repo.packagist.org',
            'auth_type' => RepositoryAuthType::None,
            'auth_username' => null,
            'auth_password' => null,
            'auth_token' => null,
            'sort_order' => 0,
        ];
    }

    public function npm(): static
    {
        return $this->state([
            'ecosystem' => Ecosystem::Npm,
            'upstream_url' => 'https://registry.npmjs.org',
        ]);
    }

    public function enabled(): static
    {
        return $this->state([
            'enabled' => true,
        ]);
    }

    public function disabled(): static
    {
        return $this->state([
            'enabled' => false,
        ]);
    }

    public function url(string $url): static
    {
        return $this->state([
            'upstream_url' => $url,
        ]);
    }

    public function sortOrder(int $order): static
    {
        return $this->state([
            'sort_order' => $order,
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
