<?php

namespace App\Services\Sn34;

use App\Models\Sn34\Board;
use App\Models\Sn34\Card;
use App\Models\Sn34\Event;
use App\Models\Sn34\Neuron;
use App\Models\Sn34\Run;
use App\Models\Sn34\SeatChange;
use App\Models\Sn34\Verdict;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Props of the /sn34 page: the Yugos competition on one screen.
 */
class Presenter
{
    /** Runs of the older packs sent with the page (the current pack is always sent in full). */
    public const OLDER_RUNS = 200;

    public function __construct(private Families $families) {}

    public function overview(): array
    {
        $board = Board::orderByDesc('confirmed_at')->first();
        $fam = $this->families->summary();
        $familyOf = function (?string $coldkey) use ($fam): ?string {
            return $coldkey === null ? null : ($fam['family'][$coldkey] ?? $coldkey);
        };
        $coldkeyOf = Neuron::pluck('coldkey', 'hotkey')->all()
            + Run::whereNotNull('coldkey')->pluck('coldkey', 'hotkey')->all();
        $incentive = Neuron::pluck('incentive', 'hotkey')->all();
        $ours = fn (?string $hotkey) => $hotkey !== null && collect(config('sn34.ours_hotkeys', []))->contains(fn ($p) => str_starts_with($hotkey, $p));
        $verdicts = Verdict::all()->keyBy('run_id');
        $runs = Run::orderBy('enqueued_at')->get();
        $pack = $runs->whereNotNull('pack')->last()?->pack;

        $runRow = function (Run $r) use ($verdicts, $familyOf, $ours): array {
            $v = $verdicts[$r->run_id] ?? null;

            return [
                'run' => substr($r->run_id, 8, 8), 'run_id' => $r->run_id, 'hotkey' => $r->hotkey, 'coldkey' => $r->coldkey,
                'family' => $familyOf($r->coldkey), 'ours' => $ours($r->hotkey), 'pack' => $r->pack, 'state' => $r->state,
                'enqueued_at' => $r->enqueued_at?->toIso8601String(), 'started_at' => $r->started_at?->toIso8601String(),
                'finished_at' => $r->finished_at?->toIso8601String(), 'bundle' => substr((string) $r->submission_sha256, 0, 8),
                'second_attempts' => $r->started_at_changes, 'eligible' => $v?->eligible, 'promoted' => $v?->promoted,
                'nv' => $v?->net_value, 'lb' => $v?->lower_bound, 'reasons' => $v?->reasons ?? [],
                'classes' => $v?->classes ?? [],
            ];
        };

        $kings = collect($board?->kings ?? [])->map(function ($k) use ($verdicts, $coldkeyOf, $familyOf, $incentive, $ours) {
            $v = $verdicts[$k['run_id'] ?? ''] ?? null;
            $ck = $coldkeyOf[$k['hotkey'] ?? ''] ?? null;

            return ['seat' => $k['seat'] ?? null, 'hotkey' => $k['hotkey'] ?? null, 'run' => substr((string) ($k['run_id'] ?? ''), 8, 8),
                'share' => $k['seat_share'] ?? null, 'nv' => $k['net_value'] ?? $v?->net_value, 'lb' => $k['lower_bound'] ?? $v?->lower_bound,
                'coldkey' => $ck, 'family' => $familyOf($ck), 'ours' => $ours($k['hotkey'] ?? null),
                'incentive' => $incentive[$k['hotkey'] ?? ''] ?? null, 'classes' => $v?->classes ?? []];
        })->values();

        $judged = $verdicts->keys()->all();
        $queueState = collect($board?->queue ?? [])->keyBy('run_id');
        $now = $runs->filter(fn (Run $r) => in_array($r->state, ['queued', 'running'], true)
            || ($queueState[$r->run_id]['state'] ?? null) === 'error'
            || ($r->state === 'failed' && ! in_array($r->run_id, $judged, true) && $r->finished_at?->gt(now()->subDays(2))))
            ->map(function (Run $r) use ($runRow, $queueState) {
                $p = $r->progress ?? [];

                return $runRow($r) + ['queue_state' => $queueState[$r->run_id]['state'] ?? null,
                    'queue_reason' => $queueState[$r->run_id]['reason'] ?? null, 'executed' => $p['executed'] ?? null,
                    'assigned' => $p['assigned'] ?? null, 'graded' => $p['graded'] ?? null, 'ungraded' => $p['ungraded'] ?? null,
                    'results_status' => $p['_status'] ?? null, 'progress_at' => $r->progress_at?->toIso8601String()];
            })->values();

        // newest run end first (first_confirmed_at of imported history is the newest saved board, not the decision time)
        $finished = $runs->pluck('finished_at', 'run_id');
        $decisions = $verdicts->filter(fn ($v) => $board && $v->policy_id === $board->policy_id)
            ->sortByDesc(fn ($v) => $finished->get($v->run_id)?->getTimestamp() ?? $v->first_confirmed_at?->getTimestamp() ?? 0)
            ->map(function (Verdict $v) use ($runs, $runRow) {
                $r = $runs->firstWhere('run_id', $v->run_id);
                $row = $r ? $runRow($r) : ['run' => substr($v->run_id, 8, 8), 'hotkey' => $v->hotkey, 'classes' => $v->classes];

                return $row + ['first_confirmed_at' => $v->first_confirmed_at?->toIso8601String(),
                    'near_miss' => ! $v->eligible && count($v->reasons ?? []) === 1];
            })->values();

        $seatHistory = $this->seatHistory($coldkeyOf, $familyOf, $board?->confirmed_at);

        return [
            'board' => $board ? ['confirmed_at' => $board->confirmed_at?->toIso8601String(), 'policy' => $board->policy_id,
                'phase' => $board->phase, 'paused' => $board->paused, 'weights' => $board->published_weights ?? [],
                'floors' => $this->floors($board->high_water_marks ?? []), 'queue' => $board->queue ?? [],
                'read_at' => $board->read_at?->toIso8601String()] : null,
            'pack' => $pack,
            'kings' => $kings,
            'runs_now' => $now,
            'decisions' => $decisions,
            // page weight (review 2026-11-25): the current pack in full, the newest OLDER_RUNS of the older packs
            'runs' => $runs->where('pack', $pack)->map($runRow)
                ->merge($runs->filter(fn (Run $r) => $r->pack !== $pack)->sortByDesc('enqueued_at')->take(self::OLDER_RUNS)->map($runRow))
                ->sortByDesc('enqueued_at')->values(),
            'runs_omitted' => max(0, $runs->filter(fn (Run $r) => $r->pack !== $pack)->count() - self::OLDER_RUNS),
            'families' => $this->familyRows($runs, $verdicts, $fam, $seatHistory, $incentive, $now, $pack, $ours),
            'evidence' => ['direct' => $fam['direct'], 'shared' => $fam['shared']],
            'seat_history' => $seatHistory,
            'datasets' => $this->datasets($runs, $pack, $familyOf),
            'cards' => Card::orderByDesc('synced_at')->limit(72)->get(['sha256', 'synced_at', 'our_choices'])
                ->map(fn ($c) => ['sha' => substr($c->sha256, 0, 8), 'synced_at' => $c->synced_at?->toIso8601String(), 'choices' => $c->our_choices])->values(),
            'events' => Event::orderByDesc('id')->limit(300)->get()->map(fn ($e) => ['id' => $e->id, 'at' => $e->occurred_at?->toIso8601String(),
                'type' => $e->type, 'run' => $e->run_id ? substr($e->run_id, 8, 8) : null, 'hotkey' => $e->hotkey,
                'family' => $familyOf($e->coldkey ?? ($coldkeyOf[$e->hotkey ?? ''] ?? null)), 'data' => $e->data])->values(),
            'notes' => $this->notes(),
            'our_hotkeys' => $this->ourHotkeys(),
            // last successful read of each job (set by the jobs), else the newest stored row
            'reads' => [
                'board' => Cache::get('sn34:read:board') ?? $board?->read_at?->toIso8601String(),
                'runs' => Cache::get('sn34:read:runs') ?? (Run::max('progress_at') ? Carbon::parse(Run::max('progress_at'))->toIso8601String() : null),
                'card' => Cache::get('sn34:read:card') ?? (Card::max('read_at') ? Carbon::parse(Card::max('read_at'))->toIso8601String() : null),
                'metagraph' => Cache::get('sn34:read:metagraph') ?? (Neuron::max('read_at') ? Carbon::parse(Neuron::max('read_at'))->toIso8601String() : null),
            ],
        ];
    }

