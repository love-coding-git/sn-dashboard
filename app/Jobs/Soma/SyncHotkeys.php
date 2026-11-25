<?php

namespace App\Jobs\Soma;

use App\Services\Soma\Hotkeys;
use App\Services\Soma\Taostats;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Read the netuid-114 metagraph from Taostats (the app's own API call) and store the state of our hotkeys for the Hotkeys
 * page: uid, emission, registration block and the place in the deregistration queue. Until 2026-11-25 this ran a bittensor
 * chain read in the research workspace; the deployed dashboard does it itself.
 */
class SyncHotkeys implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function handle(Hotkeys $hotkeys, Taostats $taostats): void
    {
        $netuid = (int) config('soma.netuid');
        $rows = $taostats->metagraph($netuid);
        if (count($rows) < 64) {
            // a short or failed read never replaces the snapshot (the subnet has 256 uids)
            Log::warning('soma: hotkeys - the metagraph came back with '.count($rows).' uids - next poll retries');

            return;
        }
        $neurons = collect($rows)->map(fn (array $n) => [
            'uid' => (int) ($n['uid'] ?? 0),
            'hotkey' => Taostats::ss58($n['hotkey'] ?? null),
            'coldkey' => Taostats::ss58($n['coldkey'] ?? null),
            'emission' => (int) round((float) ($n['emission'] ?? 0)),
            'registered_at_block' => (int) ($n['registered_at_block'] ?? 0),
            // a validator permit goes to the uids with the most stake, miners included; only a uid earning dividends validates
            'validator' => (bool) ($n['validator_permit'] ?? false),
            'validating' => (bool) ($n['validator_permit'] ?? false) && (float) ($n['dividends'] ?? 0) > 0,
        ])->filter(fn ($n) => $n['hotkey'] !== '')->values()->all();

        $snapshot = $hotkeys->record([
            'netuid' => $netuid,
            'block' => (int) collect($rows)->max('block_number'),
            'immunity_period' => (int) config('soma.immunity_period', 5000),
            'neurons' => $neurons,
            'ours' => (array) config('soma.our_hotkeys', []),
            'notes' => (array) config('soma.hotkey_notes', []),
        ]);
        Log::info("soma: hotkeys snapshot block {$snapshot->block}, {$snapshot->uids} uids, {$snapshot->zero_emission} without emission");
    }
}
