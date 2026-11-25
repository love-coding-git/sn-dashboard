<?php

namespace App\Console\Commands;

use App\Models\Sn34\Card;
use App\Models\Sn34\Neuron;
use App\Models\Sn34\Owner;
use App\Models\Sn34\Run;
use App\Models\Sn34\SeatChange;
use App\Models\Sn34\Wallet;
use App\Services\Sn34\Ingest;
use App\Services\Sn34\OwnerTracer;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Load the SN34 history that the public API no longer gives: every run the NPA/34 watch saved since 2026-10-22 with its
 * newest results, every saved board (verdicts, seat changes since 2026-10-28), the world cards, the 2026-11-25 family map
 * (owners, transfers, wallet sizes) and the metagraph of that read. Written by NPA/34 tools/sn34_dashboard_feed.py
 * --import-bundle. Idempotent; no events are recorded for imported history.
 */
class Sn34Import extends Command
{
    protected $signature = 'sn34:import {file? : the import bundle (default config sn34.import_file)}';

    protected $description = 'Load the saved SN34 history from the NPA/34 import bundle';

    public function handle(Ingest $ingest): int
    {
        $file = $this->argument('file') ?: config('sn34.import_file');
        $b = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (! is_array($b) || ($b['schema_version'] ?? null) !== 1) {
            $this->error("not an SN34 import bundle (schema 1): $file");

            return self::FAILURE;
        }

        $ingest->history($b['runs'] ?? [], false);
        $n = 0;
        foreach ($b['runs'] ?? [] as $r) {
            if (! empty($r['progress'])) {
                Run::where('run_id', $r['run_id'])->update([
                    'progress' => json_encode($r['progress']), 'complete' => (bool) ($r['progress']['complete'] ?? false),
                    'progress_at' => isset($r['progress_at']) ? Carbon::parse($r['progress_at']) : null,
                ]);
                $n++;
            }
        }
        $this->line(count($b['runs'] ?? []).' runs, '.$n.' with results');

        foreach ($b['boards'] ?? [] as $board) {
            $ingest->board($board, false, isset($board['_read_at']) ? Carbon::parse($board['_read_at']) : null);
        }
        // seat changes in confirmed order (boards were read in file order; rebuild from the bundle's own list)
        if (! empty($b['seat_changes'])) {
            SeatChange::query()->delete();
            foreach ($b['seat_changes'] as $s) {
                SeatChange::create(['from_at' => Carbon::parse($s['from']), 'policy_id' => $s['policy'] ?? null, 'seats' => $s['seats'], 'weights' => $s['weights'] ?? []]);
            }
        }
        $this->line(count($b['boards'] ?? []).' boards, '.count($b['seat_changes'] ?? []).' seat changes');

        $choices = Ingest::ourChoices();
        foreach ($b['cards'] ?? [] as $c) {
            if (! Card::where('sha256', $c['sha256'])->exists()) {
                Card::create(['sha256' => $c['sha256'], 'synced_at' => isset($c['synced_at']) ? Carbon::parse($c['synced_at']) : null,
                    'source' => $c['source'] ?? null, 'our_choices' => $c['our_choices'] ?? [], 'card' => null, 'read_at' => now()]);
            }
        }
        if (! empty($b['card_latest'])) {
            $ingest->card($b['card_latest'], $choices, false);
        }
        $this->line(count($b['cards'] ?? []).' cards');

        foreach ($b['owners'] ?? [] as $o) {
            Owner::updateOrCreate(['coldkey' => $o['coldkey']], [
                'hotkeys' => $o['hotkeys'] ?? [], 'owned_hotkeys' => $o['owned_hotkeys'] ?? null, 'balance_tao' => $o['balance_tao'] ?? null,
                'transfers_total' => $o['transfers_total'] ?? null,
                'profiled_at' => isset($o['profiled_at']) ? Carbon::parse($o['profiled_at']) : null,
            ]);
        }
        foreach ($b['transfers'] ?? [] as $t) {
            OwnerTracer::store($t);
        }
        foreach ($b['wallets'] ?? [] as $address => $count) {
            Wallet::record($address, $count);
        }
        if (! empty($b['metagraph']) && ! Neuron::exists()) {
            foreach ($b['metagraph'] as $n) {
                Neuron::create(['uid' => $n['uid'], 'hotkey' => $n['hotkey'], 'coldkey' => $n['coldkey'], 'registered_at' => null,
                    'incentive' => $n['incentive'] ?? null, 'emission' => $n['emission'] ?? null,
                    'read_at' => isset($b['metagraph_read_at']) ? Carbon::parse($b['metagraph_read_at']) : now()]);
            }
        }
        foreach (Neuron::pluck('coldkey', 'hotkey') as $hotkey => $coldkey) {
            Run::where('hotkey', $hotkey)->whereNull('coldkey')->update(['coldkey' => $coldkey]);
        }
        foreach ($b['owners'] ?? [] as $o) {
            Run::whereIn('hotkey', $o['hotkeys'] ?? [])->whereNull('coldkey')->update(['coldkey' => $o['coldkey']]);
        }
        $this->line(count($b['owners'] ?? []).' owners, '.count($b['transfers'] ?? []).' transfers, '.count($b['wallets'] ?? []).' wallets');

        return self::SUCCESS;
    }
}
