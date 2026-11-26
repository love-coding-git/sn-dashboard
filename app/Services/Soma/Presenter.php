<?php

namespace App\Services\Soma;

use App\Models\Competition;
use App\Models\Earning;
use App\Models\Miner;
use App\Models\MinerTask;
use App\Models\Neuron;
use App\Models\Owner;
use App\Models\TaskRun;
use App\Models\OwnerRegistration;
use App\Models\Transfer;
use App\Models\Wallet;
use App\Models\WatchEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds the page props: one competition on one screen (leaderboard x stage-1 problems, fingerprints, owners and
 * families, earlier rounds, watcher events) and one miner in full (tasks, runs, owner, family, money, history).
 */
class Presenter
{
    public function __construct(private Families $families) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(Competition $competition): array
    {
        $miners = Miner::where('competition_id', $competition->id)->get();
        $all = MinerTask::whereIn('miner_id', $miners->pluck('id'))->get();
        $tasks = $all->where('is_screener', true)->whereIn('task_name', Stage1::problems($competition->id))->groupBy('miner_id');
        $stage2 = $all->where('is_screener', true)->where('screener_stage', 2)->groupBy('miner_id');
        $full = $all->where('is_screener', false)->groupBy('miner_id');
        $owners = Owner::whereIn('coldkey', $miners->pluck('coldkey')->filter()->unique())->get()->keyBy('coldkey');
        $history = $this->hotkeyHistory($miners->pluck('hotkey')->all(), $competition->id);
        $rank = $this->stage1Ranks($miners);
        $rank2 = $this->stage2Ranks($miners);
        $progress = $this->fullProgress($all->where('is_screener', false));
        // runs with tokens per task (stage 2 and the full evaluation): a run is recorded before it is graded
        $recorded = TaskRun::whereIn('miner_task_id', $all->where('is_screener', false)->pluck('id')->merge($all->where('screener_stage', 2)->pluck('id')))
            ->whereNotNull('weighted_tokens')->selectRaw('miner_task_id, count(*) n')->groupBy('miner_task_id')->pluck('n', 'miner_task_id')->all();
        // stage 2 and the full evaluation start after the upload deadline; before it, "qualified" means passed stage 1
        // (round 13: 171 of 179 entries "qualified" on 2026-11-10 read as a running full evaluation)
        $phase = $competition->upload_deadline_at?->isFuture() ? 'stage1' : $this->phase($miners, $full->isNotEmpty());
        $live = $this->liveScores($all->filter(fn (MinerTask $t) => ! $t->is_screener || $t->screener_stage === 2));

        $rows = $miners->map(function (Miner $m) use ($tasks, $stage2, $full, $owners, $history, $rank, $rank2, $progress, $recorded, $live) {
            $owner = $m->coldkey ? $owners->get($m->coldkey) : null;

            return $this->minerRow($m, $owner) + [
                'rank' => $rank[$m->id] ?? null,
                'stage2_rank' => $rank2[$m->id] ?? null,
                'advanced' => in_array($m->status, ['qualified', 'evaluating', 'scored', 'failed review'], true),
                'stage1' => $this->stage1Cells($tasks->get($m->id, collect())),
                'stage2' => $this->stage1Cells($stage2->get($m->id, collect()), $recorded),
                'full' => $this->stage1Cells($full->get($m->id, collect()), $recorded),
                'full_progress' => $progress[$m->id] ?? null,
                'live' => $live[$m->id] ?? null,
                'history' => $history[$m->hotkey] ?? [],
            ];
        });
        // The phase decides the order: the platform's place while uploads run and once scored; during stage 2 the place
        // follows a partial overall, so the order is "advanced first, then the stage-2 score"; during the full evaluation
        // the entries it has scored come first by their overall (2026-11-26: they were listed by stage 2), then the rest.
        $fullDone = fn ($r) => $phase === 'full' && $r['status'] === 'scored' && $r['overall'] !== null;
        $rows = ($phase === 'stage2' || $phase === 'full'
            ? $rows->sortBy(fn ($r) => [$fullDone($r) ? 0 : 1, $fullDone($r) ? -$r['overall'] : 0, $r['advanced'] ? 0 : 1, $r['stage2_rank'] ?? 9999, $r['rank'] ?? 9999])
            : $rows->sortBy(fn ($r) => [$r['place'] === null ? 1 : 0, $r['place'] ?? 9999, $r['rank'] ?? 9999]))->values();
        $taskList = fn (Collection $ts) => $ts->unique('task_name')
            ->sortBy(fn (MinerTask $t) => [array_search($t->complexity, ['short', 'medium', 'long'], true), $t->task_name])
            ->map(fn (MinerTask $t) => ['name' => $t->task_name, 'band' => $t->complexity])->values();

        return [
            'competition' => $this->competition($competition),
            'competitions' => Competition::withCount('miners')->orderByDesc('id')->get()
                ->map(fn (Competition $c) => ['id' => $c->id, 'round' => $c->round, 'miners' => $c->miners_count])->values(),
            'problems' => Stage1::problems($competition->id),
            'stage2_problems' => $taskList($all->where('is_screener', true)->where('screener_stage', 2)),
            'full_problems' => $taskList($all->where('is_screener', false)),
            'phase' => $phase,
            'cut' => $this->cutLine($miners),
            'miners' => $rows,
            'families' => $this->familySummaries($competition, $miners, $owners, $live),
            'earnings' => $this->familyEarnings($miners),
            'earnings_coverage' => $this->earningsCoverage(),
            'events' => $this->events($competition->id, null, 120),
            'watcher' => $this->watcher($competition),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function miner(Competition $competition, Miner $miner): array
    {
        $owner = $miner->coldkey ? Owner::where('coldkey', $miner->coldkey)->first() : null;
        $tasks = MinerTask::where('miner_id', $miner->id)->with('runs')->get()
            ->sortBy(fn (MinerTask $t) => [$t->is_screener ? 0 : 1, array_search($t->task_name, Stage1::problems($competition->id), true) ?: 99, $t->complexity, $t->task_name])
            ->values();
        $ref = Miner::where('competition_id', $competition->id)->where('hotkey', 'like', config('soma.reference_hotkey_prefix').'%')->first();
        $refTasks = $ref ? MinerTask::where('miner_id', $ref->id)->get()->keyBy('task_name') : collect();
        $familyKey = $owner?->family;
        $familyColdkeys = $familyKey ? Owner::where('family', $familyKey)->pluck('coldkey')->all() : ($miner->coldkey ? [$miner->coldkey] : []);

        return [
            'competition' => $this->competition($competition),
            'problems' => Stage1::problems($competition->id),
            'miner' => $this->minerRow($miner, $owner) + [
                'rank' => $miner->stage1_score === null ? null : Miner::where('competition_id', $competition->id)->where('stage1_score', '>', $miner->stage1_score)->count() + 1,
                'of' => Miner::where('competition_id', $competition->id)->whereNotNull('stage1_score')->count(),
                'stage2_verdict' => $miner->stage2_verdict,
                'tasks_synced_at' => $miner->tasks_synced_at?->toIso8601String(),
            ],
            'tasks' => $tasks->map(fn (MinerTask $t) => $this->taskRow($t) + [
                'reference' => ($r = $refTasks->get($t->task_name)) ? ['passed' => $r->compression_passed, 'steps' => $r->mean_steps, 'ratio' => $r->weighted_ratio, 'score' => $r->platform_score] : null,
                'runs' => $t->runs->map(fn ($r) => [
                    'seq' => $r->seq, 'passed' => $r->passed, 'steps' => $r->agent_steps, 'input' => $r->input_tokens,
                    'cached' => $r->cached_input_tokens, 'output' => $r->output_tokens, 'jev' => $r->jev_input_tokens,
                    'weighted' => $r->weighted_tokens, 'seconds' => $r->time_taken_seconds,
                ])->values(),
            ])->values(),
            'reference' => $ref ? ['hotkey' => $ref->hotkey] : null,
            'family' => $familyKey ? $this->familyDetail($familyKey, $familyColdkeys, $competition) : null,
            'owner' => $owner ? $this->ownerDetail($owner) : null,
            'history' => $this->ownerHistory($miner, $familyColdkeys, $competition->id),
            'events' => $this->events($competition->id, $miner->hotkey, 50),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function competition(Competition $c): array
    {
        return [
            'id' => $c->id,
            'round' => $c->round,
            'upload_deadline_at' => $c->upload_deadline_at?->toIso8601String(),
            'evaluation_end_at' => $c->evaluation_end_at?->toIso8601String(),
            'leaderboard_synced_at' => $c->leaderboard_synced_at?->toIso8601String(),
            'is_current' => $c->id === (int) config('soma.competition'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function minerRow(Miner $m, ?Owner $owner): array
    {
        return [
            'id' => $m->id,
            'hotkey' => $m->hotkey,
            'place' => $m->place,
            'status' => $m->status,
            'stage1_score' => $m->stage1_score,
            'stage1_verdict' => $m->stage1_verdict,
            'stage2_score' => $m->stage2_score,
            'stage2_verdict' => $m->stage2_verdict,
            'overall' => $m->overall,
            'categories' => ['short' => $m->cat_short, 'medium' => $m->cat_medium, 'long' => $m->cat_long],
            'last_submit_at' => $m->last_submit_at?->toIso8601String(),
            'first_seen_at' => $m->first_seen_at?->toIso8601String(),
            'uid' => $m->uid,
            'coldkey' => $m->coldkey,
            'is_ours' => $m->is_ours,
            'our_label' => $m->our_label,
            'family' => $owner?->family ?? ($m->coldkey ? Owner::familyOf($m->coldkey) : null),
            'family_name' => $owner?->family_name,
            'owner_label' => $owner?->label ?? ($m->coldkey ? config('soma.owner_labels.'.substr($m->coldkey, 0, 8)) : null),
            'owner_created_on' => $owner?->created_on?->toDateString(),
            'fingerprint' => $m->fingerprint,
        ];
    }

    /**
     * @param  Collection<int, MinerTask>  $tasks
     * @return array<string, array<string, mixed>>
     */
    private function stage1Cells(Collection $tasks, array $recorded = []): array
    {
        return $tasks->mapWithKeys(fn (MinerTask $t) => [$t->task_name => $this->taskRow($t) + ['run' => $recorded[$t->id] ?? null]])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function taskRow(MinerTask $t): array
    {
        return [
            'name' => $t->task_name,
            'is_screener' => $t->is_screener,
            'complexity' => $t->complexity,
            'passed' => $t->compression_passed,
            'finished' => $t->finished_runs,
            'total' => $t->baseline_total ?? $t->run_count,
            'baseline_passed' => $t->baseline_passed,
            'baseline_total' => $t->baseline_total,
            'score' => $t->platform_score,
            'ratio' => $t->weighted_ratio,
            'steps' => $t->mean_steps,
        ];
    }

    /**
     * Stage-1 rank inside the competition (higher score first).
     *
     * @param  Collection<int, Miner>  $miners
     * @return array<int, int>
     */
    private function stage2Ranks(Collection $miners): array
    {
        $rank = [];
        $miners->filter(fn (Miner $m) => $m->stage2_score !== null)->sortByDesc('stage2_score')->values()
            ->each(function (Miner $m, int $i) use (&$rank) {
                $rank[$m->id] = $i + 1;
            });

        return $rank;
    }

    /**
     * Where the competition stands: uploads and stage 1 ("stage1"), stage 2 running ("stage2"), the full evaluation
     * running ("full") or scored ("scored").
     *
     * @param  Collection<int, Miner>  $miners
     */
    private function phase(Collection $miners, bool $hasFullTasks): string
    {
        $status = $miners->pluck('status');
        // an entry can finish early (every run failed fast) and show "scored" while the others still run
        if ($status->intersect(['qualified', 'evaluating'])->isNotEmpty()) {
            return 'full';
        }
        if ($status->contains('scored')) {
            return 'scored';
        }
        if ($hasFullTasks) {
            return 'full';
        }
        if ($miners->pluck('stage2_verdict')->contains('running') && $miners->whereNotNull('stage2_score')->isNotEmpty()) {
            return 'stage2';
        }

        return 'stage1';
    }

    /**
     * The stage-2 advance line (soma-miner docs/MECHANISM.md): the max(60, 20%)-th best stage-2 score among the
     * stage-1 passers, and how many advanced once the selection is out.
     *
     * @param  Collection<int, Miner>  $miners
     * @return array{size: int, line: float|null, cohort: int, advanced: int}
     */
    private function cutLine(Collection $miners): array
    {
        $scores = $miners->where('stage1_verdict', 'passed')->pluck('stage2_score')->filter(fn ($v) => $v !== null)->sortDesc()->values();
        $size = max(60, (int) ceil(0.2 * $miners->where('stage1_verdict', 'passed')->count()));

        return [
            'size' => $size,
            'line' => $scores->count() >= $size ? (float) $scores[$size - 1] : null,
            'cohort' => $miners->where('stage1_verdict', 'passed')->count(),
            'advanced' => $miners->whereIn('status', ['qualified', 'evaluating', 'scored', 'failed review'])->count(),
        ];
    }

    /**
     * Full-evaluation runs per miner: planned, run (tokens recorded) and graded.
     *
     * @param  Collection<int, MinerTask>  $fullTasks
     * @return array<int, array{planned: int, run: int, graded: int, passed: int}>
     */
    private function fullProgress(Collection $fullTasks): array
    {
        if ($fullTasks->isEmpty()) {
            return [];
        }
        $run = TaskRun::whereIn('miner_task_id', $fullTasks->pluck('id'))->whereNotNull('weighted_tokens')
            ->selectRaw('miner_task_id, count(*) n')->groupBy('miner_task_id')->pluck('n', 'miner_task_id');
        $out = [];
        foreach ($fullTasks as $t) {
            $p = $out[$t->miner_id] ?? ['planned' => 0, 'run' => 0, 'graded' => 0, 'passed' => 0];
            $p['planned'] += (int) ($t->compression_total ?? $t->baseline_total ?? 0);
            $p['run'] += (int) ($run[$t->id] ?? 0);
            $p['graded'] += (int) $t->finished_runs;
            $p['passed'] += (int) $t->compression_passed;
            $out[$t->miner_id] = $p;
        }

        return $out;
    }

    /**
     * The score each entry stands at now, with the platform's formula (scoring.py; soma-miner harness/soma_score.py) over
     * the SOMA tasks that have at least three graded runs - stage 2 and the full evaluation, the 30 tasks the final score
     * uses. Per task: r = clamp(log2(baseline weighted tokens per run / the entry's), +-2) and quality = passes / graded
     * scaled to the baseline's passes; s = r at quality >= 0.8, linear down to -1 at 0.5, -2 + 2q below. A band (and the
     * overall) is the x^(1/3)-weighted mean of s / 2, x = the baseline's passes. While runs are missing it is a
     * running estimate; the platform's own values replace it once the entry is scored.
     *
     * @param  Collection<int, MinerTask>  $tasks
     * @return array<int, array<string, mixed>>
     */
    private function liveScores(Collection $tasks): array
    {
        if ($tasks->isEmpty()) {
            return [];
        }
        // plain rows: the TaskRun model casts "passed" to a boolean, which would swallow the counts
        $agg = DB::table('task_runs')->whereIn('miner_task_id', $tasks->pluck('id'))
            ->selectRaw('miner_task_id, avg(weighted_tokens) w, sum(passed is not null) graded, sum(passed = 1) npass')
            ->groupBy('miner_task_id')->get()->keyBy('miner_task_id');
        $out = [];
        foreach ($tasks->groupBy('miner_id') as $minerId => $mine) {
            $rows = [];
            foreach ($mine as $t) {
                $a = $agg->get($t->id);
                $tokens = is_array($t->tokens) ? $t->tokens : json_decode((string) $t->tokens, true);
                $x = (int) $t->baseline_passed;
                $n = (int) $t->baseline_total;
                if (! $a || (int) $a->graded < 3 || $x <= 1 || $n < 1) {
                    continue;
                }
                $q = max(0.0, min(1.0, ((int) $a->npass / (int) $a->graded) * $n / $x));
                if ($q <= 0.5) {
                    $s = -2 + 2 * $q;     // the tokens do not matter here (runs that failed at once carry none)
                } elseif (! $a->w || empty($tokens['baseline_weighted_tokens'])) {
                    continue;
                } else {
                    $r = max(-2.0, min(2.0, log(($tokens['baseline_weighted_tokens'] / $n) / (float) $a->w, 2)));
                    $s = $q >= 0.8 ? $r : -1 + ($r + 1) * ($q - 0.5) / 0.3;
                }
                $rows[] = ['band' => $t->complexity, 's' => $s, 'w' => $x ** (1 / 3), 'done' => (int) $a->graded >= (int) ($t->compression_total ?? $n)];
            }
            if (! $rows) {
                continue;
            }
            $score = function (array $rs) {
                $w = array_sum(array_column($rs, 'w'));

                return $w > 0 ? max(-2.0, min(2.0, array_sum(array_map(fn ($r) => $r['s'] * $r['w'], $rs)) / $w)) / 2 : null;
            };
            $live = ['overall' => $score($rows), 'tasks' => count($rows), 'done' => count(array_filter($rows, fn ($r) => $r['done']))];
            foreach (['short', 'medium', 'long'] as $band) {
                $in = array_values(array_filter($rows, fn ($r) => $r['band'] === $band));
                $live[$band] = $in ? $score($in) : null;
                $live[$band.'_tasks'] = count($in);
            }
            $out[$minerId] = $live;
        }

        return $out;
    }

    private function stage1Ranks(Collection $miners): array
    {
        $rank = [];
        $miners->filter(fn (Miner $m) => $m->stage1_score !== null)->sortByDesc('stage1_score')->values()
            ->each(function (Miner $m, int $i) use (&$rank) {
                $rank[$m->id] = $i + 1;
            });

        return $rank;
    }

    /** @var array<int, array<int, int>>|null competition => miner id => rank among scored entries */
    private ?array $scored = null;

    /**
     * Rank among the scored entries of each competition (the platform's place also counts failed-review rows).
     *
     * @return array<int, array<int, int>>
     */
    private function scoredRanks(): array
    {
        if ($this->scored === null) {
            $this->scored = [];
            Miner::where('status', 'scored')->orderBy('competition_id')->orderBy('place')->get(['id', 'competition_id'])
                ->groupBy('competition_id')
                ->each(function (Collection $list, $cid) {
                    foreach ($list->values() as $i => $m) {
                        $this->scored[(int) $cid][$m->id] = $i + 1;
                    }
                });
        }

        return $this->scored;
    }

    /**
     * The same hotkeys in other competitions.
     *
     * @param  array<int, string>  $hotkeys
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function hotkeyHistory(array $hotkeys, int $exclude): array
    {
        $rounds = Competition::pluck('round', 'id');
        $ranks = $this->scoredRanks();

        return Miner::whereIn('hotkey', $hotkeys)->where('competition_id', '!=', $exclude)->orderByDesc('competition_id')->get()
            ->groupBy('hotkey')
            ->map(fn (Collection $list) => $list->map(fn (Miner $m) => [
                'competition' => $m->competition_id,
                'round' => $rounds[$m->competition_id] ?? null,
                'status' => $m->status,
                'place' => $ranks[$m->competition_id][$m->id] ?? null,
                'of' => isset($ranks[$m->competition_id]) ? count($ranks[$m->competition_id]) : null,
                'overall' => $m->overall,
                'stage1' => $m->stage1_score,
                'categories' => ['short' => $m->cat_short, 'medium' => $m->cat_medium, 'long' => $m->cat_long],
            ])->values()->all())
            ->all();
    }

    /**
     * Every earlier-round entry of the miner's hotkey and of its family's coldkeys.
     *
     * @param  array<int, string>  $coldkeys
     * @return array<int, array<string, mixed>>
     */
    private function ownerHistory(Miner $miner, array $coldkeys, int $exclude): array
    {
        $rounds = Competition::pluck('round', 'id');
        $ranks = $this->scoredRanks();

        return Miner::where('competition_id', '!=', $exclude)
            ->where(fn ($q) => $q->where('hotkey', $miner->hotkey)->orWhereIn('coldkey', $coldkeys ?: ['-']))
            ->orderByDesc('competition_id')->orderBy('place')->get()
            ->map(fn (Miner $m) => [
                'competition' => $m->competition_id,
                'round' => $rounds[$m->competition_id] ?? null,
                'hotkey' => $m->hotkey,
                'same_hotkey' => $m->hotkey === $miner->hotkey,
                'status' => $m->status,
                'place' => $ranks[$m->competition_id][$m->id] ?? null,
                'of' => isset($ranks[$m->competition_id]) ? count($ranks[$m->competition_id]) : null,
                'overall' => $m->overall,
                'stage1' => $m->stage1_score,
                'categories' => ['short' => $m->cat_short, 'medium' => $m->cat_medium, 'long' => $m->cat_long],
                'fingerprint' => $m->fingerprint,
            ])->values()->all();
    }

    /**
     * Families with entries in this competition: members, entries by status, the best entry of each stage (overall and
     * per band once the full evaluation runs), earlier rounds, other subnets, link evidence.
     *
     * @param  Collection<int, Miner>  $miners
     * @param  Collection<string, Owner>  $owners
     * @param  array<int, array<string, mixed>>  $live  running full-evaluation estimates by miner id (liveScores)
     * @return array<int, array<string, mixed>>
     */
    private function familySummaries(Competition $competition, Collection $miners, Collection $owners, array $live = []): array
    {
        $byFamily = $miners->filter(fn (Miner $m) => $m->coldkey)->groupBy(fn (Miner $m) => $owners->get($m->coldkey)?->family ?? Owner::familyOf($m->coldkey));
        $allOwners = Owner::pluck('coldkey')->all();
        $direct = collect($this->families->directLinks($allOwners));
        $shared = $this->families->sharedCounterparties($allOwners);
        $rounds = Competition::pluck('round', 'id');
        // A full-evaluation value: the platform's once the entry is scored, the running estimate while it is evaluated.
        // Failed-review and not-advanced entries have none, so they never count as a family's best.
        $value = fn (Miner $m, string $field) => in_array($m->status, ['qualified', 'evaluating', 'scored'], true)
            ? ($field === 'overall' ? $m->overall : $m->{'cat_'.$field}) ?? ($live[$m->id][$field] ?? null)
            : null;

        return $byFamily->map(function (Collection $entries, string $key) use ($direct, $shared, $rounds, $competition, $value) {
            $members = Owner::where('family', $key)->get();
            $coldkeys = $members->pluck('coldkey')->all() ?: $entries->pluck('coldkey')->unique()->values()->all();
            $set = array_flip($coldkeys);
            $earlier = Miner::whereIn('coldkey', $coldkeys)->where('competition_id', '!=', $competition->id)->get()
                ->groupBy('competition_id')->sortKeysDesc()
                ->map(fn (Collection $l, $cid) => [
                    'competition' => (int) $cid,
                    'round' => $rounds[$cid] ?? null,
                    'entries' => $l->count(),
                    'scored' => $l->where('status', 'scored')->count(),
                    'failed_review' => $l->where('status', 'failed review')->count(),
                    'best_place' => $l->where('status', 'scored')->map(fn (Miner $m) => $this->scoredRanks()[(int) $cid][$m->id] ?? null)->filter()->min(),
                    'of' => isset($this->scoredRanks()[(int) $cid]) ? count($this->scoredRanks()[(int) $cid]) : null,
                    'best_overall' => $l->max('overall'),
                ])->values();
            $subnets = OwnerRegistration::whereIn('coldkey', $coldkeys)->where('netuid', '!=', config('soma.netuid'))
                ->selectRaw('netuid, count(*) as n')->groupBy('netuid')->pluck('n', 'netuid');
            $best = $entries->whereNotNull('stage1_score')->sortByDesc('stage1_score')->first();
            $best2 = $entries->whereNotNull('stage2_score')->sortByDesc('stage2_score')->first();
            // The best entry for one value or for an element of several bands: an element is won by one entry, scored as
            // the mean of its band values (incentive_calculator's subset score), so bests of different entries never mix.
            $bestOf = function (string ...$fields) use ($entries, $value) {
                $score = function (Miner $m) use ($fields, $value) {
                    $v = array_map(fn (string $f) => $value($m, $f), $fields);

                    return in_array(null, $v, true) ? null : array_sum($v) / count($v);
                };
                $m = $entries->filter(fn (Miner $m) => $score($m) !== null)->sortByDesc($score)->first();

                return $m ? ['score' => (float) $score($m), 'hotkey' => $m->hotkey, 'place' => $m->place, 'estimate' => $m->status !== 'scored'] : null;
            };

            return [
                'key' => $key,
                'name' => $members->first()?->family_name ?? $key,
                'label' => $members->firstWhere('label')?->label ?? config("soma.owner_labels.$key"),
                'is_ours' => $entries->contains('is_ours', true),
                'owners' => $members->map(fn (Owner $o) => ['coldkey' => $o->coldkey, 'created_on' => $o->created_on?->toDateString(), 'profiled' => (bool) $o->profiled_at])->values(),
                'entries' => $entries->count(),
                'qualified' => $entries->where('status', 'qualified')->count(),
                'screening' => $entries->where('status', 'screening')->count(),
                'statuses' => $entries->countBy(fn (Miner $m) => $m->status ?? 'unknown')->all(),
                'best_stage1' => $best?->stage1_score,
                'best_hotkey' => $best?->hotkey,
                'best_stage2' => $best2 ? ['score' => $best2->stage2_score, 'hotkey' => $best2->hotkey] : null,
                'best_overall' => $bestOf('overall'),
                'best_bands' => ['short' => $bestOf('short'), 'medium' => $bestOf('medium'), 'long' => $bestOf('long')],
                'best_pairs' => ['short+medium' => $bestOf('short', 'medium'), 'short+long' => $bestOf('short', 'long'), 'medium+long' => $bestOf('medium', 'long')],
                'hotkeys' => $entries->sortByDesc('stage1_score')->pluck('hotkey')->values(),
                'earlier' => $earlier,
                'subnets' => $subnets,
                'direct_links' => $direct->filter(fn ($l) => isset($set[$l['a']]) || isset($set[$l['b']]))->values(),
                'shared_wallets' => $shared->filter(fn ($s) => array_intersect($s['owners'], $coldkeys) && $s['kind'] !== 'exchange-like')
                    ->map(fn ($s) => $s + ['others' => array_values(array_diff($s['owners'], $coldkeys))])->values(),
            ];
        })->sortByDesc(fn ($f) => [ // ours first, then the families with a full-evaluation value, then stage 2, then stage 1
            $f['is_ours'] ? 1 : 0,
            $f['best_overall']['score'] ?? -9,
            $f['best_stage2']['score'] ?? -9,
            $f['best_stage1'] ?? -9,
        ])->values()->all();
    }

    /**
     * @param  array<int, string>  $coldkeys
     * @return array<string, mixed>
     */
    private function familyDetail(string $key, array $coldkeys, Competition $competition): array
    {
        $set = array_flip($coldkeys);
        $allOwners = Owner::pluck('coldkey')->all();
        $siblings = Miner::where('competition_id', $competition->id)->whereIn('coldkey', $coldkeys)->orderByDesc('stage1_score')->get()
            ->map(fn (Miner $m) => ['hotkey' => $m->hotkey, 'status' => $m->status, 'stage1' => $m->stage1_score, 'uid' => $m->uid,
                'fingerprint' => $m->fingerprint, 'is_ours' => $m->is_ours, 'our_label' => $m->our_label]);

        return [
            'key' => $key,
            'name' => Owner::where('family', $key)->value('family_name') ?? $key,
            'label' => Owner::where('family', $key)->whereNotNull('label')->value('label') ?? config("soma.owner_labels.$key"),
            'owners' => Owner::whereIn('coldkey', $coldkeys)->get()->map(fn (Owner $o) => [
                'coldkey' => $o->coldkey, 'created_on' => $o->created_on?->toDateString(), 'balance_tao' => $o->balance_tao,
                'profiled_at' => $o->profiled_at?->toIso8601String(),
                'sn114_hotkeys' => Neuron::where('coldkey', $o->coldkey)->where('registered', true)->count(),
            ])->values(),
            'siblings' => $siblings->values(),
            'direct_links' => collect($this->families->directLinks($allOwners))->filter(fn ($l) => isset($set[$l['a']]) || isset($set[$l['b']]))->values(),
            'shared_wallets' => $this->families->sharedCounterparties($allOwners)->filter(fn ($s) => array_intersect($s['owners'], $coldkeys))
                ->map(fn ($s) => $s + ['others' => array_values(array_diff($s['owners'], $coldkeys))])->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ownerDetail(Owner $owner): array
    {
        $owners = array_flip(Owner::pluck('coldkey')->all());
        $transfers = Transfer::where('from_address', $owner->coldkey)->orWhere('to_address', $owner->coldkey)
            ->orderByDesc('transferred_at')->limit(60)->get();
        $wallets = Wallet::whereIn('address', $transfers->pluck('from_address')->merge($transfers->pluck('to_address'))->unique())->get()->keyBy('address');

        return [
            'coldkey' => $owner->coldkey,
            'family' => $owner->family,
            'label' => $owner->label,
            'created_on' => $owner->created_on?->toDateString(),
            'balance_tao' => $owner->balance_tao,
            'profiled_at' => $owner->profiled_at?->toIso8601String(),
            'registrations' => OwnerRegistration::where('coldkey', $owner->coldkey)->orderByDesc('registered_at')->get()
                ->map(fn (OwnerRegistration $r) => ['netuid' => $r->netuid, 'uid' => $r->uid, 'hotkey' => $r->hotkey,
                    'at' => $r->registered_at?->toIso8601String(), 'cost_tao' => $r->cost_tao])->values(),
            'transfers' => $transfers->map(function (Transfer $t) use ($owner, $owners, $wallets) {
                $out = $t->from_address === $owner->coldkey;
                $other = $out ? $t->to_address : $t->from_address;

                return ['at' => $t->transferred_at?->toIso8601String(), 'direction' => $out ? 'out' : 'in', 'tao' => $t->amount_tao,
                    'other' => $other, 'other_is_owner' => isset($owners[$other]), 'other_kind' => $wallets->get($other)?->kind,
                    'other_transfers' => $wallets->get($other)?->transfer_count];
            })->values(),
        ];
    }

    /**
     * Mining rewards per family: SN114 (every day read from Taostats), the last 30 days, the current daily rate of the
     * family's registered hotkeys, by month, and the family's other subnets (read per hotkey).
     *
     * @param  Collection<int, Miner>  $current
     * @return array<int, array<string, mixed>>
     */
    private function familyEarnings(Collection $current): array
    {
        $netuid = (int) config('soma.netuid');
        $owners = Owner::get(['coldkey', 'family', 'family_name', 'label'])->keyBy('coldkey');
        $famOf = fn (string $c) => $owners->get($c)?->family ?? Owner::familyOf($c);
        $fam = [];
        $add = function (string $coldkey, string $field, float $value, ?string $sub = null) use (&$fam, $famOf) {
            $k = $famOf($coldkey);
            $fam[$k]['coldkeys'][$coldkey] = true;
            if ($sub === null) {
                $fam[$k][$field] = ($fam[$k][$field] ?? 0) + $value;
            } else {
                $fam[$k][$field][$sub] = ($fam[$k][$field][$sub] ?? 0) + $value;
            }
        };
        Earning::selectRaw('coldkey, netuid, substr(day, 1, 7) as month, sum(tao) as tao')->groupBy('coldkey', 'netuid', 'month')->get()
            ->each(function ($r) use ($add, $netuid) {
                if ((int) $r->netuid === $netuid) {
                    $add($r->coldkey, 'sn114', (float) $r->tao);
                    $add($r->coldkey, 'months', (float) $r->tao, $r->month);
                } else {
                    $add($r->coldkey, 'others', (float) $r->tao, (string) $r->netuid);
                }
            });
        Earning::where('netuid', $netuid)->where('day', '>=', now('UTC')->subDays(30)->toDateString())
            ->selectRaw('coldkey, sum(tao) as tao')->groupBy('coldkey')->get()
            ->each(fn ($r) => $add($r->coldkey, 'last30', (float) $r->tao));
        Neuron::where('netuid', $netuid)->where('registered', true)->where('daily_tao', '>', 0)
            ->selectRaw('coldkey, sum(daily_tao) as tao')->groupBy('coldkey')->get()
            ->each(fn ($r) => $add($r->coldkey, 'now', (float) $r->tao));
        foreach ($current->filter(fn (Miner $m) => $m->coldkey) as $m) {
            $add($m->coldkey, 'entries', 1);
        }

        return collect($fam)->map(function ($f, $k) use ($owners) {
            $coldkeys = array_keys($f['coldkeys']);
            $owner = collect($coldkeys)->map(fn ($c) => $owners->get($c))->filter()->first();
            $f['months'] ??= [];
            ksort($f['months']);

            return [
                'key' => (string) $k,
                'name' => $owner?->family_name ?? (string) $k,
                'label' => $owner?->label,
                'coldkeys' => $coldkeys,
                'entries' => (int) ($f['entries'] ?? 0),
                'sn114' => round($f['sn114'] ?? 0, 3),
                'last30' => round($f['last30'] ?? 0, 3),
                'now' => round($f['now'] ?? 0, 3),
                'months' => array_map(fn ($v) => round($v, 3), $f['months'] ?? []),
                'others' => array_map(fn ($v) => round($v, 3), $f['others'] ?? []),
                'others_total' => round(array_sum($f['others'] ?? []), 3),
            ];
        })->filter(fn ($f) => $f['entries'] > 0 || $f['sn114'] >= 0.05 || $f['others_total'] >= 0.05 || $f['now'] > 0.01)
            ->sortByDesc(fn ($f) => [$f['sn114'], $f['entries']])->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function earningsCoverage(): array
    {
        $netuid = (int) config('soma.netuid');

        return [
            'days' => DB::table('earning_fetches')->where('scope', 'like', "$netuid:%")->count(),
            'from' => Earning::where('netuid', $netuid)->min('day'),
            'to' => Earning::where('netuid', $netuid)->max('day'),
            'other_hotkeys' => DB::table('earning_fetches')->where('scope', 'not like', "$netuid:%")->count(),
            'queued' => DB::table('jobs')->where('payload', 'like', '%FetchEarnings%')->count(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function events(int $competitionId, ?string $hotkey, int $limit): array
    {
        $q = WatchEvent::where(fn ($w) => $w->where('competition_id', $competitionId)->orWhereNull('competition_id'));
        if ($hotkey) {
            $q->where('hotkey', $hotkey);
        }

        return $q->orderByDesc('occurred_at')->orderByDesc('id')->limit($limit)->get()->map(fn (WatchEvent $e) => [
            'id' => $e->id, 'at' => $e->occurred_at->toIso8601String(), 'type' => $e->type, 'hotkey' => $e->hotkey,
            'coldkey' => $e->coldkey, 'uid' => $e->uid, 'is_ours' => $e->is_ours, 'source' => $e->source, 'data' => $e->data,
        ])->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function watcher(Competition $competition): array
    {
        $pending = DB::table('jobs')->selectRaw('queue, count(*) as n')->groupBy('queue')->pluck('n', 'queue');
        $coldkeys = Miner::where('competition_id', $competition->id)->whereNotNull('coldkey')->distinct()->pluck('coldkey');

        return [
            'leaderboard_synced_at' => $competition->leaderboard_synced_at?->toIso8601String(),
            'metagraph_synced_at' => Neuron::max('last_seen_at') ? \Illuminate\Support\Carbon::parse(Neuron::max('last_seen_at'))->toIso8601String() : null,
            'pending' => $pending,
            // the last 24 h only: 54 old rows (the platform's 503s, 2026-10-29 -> 11-05) read as a live fault on 2026-11-11
            'failed' => DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count(),
            // owners of this competition (all rounds' owners hid the gap), and entries whose owner is still unknown
            'owners_traced' => Owner::whereIn('coldkey', $coldkeys)->whereNotNull('profiled_at')->count(),
            'owners' => $coldkeys->count(),
            'unowned' => Miner::where('competition_id', $competition->id)->whereNull('coldkey')->count(),
            'poll_minutes' => config('soma.poll'),
        ];
    }
}
