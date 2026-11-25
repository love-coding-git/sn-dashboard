<?php

namespace App\Console\Commands;

use App\Jobs\Soma\SyncLeaderboard;
use App\Jobs\Soma\SyncMetagraph;
use App\Jobs\Soma\SyncMinerTasks;
use App\Models\Miner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('soma:sync {--comp= : competition id (default config soma.competition)} {--tasks : queue a task refresh for every miner} {--now : run here instead of on the queue}')]
#[Description('Poll the SOMA leaderboard and the netuid-114 metagraph once (the scheduler does this every few minutes)')]
class SomaSync extends Command
{
    public function handle(): int
    {
        $comp = (int) ($this->option('comp') ?: config('soma.competition'));
        $run = fn ($job) => $this->option('now') ? dispatch_sync($job) : dispatch($job);
        $run(new SyncMetagraph);
        $run(new SyncLeaderboard($comp));
        if ($this->option('tasks')) {
            Miner::where('competition_id', $comp)->pluck('hotkey')
                ->each(fn ($hk, $i) => SyncMinerTasks::dispatch($comp, $hk)->delay(now()->addSeconds(3 * $i)));
        }
        $this->info($this->option('now') ? 'synced' : 'queued');

        return self::SUCCESS;
    }
}
