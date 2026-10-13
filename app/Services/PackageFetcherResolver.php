<?php

namespace App\Services;

use App\Enums\PackageType;
use App\Models\Package;

class PackageFetcherResolver
{
    public function for(Package $package): PackageFetcher
    {
        return match ($package->type) {
            PackageType::GitHub => app(GitHubPackageFetcher::class),
            PackageType::GitLab => app(GitLabPackageFetcher::class),
            PackageType::Git => app(GitPackageFetcher::class),
        };
    }
}
