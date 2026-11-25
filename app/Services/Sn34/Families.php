<?php

namespace App\Services\Sn34;

use App\Models\Sn34\Owner;
use App\Models\Sn34\Transfer;
use App\Models\Sn34\Wallet;

/**
 * SN34 operator families from the traced owners. Owners joined by a direct TAO transfer (or merged by hand in
 * config sn34.family_merges) form one family. A wallet two owners both traded with is evidence only: personal (few
 * transfers) is shown as a link to check, exchange-like is ignored. Owners with no money link are "link unconfirmed",
 * never "different operators" (NPA rule).
 */
class Families
{
    /**
     * @return array<int, array{address: string, owners: array<int, string>, transfers: ?int, kind: ?string}>
     */
    public function sharedWallets(): array
    {
        $owners = Owner::pluck('coldkey')->all();
        if (count($owners) < 2) {
            return [];
        }
        $seen = [];
        foreach (Transfer::whereIn('from_address', $owners)->orWhereIn('to_address', $owners)->get(['from_address', 'to_address']) as $t) {
            foreach ([[$t->from_address, $t->to_address], [$t->to_address, $t->from_address]] as [$owner, $other]) {
                if (in_array($owner, $owners, true) && ! in_array($other, $owners, true)) {
                    $seen[$other][$owner] = true;
                }
            }
        }
        $wallets = Wallet::pluck('transfer_count', 'address');
        $kinds = Wallet::pluck('kind', 'address');
        $out = [];
        foreach ($seen as $address => $set) {
            if (count($set) >= 2) {
                $out[] = ['address' => $address, 'owners' => array_keys($set), 'transfers' => $wallets[$address] ?? null, 'kind' => $kinds[$address] ?? null];
            }
        }

        return $out;
    }

    /**
     * Family key per owner (the smallest coldkey of the group) and the evidence between owners.
     *
     * @return array{family: array<string, string>, direct: array<int, array<string, mixed>>, shared: array<int, array<string, mixed>>}
     */
    public function summary(): array
    {
        $owners = Owner::pluck('coldkey')->all();
        $parent = array_combine($owners, $owners) ?: [];
        $find = function (string $x) use (&$parent, &$find): string {
            return $parent[$x] === $x ? $x : ($parent[$x] = $find($parent[$x]));
        };
        $union = function (string $a, string $b) use (&$parent, $find): void {
            [$ra, $rb] = [$find($a), $find($b)];
            if ($ra !== $rb) {
                $parent[max($ra, $rb)] = min($ra, $rb);
            }
        };
        $direct = Transfer::whereIn('from_address', $owners)->whereIn('to_address', $owners)
            ->get(['from_address', 'to_address', 'amount_tao', 'transferred_at'])
            ->map(fn ($t) => ['from' => $t->from_address, 'to' => $t->to_address, 'tao' => $t->amount_tao, 'at' => $t->transferred_at?->toIso8601String()])
            ->all();
        foreach ($direct as $d) {
            $union($d['from'], $d['to']);
        }
        foreach (config('sn34.family_merges', []) as $group) {
            $group = array_values(array_filter($group, fn ($c) => isset($parent[$c])));
            for ($i = 1; $i < count($group); $i++) {
                $union($group[0], $group[$i]);
            }
        }
        $family = [];
        foreach ($owners as $o) {
            $family[$o] = $find($o);
        }
        $shared = array_values(array_filter($this->sharedWallets(), fn ($w) => $w['kind'] !== 'exchange-like'));

        return ['family' => $family, 'direct' => $direct, 'shared' => $shared];
    }
}
