<?php

namespace App\Services\Soma;

use App\Models\ChainSnapshot;
use App\Models\HotkeyState;
use App\Models\Miner;
use App\Models\Owner;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Our SN114 hotkeys on the chain: registration, emission and the place in the deregistration queue.
 *
 * Queue order = lowest emission first, then the earliest registration; uids still inside the immunity period are
 * skipped. This is our reading of the chain rule (soma-miner tools/prune_order.py), not checked against the subtensor
 * code. When the subnet is full, every new registration removes queue place 1.
 */
class Hotkeys
{
    public const BLOCKS_PER_DAY = 7200;

    public const SECONDS_PER_BLOCK = 12;

    /** Registration counts shown on the page: label => blocks back from the snapshot. */
    public const WINDOWS = ['h1' => 300, 'h6' => 1800, 'h24' => 7200, 'd3' => 21600, 'd7' => 50400];

    /**
     * Queue place of every uid that is not immune: uid => place (1 = removed by the next registration).
     *
     * @param  array<int, array{uid: int, emission: int, registered_at_block: int}>  $neurons
     * @return array<int, int>
     */
    public static function ranks(array $neurons, int $block, int $immunity): array
    {
        $queue = array_values(array_filter($neurons, fn ($n) => $block - (int) $n['registered_at_block'] >= $immunity));
        usort($queue, fn ($a, $b) => [(int) $a['emission'], (int) $a['registered_at_block']] <=> [(int) $b['emission'], (int) $b['registered_at_block']]);
        $ranks = [];
        foreach ($queue as $i => $n) {
            $ranks[(int) $n['uid']] = $i + 1;
        }

        return $ranks;
    }

    /**
     * Store one snapshot printed by scripts/chain_snapshot.py, with the state of each of our hotkeys.
     *
     * @param  array{netuid: int, block: int, immunity_period: int, neurons: array, ours: array<string, string>, notes?: array<string, string>}  $data
     */
    public function record(array $data, ?CarbonInterface $now = null): ChainSnapshot
    {
        $now ??= now();
        $block = (int) $data['block'];
        $immunity = (int) $data['immunity_period'];
        $neurons = array_values($data['neurons']);
        $ranks = self::ranks($neurons, $block, $immunity);
        $byHotkey = collect($neurons)->keyBy('hotkey');
        $notes = $data['notes'] ?? [];

        return DB::transaction(function () use ($data, $now, $block, $immunity, $neurons, $ranks, $byHotkey, $notes) {
            $snapshot = ChainSnapshot::create([
                'netuid' => (int) $data['netuid'],
                'block' => $block,
                'taken_at' => $now,
                'immunity_period' => $immunity,
                'uids' => count($neurons),
                'immune' => count($neurons) - count($ranks),
                'zero_emission' => collect($neurons)->where('emission', 0)->count(),
                'neurons' => $neurons,
            ]);
            foreach ($data['ours'] as $hotkey => $name) {
                $n = $byHotkey->get($hotkey);
                HotkeyState::create([
                    'chain_snapshot_id' => $snapshot->id,
                    'name' => $name,
                    'hotkey' => $hotkey,
                    'registered' => $n !== null,
                    'uid' => $n['uid'] ?? null,
                    'emission' => $n['emission'] ?? null,
                    'registered_at_block' => $n['registered_at_block'] ?? null,
                    'immune' => $n !== null && ! isset($ranks[$n['uid']]),
                    'rank' => $n !== null ? ($ranks[$n['uid']] ?? null) : null,
                    'note' => $notes[$name] ?? null,
                ]);
            }
            // the full neuron list is kept for a day; older snapshots keep their counts and our hotkeys' states
            ChainSnapshot::where('id', '<', $snapshot->id)->where('taken_at', '<', $now->copy()->subDay())
                ->whereNotNull('neurons')->update(['neurons' => null]);

            return $snapshot;
        });
    }

