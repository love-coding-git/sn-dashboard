<?php

namespace App\Services\Sn34;

use App\Models\Sn34\Neuron;
use App\Models\Sn34\Owner;
use App\Models\Sn34\Run;
use App\Models\Sn34\Transfer;
use App\Models\Sn34\Wallet;
use App\Services\Soma\Taostats;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Trace one SN34 owner (coldkey) on Taostats: balance, TAO transfers (the newest page and the oldest ones), and the size
 * of every counterparty wallet it shares with another SN34 owner. Uses the app's one Taostats client, so its pacing is
 * shared with the SOMA jobs (the same TAO_API_KEY also serves NPA/34 tools).
 */
class OwnerTracer
{
    public function __construct(private Taostats $taostats) {}

    public function trace(string $coldkey): void
    {
        $ok = function (array $res): array {
            if (isset($res['_error'])) {
                throw new RuntimeException('taostats: '.$res['_error']);
            }

            return $res;
        };
        $hotkeys = Neuron::where('coldkey', $coldkey)->pluck('hotkey')
            ->merge(Run::where('coldkey', $coldkey)->distinct()->pluck('hotkey'))->unique()->values()->all();
        $owner = Owner::firstOrCreate(['coldkey' => $coldkey]);
        $account = $ok($this->taostats->get('/v1/accounts/'.$coldkey))['data'][0] ?? [];

        $first = $ok($this->taostats->get('/v1/transfers', ['address' => $coldkey, 'limit' => 200]));
        $rows = $first['data'] ?? [];
        $pages = (int) ($first['pagination']['total_pages'] ?? 1);
        $max = (int) config('sn34.trace_pages', 3);
        for ($p = $pages; $p >= 2 && $p > $pages - ($max - 1); $p--) {
            $rows = array_merge($rows, $this->taostats->get('/v1/transfers', ['address' => $coldkey, 'limit' => 200, 'page' => $p])['data'] ?? []);
        }
        foreach ($rows as $t) {
            self::store($t);
        }
        $owner->update([
            'hotkeys' => $hotkeys,
            'balance_tao' => isset($account['balance_total']) ? ((float) $account['balance_total']) / 1e9 : $owner->balance_tao,
            'transfers_total' => $first['pagination']['total_items'] ?? null,
            'profiled_at' => now(),
        ]);
        Wallet::record($coldkey, $first['pagination']['total_items'] ?? null);

        $lookups = 0;
        foreach (app(Families::class)->sharedWallets() as $w) {
            if ($lookups >= (int) config('sn34.wallet_lookups', 10)) {
                break;
            }
            if (! in_array($coldkey, $w['owners'], true) || $w['transfers'] !== null) {
                continue;
            }
            $count = $this->taostats->get('/v1/transfers', ['address' => $w['address'], 'limit' => 1])['pagination']['total_items'] ?? null;
            Wallet::record($w['address'], $count);
            $lookups++;
        }
    }

    /**
     * @param  array<string, mixed>  $t  one Taostats transfer
     */
    public static function store(array $t): void
    {
        $id = $t['id'] ?? null;
        $from = Ingest::ss58($t['from'] ?? null);
        $to = Ingest::ss58($t['to'] ?? null);
        if (! $id || $from === '' || $to === '') {
            return;
        }
        Transfer::firstOrCreate(['external_id' => (string) $id], [
            'from_address' => $from, 'to_address' => $to, 'amount_tao' => ((float) ($t['amount'] ?? 0)) / 1e9,
            'block_number' => $t['block_number'] ?? null,
            'transferred_at' => isset($t['timestamp']) ? Carbon::parse($t['timestamp'])->utc() : null,
        ]);
    }
}
