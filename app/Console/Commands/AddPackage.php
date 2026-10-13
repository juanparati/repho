<?php

namespace App\Console\Commands;

use App\Enums\Ecosystem;
use App\Enums\PackageType;
use App\Models\Credential;
use App\Models\Package;
use App\Models\Repository;
use App\Services\PackageSynchronizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('package:add {url : Repository URL} {--type= : Source type (github, gitlab, git)} {--ecosystem=composer : Package ecosystem (composer, npm)} {--name= : Package name, required for npm (as declared in package.json)} {--credential= : Credential name or ID} {--repo=* : Repository slug(s) to add the package to}')]
#[Description('Add a new package from a repository URL')]
class AddPackage extends Command
{
    public function handle(PackageSynchronizer $synchronizer): int
    {
        $url = $this->argument('url');
        $type = $this->resolveType($url);

        if (! $type) {
            $this->error('Could not determine package type. Use --type to specify.');

            return self::FAILURE;
        }

        $ecosystem = Ecosystem::tryFrom((string) $this->option('ecosystem'));

        if (! $ecosystem) {
            $this->error('Unknown ecosystem. Use --ecosystem=composer or --ecosystem=npm.');

            return self::FAILURE;
        }

        $name = $ecosystem === Ecosystem::Npm ? (string) $this->option('name') : $this->guessPackageName($url);

        if ($ecosystem === Ecosystem::Npm && preg_match('/^'.Package::NPM_NAME_PATTERN.'$/', $name) !== 1) {
            $this->error('npm packages need a valid --name, e.g. --name=@scope/package.');

            return self::FAILURE;
        }

        $credential = $this->resolveCredential();

        $package = Package::create([
            'name' => $name,
            'ecosystem' => $ecosystem,
            'repository_url' => $url,
            'type' => $type,
            'credential_id' => $credential?->id,
        ]);

        $repoSlugs = $this->option('repo') ?: ['default'];
        $repos = Repository::whereIn('slug', $repoSlugs)->pluck('id');

        if ($repos->isNotEmpty()) {
            $package->repositories()->attach($repos);
            $this->info('Added to repositories: '.implode(', ', $repoSlugs));
        }

        $this->info("Package created: {$package->name}");
        $this->info('Syncing...');

        try {
            $synchronizer->sync($package);

            $package->refresh();

            $versionCount = $package->versions()->count();
            $this->info("Synced successfully. {$versionCount} version(s) found.");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Sync failed: {$e->getMessage()}");
            $this->info("Package was created but sync failed. You can retry with: php artisan package:sync {$package->name}");

            return self::FAILURE;
        }
    }

    private function resolveType(string $url): ?PackageType
    {
        if ($type = $this->option('type')) {
            return PackageType::tryFrom($type);
        }

        $host = parse_url($url, PHP_URL_HOST) ?? '';

        if (str_contains($host, 'github.com')) {
            return PackageType::GitHub;
        }

        if (str_contains($host, 'gitlab.com') || str_contains($host, 'gitlab')) {
            return PackageType::GitLab;
        }

        return PackageType::Git;
    }

    private function resolveCredential(): ?Credential
    {
        $identifier = $this->option('credential');

        if (! $identifier) {
            return null;
        }

        return Credential::where('id', $identifier)
            ->orWhere('name', $identifier)
            ->first();
    }

    private function guessPackageName(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $path = trim($path, '/');
        $path = preg_replace('/\.git$/', '', $path);

        return strtolower($path);
    }
}
