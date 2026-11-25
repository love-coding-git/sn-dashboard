<?php

namespace App\Jobs\Soma;

use App\Models\Miner;
use App\Services\Soma\DashboardClient;
use App\Services\Soma\Ingest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Read one miner's dashboard page (tasks and runs) into the database and refresh its fingerprint.
 */
class SyncMinerTasks implements ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public int $competition, public string $hotkey) {}

    public function handle(DashboardClient $client, Ingest $ingest): void
    {
        $miner = Miner::where('competition_id', $this->competition)->where('hotkey', $this->hotkey)->first();
        if (! $miner) {
            return;
        }
        $ingest->tasks($miner, $client->minerTasks($this->competition, $this->hotkey));
    }
}