    /**
     * Our hotkeys on netuid 34 (config sn34.ours_hotkeys, plus every other registered hotkey of the same coldkey):
     * uid, incentive, daily alpha, registration age, immunity left, the deregistration-queue place and the newest run.
     * Queue rule (the same reading as the SOMA Hotkeys page): uids not immune, lowest emission first, then the earliest
     * registration. A configured hotkey missing from the metagraph is listed as not registered.
     */
    public function ourHotkeys(): array
    {
        $prefixes = config('sn34.ours_hotkeys', []);
        $names = config('sn34.our_names', []);
        $neurons = Neuron::all();
        $isOurs = fn (?string $h) => $h !== null && collect($prefixes)->contains(fn ($p) => str_starts_with($h, $p));
        $ourColdkeys = $neurons->filter(fn ($n) => $isOurs($n->hotkey))->pluck('coldkey')->unique()->all();
        $mine = $neurons->filter(fn ($n) => $isOurs($n->hotkey) || in_array($n->coldkey, $ourColdkeys, true))->values();
        $order = $neurons->reject(fn ($n) => (bool) $n->immune)
            ->sortBy(fn ($n) => sprintf('%030.6f|%015d', (float) ($n->emission ?? 0), (int) ($n->registered_at_block ?? 0)))
            ->pluck('uid')->values()->flip();
        $period = (int) config('sn34.immunity_period', 5000);
        $blockSeconds = (int) config('sn34.block_seconds', 12);
        $rows = [];
        foreach ($mine as $n) {
            $age = $n->block_number && $n->registered_at_block ? $n->block_number - $n->registered_at_block : null;
            $last = Run::where('hotkey', $n->hotkey)->orderByDesc('enqueued_at')->first();
            $v = $last ? Verdict::firstWhere('run_id', $last->run_id) : null;
            $rows[] = [
                'name' => collect($names)->first(fn ($nm, $p) => str_starts_with($n->hotkey, $p)), 'hotkey' => $n->hotkey,
                'coldkey' => $n->coldkey, 'registered' => true, 'uid' => $n->uid, 'incentive' => $n->incentive,
                'daily_alpha' => $n->daily_alpha, 'daily_tao' => $n->daily_tao, 'emission' => $n->emission,
                'registered_at' => $n->registered_at?->toIso8601String(), 'age_blocks' => $age,
                'immune' => $n->immune, 'immunity_left_hours' => $age !== null ? round(max(0, $period - $age) * $blockSeconds / 3600, 1) : null,
                'in_danger' => $n->in_danger, 'place' => isset($order[$n->uid]) ? $order[$n->uid] + 1 : null,
                'last_run' => $last ? ['run' => substr($last->run_id, 8, 8), 'state' => $last->state, 'enqueued_at' => $last->enqueued_at?->toIso8601String(),
                    'eligible' => $v?->eligible, 'promoted' => $v?->promoted, 'nv' => $v?->net_value] : null,
            ];
        }
        foreach ($prefixes as $p) {
            if (! $mine->contains(fn ($n) => str_starts_with($n->hotkey, $p))) {
                $rows[] = ['name' => $names[$p] ?? null, 'hotkey' => $p, 'coldkey' => null, 'registered' => false, 'uid' => null,
                    'incentive' => null, 'daily_alpha' => null, 'daily_tao' => null, 'emission' => null, 'registered_at' => null,
                    'age_blocks' => null, 'immune' => null, 'immunity_left_hours' => null, 'in_danger' => null, 'place' => null, 'last_run' => null];
            }
        }

        return ['rows' => $rows, 'uids' => $neurons->count(), 'non_immune' => $order->count(), 'immunity_period' => $period,
            'read_at' => Neuron::max('read_at') ? Carbon::parse(Neuron::max('read_at'))->toIso8601String() : null];
    }

