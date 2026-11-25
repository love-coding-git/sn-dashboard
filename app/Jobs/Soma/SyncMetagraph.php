<?php

namespace App\Jobs\Soma;

use App\Models\Miner;
use App\Models\Owner;
use App\Services\Soma\Families;
use App\Services\Soma\Ingest;
use App\Services\Soma\Taostats;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Read the netuid-114 metagraph from Taostats: new hotkeys become registration events, and miners get their
 * coldkey and uid.
 */
class SyncMetagraph implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 2;

    public function handle(Taostats $taostats, Ingest $ingest, Families $families): void
    {
        $netuid = (int) config('soma.netuid');
        $rows = $taostats->metagraph($netuid);
        if (! $rows) {
            Log::warning('soma: metagraph came back empty - next poll retries');

            return;
        }
        $events = $ingest->metagraph($netuid, $rows, true);
        $families->refresh();
        // trace new owners first, then a few not-yet-traced owners of the current competition per run, then a few whose
        // trace is older than soma.reprofile_days (new transfers and registrations after the first trace link families)
        $current = Miner::where('competition_id', config('soma.competition'))->whereNotNull('coldkey')->distinct()->pluck('coldkey');
        $todo = $events->pluck('coldkey')->filter()
            ->merge(Owner::whereIn('coldkey', $current)->whereNull('profiled_at')->limit(5)->pluck('coldkey'))
            ->merge(Owner::whereIn('coldkey', $current)->where('profiled_at', '<', now()->subDays((float) config('soma.reprofile_days', 3)))
                ->orderBy('profiled_at')->limit(3)->pluck('coldkey'))
            ->unique()->values();
        foreach ($todo as $i => $coldkey) {
            ProfileOwner::dispatch($coldkey)->delay(now()->addMinutes($i));
        }
        // entries whose hotkey left the metagraph before a poll saw it: their owner comes from the registration record
        $resolve = ResolveOwner::queueMissing(10);
        Log::info('soma: metagraph '.count($rows).' uids, '.$events->count().' registrations, '.$todo->count().' owners queued for tracing, '
            .$resolve->count().' hotkeys queued for an owner lookup');
    }
}
