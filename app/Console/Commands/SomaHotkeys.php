<?php

namespace App\Console\Commands;

use App\Jobs\Soma\SyncHotkeys;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('soma:hotkeys {--now : run here instead of on the queue}')]
#[Description('Read our SN114 hotkeys from the chain once (the scheduler does this every few minutes)')]
class SomaHotkeys extends Command
{
    public function handle(): int
    {
        $this->option('now') ? dispatch_sync(new SyncHotkeys) : dispatch(new SyncHotkeys);
        $this->info($this->option('now') ? 'read' : 'queued');

        return self::SUCCESS;
    }
}