    /**
     * A light SN34 summary for the cross-subnet overview page: board time, seats, our weight, runs now, the newest five
     * decisions. Reads only the newest board, the open runs and the verdicts of the current policy (no families,
     * datasets or seat history).
     */
    public function summary(): array
    {
        $board = Board::orderByDesc('confirmed_at')->first();
        $ourPrefixes = config('sn34.ours_hotkeys', []);
        $ours = fn (?string $h) => $h !== null && collect($ourPrefixes)->contains(fn ($p) => str_starts_with($h, $p));
        $coldkeyOf = Neuron::pluck('coldkey', 'hotkey')->all();
        $incentive = Neuron::pluck('incentive', 'hotkey')->all();
        $weights = $board?->published_weights ?? [];
        $kings = collect($board?->kings ?? [])->map(fn ($k) => [
            'seat' => $k['seat'] ?? null, 'hotkey' => $k['hotkey'] ?? null, 'run' => substr((string) ($k['run_id'] ?? ''), 8, 8),
            'share' => $k['seat_share'] ?? null, 'nv' => $k['net_value'] ?? null, 'lb' => $k['lower_bound'] ?? null,
            'coldkey' => $coldkeyOf[$k['hotkey'] ?? ''] ?? null, 'ours' => $ours($k['hotkey'] ?? null),
            'incentive' => $incentive[$k['hotkey'] ?? ''] ?? null,
        ])->values();
        $queue = collect($board?->queue ?? [])->keyBy('run_id');
        $open = Run::whereIn('state', ['queued', 'running'])->orWhereIn('run_id', $queue->where('state', 'error')->keys()->all())
            ->orderByDesc('enqueued_at')->get()
            ->map(fn (Run $r) => ['run' => substr($r->run_id, 8, 8), 'hotkey' => $r->hotkey, 'coldkey' => $r->coldkey, 'ours' => $ours($r->hotkey),
                'state' => ($queue[$r->run_id]['state'] ?? null) === 'error' ? 'error' : $r->state,
                'executed' => $r->progress['executed'] ?? null, 'assigned' => $r->progress['assigned'] ?? null,
                'ungraded' => $r->progress['ungraded'] ?? null, 'started_at' => $r->started_at?->toIso8601String()])->values();
        $finished = Run::pluck('finished_at', 'run_id');
        $decisions = Verdict::where('policy_id', $board?->policy_id)->get()
            ->sortByDesc(fn ($v) => $finished->get($v->run_id)?->getTimestamp() ?? 0)->take(5)
            ->map(fn (Verdict $v) => ['run' => substr($v->run_id, 8, 8), 'hotkey' => $v->hotkey, 'coldkey' => $coldkeyOf[$v->hotkey] ?? null,
                'ours' => $ours($v->hotkey), 'eligible' => $v->eligible, 'promoted' => $v->promoted, 'nv' => $v->net_value,
                'lb' => $v->lower_bound, 'near_miss' => ! $v->eligible && count($v->reasons ?? []) === 1, 'reasons' => $v->reasons ?? [],
                'finished_at' => $finished->get($v->run_id)?->toIso8601String()])->values();

        return [
            'confirmed_at' => $board?->confirmed_at?->toIso8601String(), 'policy' => $board?->policy_id, 'phase' => $board?->phase,
            'kings' => $kings,
            'our_weight' => collect($weights)->filter(fn ($w, $h) => $ours($h))->sum(),
            'runs_now' => $open,
            'decisions' => $decisions,
            'our_hotkeys' => (function () {
                $h = $this->ourHotkeys();
                $reg = collect($h['rows'])->where('registered', true);

                return ['registered' => $reg->count(), 'nearest_place' => $reg->pluck('place')->filter()->min(), 'non_immune' => $h['non_immune']];
            })(),
        ];
    }

