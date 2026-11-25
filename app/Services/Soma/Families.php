<?php

namespace App\Services\Soma;

use App\Models\Miner;
use App\Models\Owner;
use App\Models\Transfer;
use App\Models\Wallet;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Owner families. Strong links merge owners into one family: a direct transfer between two SN114 owners, a wallet of a kind
 * in config soma.family_link_wallet_kinds (personal, <= 300 transfers) that traded with two or more owners and funded at least
 * one of them (funding links, 2026-10-27 - a wallet they only paid into is evidence only), or a merge confirmed by hand in
 * soma-miner/rounds/round11/docs/RIVALS11.md (config soma.family_merges).
 * Exchange-like and medium wallets shared with another owner are shown as evidence only.
 */
class Families
{
    /**
     * Recompute every owner's family key and store it on the owners table.
     *
     * @return array<string, string> coldkey => family key
     */
    public function refresh(): array
    {
        $owners = Owner::pluck('coldkey')->all();
        $parent = array_combine($owners, $owners) ?: [];
        $find = function (string $x) use (&$parent, &$find): string {
            return $parent[$x] === $x ? $x : ($parent[$x] = $find($parent[$x]));
        };
        $union = function (string $a, string $b) use (&$parent, $find): void {
            [$ra, $rb] = [$find($a), $find($b)];
            if ($ra !== $rb) {
                $parent[$rb] = $ra;
            }
        };
        foreach ($this->directLinks($owners) as $link) {
            $union($link['a'], $link['b']);
        }
        $kinds = config('soma.family_link_wallet_kinds', []);
        foreach ($kinds ? $this->sharedCounterparties($owners) : [] as $shared) {
            // a wallet the owners only paid into (a seller, a service) is no funding link - it must have sent TAO to one of them
            if (in_array($shared['kind'], $kinds, true)
                && Transfer::where('from_address', $shared['address'])->whereIn('to_address', $shared['owners'])->exists()) {
                foreach (array_slice($shared['owners'], 1) as $c) {
                    $union($shared['owners'][0], $c);
                }
            }
        }
        $byPrefix = [];
        foreach ($owners as $c) {
            $byPrefix[substr($c, 0, 8)][] = $c;
        }
        foreach (config('soma.family_merges') as $from => $to) {
            foreach ($byPrefix[$from] ?? [] as $a) {
                foreach ($byPrefix[$to] ?? [] as $b) {
                    $union($a, $b);
                }
            }
        }
        $ours = Owner::where('label', 'ours')->pluck('coldkey')->all();
        foreach (array_slice($ours, 1) as $c) {
            $union($ours[0], $c);
        }
        $groups = [];
        foreach ($owners as $c) {
            $groups[$find($c)][] = $c;
        }
        $targets = array_values(config('soma.family_merges'));
        $entries = Miner::whereIn('coldkey', $owners)->selectRaw('coldkey, count(*) as n')->groupBy('coldkey')->pluck('n', 'coldkey');
        $familyOf = [];
        foreach ($groups as $members) {
            usort($members, function ($a, $b) use ($targets, $entries) {
                $ta = in_array(substr($a, 0, 8), $targets, true);
                $tb = in_array(substr($b, 0, 8), $targets, true);

                return [$tb, $entries[$b] ?? 0, $a] <=> [$ta, $entries[$a] ?? 0, $b];
            });
            $key = array_intersect($members, $ours) ? 'ours' : substr($members[0], 0, 8);
            foreach ($members as $c) {
                $familyOf[$c] = $key;
            }
        }
        $members = [];
        foreach ($familyOf as $c => $key) {
            $members[$key][] = $c;
        }
        $names = $this->names($members);
        foreach ($familyOf as $c => $key) {
            Owner::where('coldkey', $c)->update(['family' => $key, 'family_name' => $names[$key]]);
        }

        return $familyOf;
    }

    /** Stable names for families that have neither a configured name nor a dossier team name. */
    private const WORDS = [
        'Alder', 'Aspen', 'Birch', 'Cedar', 'Elm', 'Fir', 'Hazel', 'Larch', 'Maple', 'Oak', 'Pine', 'Rowan', 'Willow', 'Yew',
        'Acacia', 'Cypress', 'Holly', 'Juniper', 'Laurel', 'Linden', 'Magnolia', 'Olive', 'Poplar', 'Sequoia', 'Spruce',
        'Teak', 'Walnut', 'Ash', 'Beech', 'Hemlock', 'Albatross', 'Bittern', 'Condor', 'Crane', 'Egret', 'Falcon', 'Finch',
        'Gannet', 'Heron', 'Ibis', 'Kestrel', 'Kite', 'Lark', 'Linnet', 'Magpie', 'Merlin', 'Osprey', 'Owl', 'Petrel',
        'Plover', 'Quail', 'Raven', 'Robin', 'Shrike', 'Swift', 'Tern', 'Thrush', 'Wren', 'Agate', 'Basalt', 'Beryl',
        'Cobalt', 'Coral', 'Flint', 'Garnet', 'Granite', 'Jade', 'Jasper', 'Marble', 'Obsidian', 'Onyx', 'Opal', 'Quartz',
        'Ruby', 'Sapphire', 'Shale', 'Slate', 'Topaz', 'Zircon', 'Amber', 'Bronze', 'Copper', 'Iron', 'Silver', 'Tin',
        'Comet', 'Nova', 'Orbit', 'Pulsar', 'Quasar', 'Nebula', 'Meteor', 'Aurora', 'Zenith', 'Vega', 'Lyra', 'Orion',
        'Sirius', 'Rigel', 'Altair', 'Deneb', 'Polaris', 'Atlas', 'Delta', 'Fjord', 'Glacier', 'Harbor', 'Island', 'Lagoon',
        'Mesa', 'Oasis', 'Prairie', 'Reef', 'Savanna', 'Tundra', 'Valley', 'Canyon', 'Dune', 'Estuary',
    ];

