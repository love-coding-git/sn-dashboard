<?php

namespace App\Jobs\Sn34;

use App\Services\Sn34\Ingest;
use App\Services\Sn34\YugosClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Read the current policy and its competition board: seats, weights, floors, verdicts, queue states.
 */
class SyncBoard implements ShouldQueue
{
    use Queueable;

    public int $timeout = 150;

    public int $tries = 1;

    public function handle(YugosClient $client, Ingest $ingest): void
    {
        $current = $client->current();
        $policy = $current['policy_id'] ?? null;
        if (! $policy) {
            Log::warning('sn34: no policy_id in /competition/submissions ('.json_encode($current['_error'] ?? null).')');

            return;
        }
        $board = $client->board($policy);
        if (isset($board['_error'])) {
            return;
        }
        $events = $ingest->board($board);
        // the read time itself (a stored row changes only when the data changes), shown as the read age on the page
        Cache::put('sn34:read:board', now()->toIso8601String(), now()->addDays(2));
        Log::info('sn34: board '.($board['confirmed_at'] ?? '?').', '.count($board['challenges'] ?? []).' challenges, '.$events->count().' events');
    }
}
