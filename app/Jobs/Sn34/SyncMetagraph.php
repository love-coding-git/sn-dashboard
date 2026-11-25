<?php

namespace App\Jobs\Sn34;

use App\Models\Sn34\Owner;
use App\Models\Sn34\Run;
use App\Services\Sn34\Ingest;
use App\Services\Soma\Taostats;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Read the netuid-34 metagraph (Taostats, the app's one client) to link hotkeys to coldkeys, then queue owner traces
 * for the coldkeys that submitted in the current pack and are new or stale (a few per read, lowest-priority queue).
 */
class SyncMetagraph implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function handle(Taostats $taostats, Ingest $ingest): void
    {
        if ($ingest->metagraph($taostats->metagraph((int) config('sn34.netuid'))) > 0) {
            // the read time itself (a stored row changes only when the data changes), shown as the read age on the page
            Cache::put('sn34:read:metagraph', now()->toIso8601String(), now()->addDays(2));
        }

        $pack = Run::whereNotNull('pack')->orderByDesc('enqueued_at')->value('pack');
        $current = Run::where('pack', $pack)->whereNotNull('coldkey')->distinct()->pluck('coldkey');
        foreach ($current as $coldkey) {
            Owner::firstOrCreate(['coldkey' => $coldkey]);
        }
        $todo = Owner::whereIn('coldkey', $current)->whereNull('profiled_at')->limit(3)->pluck('coldkey')
            ->merge(Owner::whereIn('coldkey', $current)->where('profiled_at', '<', now()->subDays((float) config('sn34.reprofile_days', 1)))
                ->orderBy('profiled_at')->limit(1)->pluck('coldkey'))
            ->unique()->values();
        foreach ($todo as $i => $coldkey) {
            TraceOwner::dispatch($coldkey)->delay(now()->addMinutes(2 * $i));
        }
    }
}