    /**
     * @param  array<string, array<int, string>>  $members  family key => coldkeys
     * @return array<string, string> family key => name
     */
    private function names(array $members): array
    {
        ksort($members);
        $manual = config('soma.family_names', []);
        $dossier = $this->dossierTeams();
        // a generated name stays with the owners it was given to (table family_name_owners): a family keeps the name most of
        // its owners held, whichever owner is its root, and a name freed by a merge never goes to an unrelated family
        // (2026-10-27: "Pine" had moved from 5FBvtucf's family to 5CdCkDmv, and B's name flipped from Dune 2 to Egret)
        $holders = DB::table('family_name_owners')->get(['name', 'coldkey'])->groupBy('name')
            ->map(fn ($rows) => $rows->pluck('coldkey')->all())->all();
        $free = fn (string $name, array $coldkeys): bool => ! isset($holders[$name]) || array_intersect($holders[$name], $coldkeys) !== [];
        $names = [];
        $used = [];
        foreach ($members as $key => $coldkeys) {
            $name = $manual[$key] ?? null;
            foreach ($name ? [] : $coldkeys as $c) {
                if (isset($dossier[$c])) {
                    $name = $dossier[$c];
                    break;
                }
            }
            if ($name) {
                $names[$key] = $name;
                $used[$name] = true;
            }
        }
        foreach ($members as $key => $coldkeys) {
            if (isset($names[$key])) {
                continue;
            }
            $held = [];
            foreach ($holders as $name => $cs) {
                if ($k = count(array_intersect($cs, $coldkeys))) {
                    $held[(string) $name] = $k;
                }
            }
            uksort($held, fn ($a, $b) => [$held[$b], $a] <=> [$held[$a], $b]);
            foreach (array_keys($held) as $old) {
                if (! isset($used[$old]) && ! str_starts_with($old, 'Family ')) {
                    $names[$key] = $old;
                    $used[$old] = true;
                    break;
                }
            }
        }
        $n = count(self::WORDS);
        foreach ($members as $key => $coldkeys) {
            if (isset($names[$key])) {
                continue;
            }
            $start = crc32((string) $key) % $n;
            for ($pass = 1; ! isset($names[$key]); $pass++) {
                for ($k = 0; $k < $n; $k++) {
                    $candidate = self::WORDS[($start + $k) % $n].($pass > 1 ? " $pass" : '');
                    if (! isset($used[$candidate]) && $free($candidate, $coldkeys)) {
                        $names[$key] = $candidate;
                        $used[$candidate] = true;
                        break;
                    }
                }
            }
        }
        $rows = [];
        foreach ($members as $key => $coldkeys) {
            foreach ($coldkeys as $c) {
                $rows[] = ['name' => $names[$key], 'coldkey' => $c];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('family_name_owners')->insertOrIgnore($chunk);
        }

        return $names;
    }

    /**
     * Team names of the SN114 team dossier: coldkey => team (config/soma_teams.php, copied from the dossier 2026-11-25).
     *
     * @return array<string, string>
     */
    private function dossierTeams(): array
    {
        return (array) config('soma_teams', []);
    }

    /**
     * Transfers directly between two owners.
     *
     * @param  array<int, string>  $owners
     * @return array<int, array{a: string, b: string, n: int, tao: float, first: ?string}>
     */
    public function directLinks(array $owners): array
    {
        $rows = Transfer::whereIn('from_address', $owners)->whereIn('to_address', $owners)
            ->whereColumn('from_address', '!=', 'to_address')->get();
        $links = [];
        foreach ($rows as $t) {
            [$a, $b] = $t->from_address < $t->to_address ? [$t->from_address, $t->to_address] : [$t->to_address, $t->from_address];
            $k = "$a|$b";
            $links[$k] ??= ['a' => $a, 'b' => $b, 'n' => 0, 'tao' => 0.0, 'first' => null];
            $links[$k]['n']++;
            $links[$k]['tao'] += $t->amount_tao;
            $at = $t->transferred_at?->toIso8601String();
            if ($at && (! $links[$k]['first'] || $at < $links[$k]['first'])) {
                $links[$k]['first'] = $at;
            }
        }

        return array_values($links);
    }

    /**
     * Wallets that traded with two or more owners, with their kind.
     *
     * @param  array<int, string>  $owners
     * @return Collection<int, array{address: string, kind: ?string, transfers: ?int, owners: array<int, string>}>
     */
    public function sharedCounterparties(array $owners): Collection
    {
        $set = array_flip($owners);
        $with = [];
        Transfer::where(fn ($q) => $q->whereIn('from_address', $owners)->orWhereIn('to_address', $owners))
            ->get(['from_address', 'to_address'])
            ->each(function (Transfer $t) use ($set, &$with) {
                if (isset($set[$t->from_address]) && ! isset($set[$t->to_address])) {
                    $with[$t->to_address][$t->from_address] = true;
                } elseif (isset($set[$t->to_address]) && ! isset($set[$t->from_address])) {
                    $with[$t->from_address][$t->to_address] = true;
                }
            });
        $wallets = Wallet::whereIn('address', array_keys($with))->get()->keyBy('address');

        return collect($with)->filter(fn ($o) => count($o) >= 2)->map(fn ($o, $address) => [
            'address' => $address,
            'kind' => $wallets[$address]->kind ?? null,
            'transfers' => $wallets[$address]->transfer_count ?? null,
            'owners' => array_keys($o),
        ])->values();
    }
}
