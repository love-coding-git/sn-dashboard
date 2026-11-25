<?php

namespace App\Jobs\Sn34;

use App\Services\Sn34\Ingest;
use App\Services\Sn34\YugosClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Read the active world card; store it once per sha256 and check our route choices on it (presence, api format,
 * price). A format change with no other diff hid an api_formats move once (NPA/34 REGRESSIONS 157).
 */
class SyncCard implements ShouldQueue
{
    use Queueable;

    public int $timeout = 90;

    public int $tries = 1;

    public function handle(YugosClient $client, Ingest $ingest): void
    {
        $card = $client->card();
        if (! isset($card['_error'])) {
            $ingest->card($card, Ingest::ourChoices());
            // the read time itself (a stored row changes only when the data changes), shown as the read age on the page
            Cache::put('sn34:read:card', now()->toIso8601String(), now()->addDays(2));
        }
    }
}
