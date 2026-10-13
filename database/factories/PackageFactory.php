<?php

namespace Database\Factories;

use App\Enums\Ecosystem;
use App\Enums\PackageType;
use App\Models\Package;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Package>
 */
class PackageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $vendor = fake()->slug(1);
        $name = fake()->slug(1);

        return [
            'name' => $vendor.'/'.$name,
            'ecosystem' => Ecosystem::Composer,
            'repository_url' => 'https://github.com/'.$vendor.'/'.$name,
            'type' => PackageType::GitHub,
            'credential_id' => null,
            'download_dists' => false,
            'description' => fake()->sentence(),
            'last_synced_at' => null,
            'sync_error' => null,
        ];
    }

    public function github(): static
    {
        return $this->state(function (array $attributes) {
            $name = ltrim($attributes['name'], '@');

            return [
                'type' => PackageType::GitHub,
                'repository_url' => 'https://github.com/'.$name,
            ];
        });
    }

    public function gitlab(): static
    {
        return $this->state(function (array $attributes) {
            $name = ltrim($attributes['name'], '@');

            return [
                'type' => PackageType::GitLab,
                'repository_url' => 'https://gitlab.com/'.$name,
            ];
        });
    }

    public function git(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'type' => PackageType::Git,
            ];
        });
    }

    public function npm(?string $name = null): static
    {
        return $this->state(fn (array $attributes) => [
            'ecosystem' => Ecosystem::Npm,
            'name' => $name ?? '@'.$attributes['name'],
        ]);
    }

    public function synced(): static
    {
        return $this->state([
            'last_synced_at' => now(),
            'sync_error' => null,
        ]);
    }

    public function syncing(): static
    {
        return $this->state([
            'is_syncing' => true,
        ]);
    }

    public function withDistDownloads(): static
    {
        return $this->state([
            'download_dists' => true,
        ]);
    }

    public function withSyncError(string $error = 'Sync failed'): static
    {
        return $this->state([
            'sync_error' => $error,
        ]);
    }
}
