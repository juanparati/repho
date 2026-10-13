<?php

namespace App\Jobs;

use App\Models\Version;
use App\Services\DistStorage;
use App\Services\PackageFetcherResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DownloadVersionDist implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public Version $version,
    ) {}

    public function handle(DistStorage $dists, PackageFetcherResolver $fetchers): void
    {
        $package = $this->version->package;

        if ($dists->exists($package, $this->version->reference)) {
            return;
        }

        $dists->store($package, $this->version, $fetchers->for($package));
    }
}
