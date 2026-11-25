<?php

namespace App\Jobs\Sn34;

use App\Models\Sn34\Run;
use App\Models\Sn34\Verdict;
use App\Services\Sn34\Ingest;
use App\Services\Sn34\YugosClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Read the run history (states, started_at changes), then the results progress of every run that is open: queued or
 * running, or failed / completed in the last two days without a verdict yet (failed is not the end - a run can turn
 * completed hours later and be judged).
 */
class SyncRuns implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    /** At most this many /competition/results calls (20 runs each) per run of the job; the rest wait for the next minute. */
    public const MAX_BATCHES = 10;

    public int $tries = 1;

    public function handle(YugosClient $client, Ingest $ingest): void
    {
        $history = $client->history();
        if (isset($history['_error'])) {
            return;
        }
        $events = $ingest->history($history['submissions'] ?? [], Run::exists());

        $judged = Verdict::pluck('run_id')->all();
        // queued / running first, then the newest unjudged ends, so a cap never starves the runs in progress
        $open = Run::whereIn('state', ['queued', 'running'])->orderByDesc('enqueued_at')->pluck('run_id')
            ->merge(Run::whereIn('state', ['failed', 'completed'])->where('finished_at', '>', now()->subDays(2))
                ->whereNotIn('run_id', $judged)->orderByDesc('finished_at')->pluck('run_id'))
            ->unique()->values()->all();
        $batches = array_chunk($open, (int) config('sn34.results_batch', 20));
        if (count($batches) > self::MAX_BATCHES) {
            Log::warning('sn34: '.(count($batches) - self::MAX_BATCHES).' results batches skipped this minute ('.count($open).' open runs)');
            $batches = array_slice($batches, 0, self::MAX_BATCHES);
        }
        foreach ($batches as $ids) {
            $res = $client->results($ids);
            if (! isset($res['_error'])) {
                $events = $events->merge($ingest->results($res['runs'] ?? []));
            }
        }
        // the read time itself (a stored row changes only when the data changes), shown as the read age on the page
        Cache::put('sn34:read:runs', now()->toIso8601String(), now()->addDays(2));
        Log::info('sn34: runs '.count($history['submissions'] ?? []).', open '.count($open).', '.$events->count().' events');
    }
}
