<?php

namespace App\Jobs\Soma;

use App\Models\Competition;
use App\Models\Miner;
use App\Models\Owner;
use App\Services\Soma\DashboardClient;
use App\Services\Soma\Ingest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Poll one competition's leaderboard, record events, and queue a task refresh for every miner that changed,
 * is still in screening, or has never been read.
 */
class SyncLeaderboard implements ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public int $tries = 2;

    public function __construct(public int $competition) {}

    public function handle(DashboardClient $client, Ingest $ingest): void
    {
        $rows = $client->leaderboard($this->competition);
        if (! $rows) {
            Log::warning("soma: leaderboard {$this->competition} came back empty (CloudFront?) - next poll retries");

            return;
        }
        $competition = Competition::ensure($this->competition);
        $detect = Miner::where('competition_id', $competition->id)->exists();
        // a scored entry can still change: the platform re-runs failed runs after scoring (round 11, 2026-10-15)
        $scoredBefore = Miner::where('competition_id', $competition->id)->where('status', 'scored')->pluck('overall', 'hotkey');
        $events = $ingest->leaderboard($competition, $rows, $detect);
        $moved = Miner::where('competition_id', $competition->id)->where('status', 'scored')->get(['hotkey', 'overall'])
            ->filter(fn ($m) => $scoredBefore->has($m->hotkey) && abs((float) $scoredBefore[$m->hotkey] - (float) $m->overall) > 1e-9)
            ->pluck('hotkey');

        $refresh = $events->whereIn('type', ['upload', 'stage1_done', 'status'])->pluck('hotkey')
            ->merge(Miner::where('competition_id', $competition->id)
                ->where(fn ($q) => $q->whereNull('tasks_synced_at')->orWhereIn('status', ['screening', 'in queue']))
                ->pluck('hotkey'))
            // entries under evaluation (stage 2: qualifying; full evaluation: qualified / evaluating): their SOMA tasks
            // fill in over days - the 20 stalest pages per poll, so each of the ~60 advanced entries about every 15 min
            ->merge(Miner::where('competition_id', $competition->id)->whereIn('status', ['qualifying', 'qualified', 'evaluating'])
                ->where(fn ($q) => $q->whereNull('tasks_synced_at')->orWhere('tasks_synced_at', '<', now()->subMinutes(15)))
                ->orderBy('tasks_synced_at')->limit(20)->pluck('hotkey'))
            // scored entries: when their overall moves, and at least hourly in case a re-run leaves it unchanged
            ->merge($moved)
            ->merge(Miner::where('competition_id', $competition->id)->where('status', 'scored')
                ->where(fn ($q) => $q->whereNull('tasks_synced_at')->orWhere('tasks_synced_at', '<', now()->subMinutes(60)))
                ->orderBy('tasks_synced_at')->limit(10)->pluck('hotkey'))
            ->unique()->values();
        foreach ($refresh as $i => $hotkey) {
            SyncMinerTasks::dispatch($this->competition, $hotkey)->delay(now()->addSeconds(3 * $i));
        }
        $owners = Miner::where('competition_id', $competition->id)->whereIn('hotkey', $events->pluck('hotkey'))
            ->whereNotNull('coldkey')->pluck('coldkey')->unique()
            ->reject(fn ($c) => Owner::where('coldkey', $c)->whereNotNull('profiled_at')->exists())->values();
        foreach ($owners as $i => $coldkey) {
            ProfileOwner::dispatch($coldkey)->delay(now()->addMinutes(1 + $i));
        }
        // entries without an owner (hotkey pruned before a metagraph poll saw it): look the owner up now, not 10 minutes later
        ResolveOwner::queueMissing(10, $this->competition);
        Log::info("soma: leaderboard {$this->competition}: ".count($rows).' rows, '.$events->count().' events, '.$refresh->count().' task refreshes queued');
    }
}
