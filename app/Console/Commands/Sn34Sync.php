<?php

namespace App\Console\Commands;

use App\Jobs\Sn34\SyncBoard;
use App\Jobs\Sn34\SyncCard;
use App\Jobs\Sn34\SyncMetagraph;
use App\Jobs\Sn34\SyncRuns;
use Illuminate\Console\Command;

class Sn34Sync extends Command
{
    protected $signature = 'sn34:sync {--now : run the reads in this process instead of queueing them}';

    protected $description = 'Read the Yugos board, runs, world card and the netuid-34 metagraph once';

    public function handle(): int
    {
        foreach ([new SyncBoard, new SyncRuns, new SyncCard, new SyncMetagraph] as $job) {
            $this->option('now') ? dispatch_sync($job) : dispatch($job->onQueue('sn34'));
            $this->line(class_basename($job).($this->option('now') ? ' done' : ' queued'));
        }

        return self::SUCCESS;
    }
}
