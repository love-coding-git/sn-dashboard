<?php

namespace App\Jobs\Sn34;

use App\Services\Sn34\OwnerTracer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Trace one SN34 owner's money (Taostats). Runs on the lowest-priority queue so the SOMA owner jobs go first.
 */
class TraceOwner implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 2;

    public function __construct(public string $coldkey)
    {
        $this->onQueue((string) config('sn34.trace_queue', 'profile'));
    }

    public function uniqueId(): string
    {
        return 'sn34:'.$this->coldkey;
    }

    public function handle(OwnerTracer $tracer): void
    {
        $tracer->trace($this->coldkey);
    }
}