    private function floors(array $hwm): array
    {
        $out = [];
        foreach (Ingest::CLASSES as $c) {
            $h = $hwm[$c] ?? [];
            $out[$c] = ['quality' => $h['quality_floor'] ?? null, 'hard' => $h['hard_quality_floor'] ?? null, 'private' => $h['private_quality_floor'] ?? null];
        }

        return $out;
    }

    private function seatHistory(array $coldkeyOf, callable $familyOf, ?CarbonInterface $last): array
    {
        $rows = SeatChange::orderBy('from_at')->get();
        $out = [];
        foreach ($rows as $i => $s) {
            $to = $rows[$i + 1]->from_at ?? null;
            $end = $to ?? $last ?? now();
            $out[] = ['from' => $s->from_at?->toIso8601String(), 'to' => $to?->toIso8601String(), 'policy' => $s->policy_id,
                'hours' => round(max(0, $end->diffInSeconds($s->from_at, true)) / 3600, 2), 'open' => $to === null,
                'seats' => collect($s->seats)->map(fn ($x) => ['seat' => $x[0], 'hotkey' => $x[1], 'run' => substr((string) $x[2], 8, 8),
                    'share' => $x[3], 'family' => $familyOf($coldkeyOf[$x[1] ?? ''] ?? null)])->all()];
        }

        return array_reverse($out);
    }

