<?php

namespace App\Jobs\Soma;

use App\Services\Soma\Hotkeys;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Read the netuid-114 metagraph straight from the chain (scripts/chain_snapshot.py on the research workspace's Python,
 * a few seconds) and store the state of our hotkeys for the Hotkeys page.
 */
class SyncHotkeys implements ShouldQueue
{
    use Queueable;

    public int $timeout = 240;

    public int $tries = 1;

    public function handle(Hotkeys $hotkeys): void
    {
        $result = Process::timeout(180)->path(base_path())
            ->run([(string) config('soma.chain.python'), (string) config('soma.chain.script')]);
        if (! $result->successful()) {
            Log::warning('soma: chain snapshot failed - '.Str::limit(trim($result->errorOutput()), 400));

            return;
        }
        $data = json_decode($result->output(), true);
        if (! is_array($data) || empty($data['neurons']) || ! isset($data['ours'])) {
            Log::warning('soma: chain snapshot came back empty - next poll retries');

            return;
        }
        $snapshot = $hotkeys->record($data);
        Log::info("soma: chain snapshot block {$snapshot->block}, {$snapshot->uids} uids, {$snapshot->zero_emission} without emission");
    }
}
