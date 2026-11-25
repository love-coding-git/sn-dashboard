<?php

namespace App\Services\Soma;

use App\Jobs\Soma\FetchEarnings;
use App\Models\Miner;
use App\Models\Owner;
use App\Models\OwnerRegistration;
use App\Models\Transfer;
use App\Models\Wallet;
use Illuminate\Support\Carbon;

/**
 * Profile one SN114 owner from Taostats: account age and balance, registrations on every subnet, TAO transfers, and
 * the kind of every wallet it shares with another owner. Then recompute the families.
 */
class OwnerProfiler
{
    /** Transfer pages read per owner: the newest page and the oldest ones. */
    public const MAX_PAGES = 5;

    public function __construct(private Taostats $taostats, private Families $families) {}

    public function profile(string $coldkey, int $maxWalletLookups = 25): void
    {
        $owner = Owner::ensure($coldkey);
        // a failed read stops the trace (the job retries later) instead of storing blanks and marking the owner traced
        $ok = function (array $res): array {
            if (isset($res['_error'])) {
                throw new \RuntimeException('taostats: '.$res['_error']);
            }

            return $res;
        };
        $account = $ok($this->taostats->get('/v1/accounts/'.$coldkey))['data'][0] ?? [];
        $owner->update([
            'created_on' => $account['created_on_date'] ?? null,
            'balance_tao' => isset($account['balance_total']) ? ((float) $account['balance_total']) / 1e9 : null,
        ]);

        $regs = $ok($this->taostats->get('/v1/subnets/neuron-registration-events', ['coldkey' => $coldkey, 'limit' => 200]));
        $sn114 = [];
        foreach ($regs['data'] ?? [] as $r) {
            $hotkey = Taostats::ss58($r['hotkey'] ?? null);
            if ($hotkey === '') {
                continue;
            }
            OwnerRegistration::updateOrCreate([
                'coldkey' => $coldkey,
                'netuid' => (int) ($r['netuid'] ?? 0),
                'hotkey' => $hotkey,
                'registered_at' => isset($r['timestamp']) ? Carbon::parse($r['timestamp']) : null,
            ], ['uid' => $r['uid'] ?? null, 'cost_tao' => isset($r['registration_cost']) ? ((float) $r['registration_cost']) / 1e9 : null]);
            if ((int) ($r['netuid'] ?? 0) === (int) config('soma.netuid')) {
                $sn114[] = $hotkey;
            }
        }
        if ($sn114) {
            Miner::whereIn('hotkey', $sn114)->whereNull('coldkey')->update(['coldkey' => $coldkey]);
        }

        // Taostats lists transfers newest first, 200 a page. Past 200 the funding that links families sits on the last pages
        // (5 of 67 round-13 owners had 207-434 transfers on 2026-11-11), so the oldest pages are read too, up to MAX_PAGES in all.
        $tr = $ok($this->taostats->get('/v1/transfers', ['address' => $coldkey, 'limit' => 200]));
        Wallet::record($coldkey, $tr['pagination']['total_items'] ?? null);
        $rows = $tr['data'] ?? [];
        $pages = (int) ($tr['pagination']['total_pages'] ?? 1);
        for ($p = $pages; $p >= 2 && $p > $pages - (self::MAX_PAGES - 1); $p--) {
            $rows = array_merge($rows, $this->taostats->get('/v1/transfers', ['address' => $coldkey, 'limit' => 200, 'page' => $p])['data'] ?? []);
        }
        foreach ($rows as $t) {
            $this->storeTransfer($t);
        }

        $owners = Owner::pluck('coldkey')->all();
        $lookups = 0;
        foreach ($this->families->sharedCounterparties($owners) as $shared) {
            if ($lookups >= $maxWalletLookups || ! in_array($coldkey, $shared['owners'], true) || $shared['transfers'] !== null) {
                continue;
            }
            $count = $this->taostats->get('/v1/transfers', ['address' => $shared['address'], 'limit' => 1])['pagination']['total_items'] ?? null;
            Wallet::record($shared['address'], $count);
            $lookups++;
        }
        $owner->update(['profiled_at' => now()]);
        $this->families->refresh();
        // earnings on the owner's other subnets (SN114 is read day by day for every owner at once)
        OwnerRegistration::where('coldkey', $coldkey)->where('netuid', '!=', config('soma.netuid'))->get()
            ->unique(fn ($r) => $r->netuid.':'.$r->hotkey)
            ->reject(fn ($r) => Earnings::fetched($r->netuid.':'.$r->hotkey))
            ->each(fn ($r) => FetchEarnings::dispatch($r->netuid, null, $r->hotkey));
    }

    /**
     * @param  array<string, mixed>  $t  one Taostats transfer
     */
    public static function storeTransfer(array $t): void
    {
        $id = $t['id'] ?? null;
        $from = Taostats::ss58($t['from'] ?? null);
        $to = Taostats::ss58($t['to'] ?? null);
        if (! $id || $from === '' || $to === '') {
            return;
        }
        Transfer::firstOrCreate(['external_id' => $id], [
            'from_address' => $from,
            'to_address' => $to,
            'amount_tao' => ((float) ($t['amount'] ?? 0)) / 1e9,
            'block_number' => $t['block_number'] ?? null,
            'transferred_at' => isset($t['timestamp']) ? Carbon::parse($t['timestamp']) : null,
        ]);
    }
}