    private function familyRows(Collection $runs, Collection $verdicts, array $fam, array $seatHistory, array $incentive, Collection $now, ?string $pack, callable $ours): array
    {
        $groups = [];
        foreach ($runs as $r) {
            $key = $r->coldkey ? ($fam['family'][$r->coldkey] ?? $r->coldkey) : null;
            if ($key !== null) {
                $groups[$key][] = $r;
            }
        }
        $seatHours = [];
        foreach ($seatHistory as $s) {
            foreach ($s['seats'] as $x) {
                if ($x['family']) {
                    $seatHours[$x['family']][$x['seat']] = ($seatHours[$x['family']][$x['seat']] ?? 0) + $s['hours'];
                }
            }
        }
        $out = [];
        foreach ($groups as $key => $rs) {
            $rs = collect($rs);
            $packs = [];
            foreach ($rs->groupBy(fn ($r) => $r->pack ?? 'unknown') as $p => $xs) {
                $vs = $xs->map(fn ($r) => $verdicts[$r->run_id] ?? null)->filter();
                $packs[$p] = ['runs' => $xs->count(), 'states' => $xs->countBy('state')->all(), 'judged' => $vs->count(),
                    'eligible' => $vs->where('eligible', true)->count(), 'crowns' => $vs->where('promoted', true)->count(),
                    'best_nv' => $vs->max('net_value')];
            }
            $hotkeys = $rs->pluck('hotkey')->unique()->values();
            $current = $rs->where('pack', $pack);
            $vCur = $current->map(fn ($r) => $verdicts[$r->run_id] ?? null)->filter();
            $out[] = [
                'key' => $key, 'coldkeys' => $rs->pluck('coldkey')->unique()->values(), 'hotkeys' => $hotkeys,
                'ours' => $hotkeys->contains(fn ($h) => $ours($h)), 'runs' => $rs->count(),
                'crowns' => $rs->filter(fn ($r) => ($verdicts[$r->run_id] ?? null)?->promoted)->count(),
                'current_runs' => $current->count(), 'current_eligible' => $vCur->where('eligible', true)->count(),
                'current_best_nv' => $vCur->max('net_value'), 'current_best_lb' => $vCur->max('lower_bound'),
                'packs' => $packs, 'seat_hours' => $seatHours[$key] ?? [],
                'incentive' => $hotkeys->sum(fn ($h) => $incentive[$h] ?? 0),
                'last_enqueue' => $rs->max('enqueued_at')?->toIso8601String(),
                'running' => $now->where('family', $key)->pluck('run')->values(),
            ];
        }
        usort($out, fn ($a, $b) => [$b['current_runs'], $b['runs']] <=> [$a['current_runs'], $a['runs']]);

        return $out;
    }

    /**
     * Current pack, complete runs: per dataset the median and best score, the run that holds the best, and per family
     * its mean minus the median in answers (score difference x items).
     */
    private function datasets(Collection $runs, ?string $pack, callable $familyOf): array
    {
        $rows = [];
        foreach ($runs->where('pack', $pack)->where('complete', true) as $r) {
            foreach ($r->progress['datasets'] ?? [] as $d) {
                $k = ($d['category'] ?? '?').'/'.($d['dataset'] ?? '?');
                $rows[$k][] = ['score' => (float) ($d['mean_score'] ?? 0), 'n' => (int) ($d['graded'] ?? 0),
                    'run' => substr($r->run_id, 8, 8), 'hotkey' => $r->hotkey, 'family' => $familyOf($r->coldkey)];
            }
        }
        $out = [];
        foreach ($rows as $k => $xs) {
            $scores = collect($xs)->pluck('score')->sort()->values();
            $median = $scores->median();
            $best = collect($xs)->sortByDesc('score')->first();
            $n = collect($xs)->max('n');
            $byFamily = collect($xs)->filter(fn ($x) => $x['family'])->groupBy('family')
                ->map(fn ($g) => round(($g->avg('score') - $median) * $n, 1))->all();
            $out[] = ['dataset' => $k, 'items' => $n, 'runs' => count($xs), 'median' => $median, 'best' => $best, 'by_family' => $byFamily];
        }
        usort($out, fn ($a, $b) => strcmp($a['dataset'], $b['dataset']));

        return $out;
    }

    /**
     * NPA/34 watch notes from its notes file, English only (user 2026-11-25: 'show all with English'): the English rows
     * sub_analysis writes (newest watch-loop stops, newest Discord rows) and the family notes' English text. The Korean
     * originals stay in the NPA records and are not sent.
     */
    private function notes(): array
    {
        $file = (string) config('sn34.notes_file');
        $f = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
        $en = $f['notes_en'] ?? [];

        return [
            'generated_at' => $f['meta']['generated_at'] ?? null,
            'watch_rows' => $en['watch'] ?? [],
            'discord_rows' => $en['discord'] ?? [],
            'loop_stops' => array_reverse($f['loop_stops'] ?? []),
            'family_notes' => collect($f['family_notes'] ?? [])->map(fn ($n) => [
                'coldkeys' => $n['coldkeys'] ?? [], 'kind' => $n['kind'] ?? null, 'strength' => $n['strength'] ?? null,
                'verdict' => $n['verdict'] ?? null, 'text' => $en['family_notes'][$n['kind'] ?? ''] ?? null, 'source' => $n['source'] ?? null,
            ])->filter(fn ($n) => $n['text'] !== null)->values()->all(),
        ];
    }
}