    /** Everything the Hotkeys page shows, from the newest snapshot. */
    public function page(): array
    {
        $current = (int) config('soma.competition');
        $empty = ['snapshot' => null, 'ours' => [], 'queue' => [], 'history' => [], 'names' => [],
            'current' => ['id' => $current, 'round' => config("soma.competitions.$current.round")]];
        $snapshot = ChainSnapshot::orderByDesc('taken_at')->orderByDesc('id')->first();
        if (! $snapshot) {
            return $empty;
        }

        $block = (int) $snapshot->block;
        $neurons = collect($snapshot->neurons ?? []);
        $registrations = collect(self::WINDOWS)->map(fn ($blocks) => $neurons->filter(fn ($n) => (int) $n['registered_at_block'] > $block - $blocks)->count());
        $perDay24 = (float) $registrations['h24'];
        $perDay7 = $registrations['d7'] / 7;

        $states = $snapshot->states()->get();
        $dayAgo = $this->ranksAround($snapshot->taken_at->copy()->subDay());
        $entries = Miner::whereIn('hotkey', $states->pluck('hotkey'))->orderByDesc('competition_id')->get()->groupBy('hotkey');

        $ours = $states->map(function (HotkeyState $s) use ($block, $snapshot, $dayAgo, $entries, $perDay24, $perDay7, $current) {
            $mine = $entries->get($s->hotkey, collect());
            $immuneLeft = $s->immune ? (int) $snapshot->immunity_period - ($block - (int) $s->registered_at_block) : null;

            return [
                'name' => $s->name,
                'hotkey' => $s->hotkey,
                'registered' => $s->registered,
                'uid' => $s->uid,
                'emission' => $s->emission,
                'registered_at_block' => $s->registered_at_block,
                'age_days' => $s->registered ? round(($block - (int) $s->registered_at_block) / self::BLOCKS_PER_DAY, 1) : null,
                'immune' => $s->immune,
                'immune_hours_left' => $immuneLeft !== null ? round($immuneLeft * self::SECONDS_PER_BLOCK / 3600, 1) : null,
                'rank' => $s->rank,
                'rank_day_ago' => $dayAgo[$s->hotkey] ?? null,
                'eta_days_24h' => $s->rank && $perDay24 > 0 ? round($s->rank / $perDay24, 1) : null,
                'eta_days_7d' => $s->rank && $perDay7 > 0 ? round($s->rank / $perDay7, 1) : null,
                'note' => $s->note,
                'in_current' => $mine->contains('competition_id', $current),
                'entries' => $mine->map(fn (Miner $m) => [
                    'competition' => $m->competition_id,
                    'round' => config("soma.competitions.{$m->competition_id}.round"),
                    'label' => $m->our_label,
                    'status' => $m->status,
                    'stage1' => $m->stage1_score,
                    'overall' => $m->overall,
                    'place' => $m->place,
                    'uploaded_at' => $m->last_submit_at?->toIso8601String(),
                ])->values(),
            ];
        })
            // registered first, by queue place (immune ones after), then the unregistered by name
            ->sortBy(fn ($r) => [$r['registered'] ? 0 : 1, $r['rank'] ?? PHP_INT_MAX, $r['name']])
            ->values();

        return [
            'snapshot' => [
                'block' => $block,
                'taken_at' => $snapshot->taken_at->toIso8601String(),
                'immunity_period' => (int) $snapshot->immunity_period,
                'uids' => (int) $snapshot->uids,
                'immune' => (int) $snapshot->immune,
                'zero_emission' => (int) $snapshot->zero_emission,
                'registrations' => $registrations,
                'per_day_24h' => $perDay24,
                'per_day_7d' => round($perDay7, 1),
            ],
            'ours' => $ours,
            'queue' => $this->queue($neurons, $block, (int) $snapshot->immunity_period, $states),
            'history' => $this->history($snapshot->taken_at),
            'names' => $ours->where('registered', true)->pluck('name')->values(),
            'current' => $empty['current'],
        ];
    }

    /** Our hotkeys' places in the snapshot nearest to $at (within three hours), hotkey => place. */
    private function ranksAround(CarbonInterface $at): array
    {
        $snapshot = ChainSnapshot::whereBetween('taken_at', [$at->copy()->subHours(3), $at->copy()->addHours(3)])
            ->get(['id', 'taken_at'])
            ->sortBy(fn (ChainSnapshot $s) => abs($s->taken_at->getTimestamp() - $at->getTimestamp()))
            ->first();

        return $snapshot ? $snapshot->states()->pluck('rank', 'hotkey')->all() : [];
    }

    /** Every uid of the snapshot in queue order (immune uids last), with its owner's family when the dashboard knows it. */
    private function queue(Collection $neurons, int $block, int $immunity, Collection $states): array
    {
        $ranks = self::ranks($neurons->all(), $block, $immunity);
        $ours = $states->pluck('name', 'hotkey');
        $owners = Owner::whereIn('coldkey', $neurons->pluck('coldkey'))->get(['coldkey', 'family_name', 'label'])->keyBy('coldkey');

        return $neurons->map(fn ($n) => [
            'rank' => $ranks[$n['uid']] ?? null,
            'uid' => (int) $n['uid'],
            'hotkey' => $n['hotkey'],
            'coldkey' => $n['coldkey'],
            'emission' => (int) $n['emission'],
            'registered_at_block' => (int) $n['registered_at_block'],
            'age_days' => round(($block - (int) $n['registered_at_block']) / self::BLOCKS_PER_DAY, 1),
            'validator' => (bool) ($n['validator'] ?? false),
            'validating' => (bool) ($n['validating'] ?? false),
            'ours' => $ours[$n['hotkey']] ?? null,
            'family' => $owners->get($n['coldkey'])?->family_name,
        ])->sortBy(fn ($r) => [$r['rank'] === null ? 1 : 0, $r['rank'] ?? 0, -$r['registered_at_block']])->values()->all();
    }

    /** One row per hour for the last seven days: our hotkeys' places, newest first. */
    private function history(CarbonInterface $until): array
    {
        $snapshots = ChainSnapshot::where('taken_at', '>=', $until->copy()->subDays(7))->orderByDesc('taken_at')
            ->get(['id', 'block', 'taken_at', 'zero_emission'])
            ->unique(fn (ChainSnapshot $s) => $s->taken_at->format('Y-m-d H'))
            ->values();
        $states = HotkeyState::whereIn('chain_snapshot_id', $snapshots->pluck('id'))->get()->groupBy('chain_snapshot_id');

        return $snapshots->map(fn (ChainSnapshot $s) => [
            'taken_at' => $s->taken_at->toIso8601String(),
            'block' => (int) $s->block,
            'zero_emission' => (int) $s->zero_emission,
            'ranks' => $states->get($s->id, collect())->mapWithKeys(fn (HotkeyState $h) => [$h->name => $h->rank])->all(),
            'emission' => $states->get($s->id, collect())->mapWithKeys(fn (HotkeyState $h) => [$h->name => $h->emission])->all(),
        ])->all();
    }
}
