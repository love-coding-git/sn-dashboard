<?php

namespace App\Jobs\Soma;

use App\Services\Soma\OwnerProfiler;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Trace one owner's chain history (family links, other subnets, previous SN114 hotkeys).
 */
class ProfileOwner implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 2;

    public function __construct(public string $coldkey)
    {
        $this->onQueue('owners');     // slow (Taostats rate limits) - off the watcher's queue, ahead of the earnings reads
    }

    public function uniqueId(): string
    {
        return $this->coldkey;
    }

    public function handle(OwnerProfiler $profiler): void
    {
        $profiler->profile($this->coldkey);
    }
}
