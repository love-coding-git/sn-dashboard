<?php

namespace App\Services\Soma;

use App\Models\Competition;
use App\Models\Miner;
use App\Models\MinerTask;
use App\Models\Neuron;
use App\Models\Owner;
use App\Models\TaskRun;
use App\Models\WatchEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Writes dashboard and chain data into MySQL and detects the watcher's events - the same rules as
 * soma-miner/rounds/round11/tools/watch11.py: a new hotkey or a changed lastSubmit is an upload, leaving "screening" is
 * stage1_done, any other status change is a status event, a hotkey new to the metagraph is a registration.
 */
class Ingest
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return Collection<int, WatchEvent> events created (only when $detect)
     */
    public function leaderboard(Competition $competition, array $rows, bool $detect, ?CarbonInterface $now = null): Collection
    {
        $now ??= now();
        $existing = Miner::where('competition_id', $competition->id)->get()->keyBy('hotkey');
        $events = collect();
        foreach ($rows as $r) {
            $hotkey = $r['hotkey'] ?? null;
            if (! is_string($hotkey) || $hotkey === '') {
                continue;
            }
            $submit = isset($r['lastSubmit']) ? Carbon::parse($r['lastSubmit']) : null;
            // While stage 2 runs the platform sends -1 (sometimes 0) for the stage-2 score and the overall: not scored yet.
            // A finished entry keeps what the platform sends (a failed stage 2 can score -1).
            $unscored = fn ($v) => $v === null || (data_get($r, 'stage2.verdict') === 'running' && in_array((float) $v, [-1.0, 0.0], true));
            // While the full evaluation runs, the overall counts every unfinished task as a failure: a partial value.
            $evaluating = in_array($r['status'] ?? null, ['qualified', 'evaluating'], true);
            $attrs = [
                'place' => $r['place'] ?? null,
                'status' => $r['status'] ?? null,
                'stage1_score' => data_get($r, 'stage1.score'),
                'stage1_verdict' => data_get($r, 'stage1.verdict'),
                'stage2_score' => $unscored(data_get($r, 'stage2.score')) ? null : data_get($r, 'stage2.score'),
                'stage2_verdict' => data_get($r, 'stage2.verdict'),
                'overall' => $evaluating || $unscored($r['overall'] ?? null) ? null : $r['overall'],
                'cat_short' => data_get($r, 'categories.short'),
                'cat_medium' => data_get($r, 'categories.medium'),
                'cat_long' => data_get($r, 'categories.long'),
                'last_submit_at' => $submit,
            ];
            $old = $existing->get($hotkey);
            $type = null;
            if ($detect) {
                if (! $old || self::stamp($old->last_submit_at) !== self::stamp($submit)) {
                    $type = 'upload';
                } elseif ($old->status !== $attrs['status']) {
                    // stage 1 is done only when a running entry reaches a final status ("in queue" is still running)
                    $running = in_array($old->status, ['screening', 'in queue'], true);
                    $final = in_array($attrs['status'], ['qualified', 'not qualified', 'scored', 'failed review'], true);
                    $type = $running && $final ? 'stage1_done' : 'status';
                }
            }
            $miner = Miner::updateOrCreate(
                ['competition_id' => $competition->id, 'hotkey' => $hotkey],
                $attrs + ($old ? [] : ['first_seen_at' => $now]),
            );
            if ($type) {
                $events->push(WatchEvent::create([
                    'occurred_at' => $now,
                    'type' => $type,
                    'competition_id' => $competition->id,
                    'hotkey' => $hotkey,
                    'coldkey' => $miner->coldkey,
                    'uid' => $miner->uid,
                    'is_ours' => (bool) $miner->is_ours,    // a row created just now has no value loaded yet
                    'data' => array_filter([
                        'status' => $attrs['status'],
                        'from' => $old?->status,
                        'stage1' => $attrs['stage1_score'],
                        'lastSubmit' => $r['lastSubmit'] ?? null,
                    ], fn ($v) => $v !== null),
                ]));
            }
        }
        $competition->update(['leaderboard_synced_at' => $now]);

        return $events;
    }

    /**
     * Replace a miner's tasks and runs with what the dashboard shows now, then refresh its fingerprint.
     *
     * @param  array<int, array<string, mixed>>  $tasks
     */
    public function tasks(Miner $miner, array $tasks, ?array $referenceShape = null): void
    {
        DB::transaction(function () use ($miner, $tasks) {
            foreach ($tasks as $t) {
                if (! isset($t['task_name'])) {
                    continue;
                }
                $runs = array_values($t['runs'] ?? []);
                $finished = array_values(array_filter($runs, fn ($r) => ($r['pass_with_compression'] ?? null) !== null));
                $steps = array_values(array_filter(array_map(fn ($r) => $r['agent_steps'] ?? null, $finished)));
                $tokens = $t['tokens'] ?? null;
                $base = (float) ($tokens['baseline_weighted_tokens'] ?? 0);
                $task = MinerTask::updateOrCreate(['miner_id' => $miner->id, 'task_name' => $t['task_name']], [
                    'platform_task_id' => $t['task_id'] ?? null,
                    'is_screener' => (bool) ($t['is_screener'] ?? false),
                    'screener_stage' => $t['screener_stage'] ?? null,
                    'complexity' => $t['complexity'] ?? null,
                    'benchmark_type' => $t['benchmarkType'] ?? null,
                    'run_count' => $t['run_count'] ?? null,
                    'baseline_passed' => data_get($t, 'runCounts.baselinePassed'),
                    'baseline_total' => data_get($t, 'runCounts.baselineTotal'),
                    'compression_passed' => data_get($t, 'runCounts.compressionPassed'),
                    'compression_total' => data_get($t, 'runCounts.compressionTotal'),
                    'finished_runs' => count($finished),
                    'platform_score' => $t['platform_score'] ?? null,
                    'weighted_ratio' => $base > 0 ? ((float) ($tokens['miner_weighted_tokens'] ?? 0)) / $base : null,
                    'mean_steps' => $steps ? array_sum($steps) / count($steps) : null,
                    'tokens' => $tokens,
                ]);
                TaskRun::where('miner_task_id', $task->id)->delete();
                $rows = [];
                foreach ($runs as $i => $r) {
                    $passed = $r['pass_with_compression'] ?? null;
                    $rows[] = [
                        'miner_task_id' => $task->id,
                        'seq' => $i,
                        'run_id' => $r['run_id'] ?? null,
                        'attempt_no' => $r['attempt_no'] ?? null,
                        'passed' => $passed === null ? null : (int) (bool) $passed,
                        'agent_steps' => $r['agent_steps'] ?? null,
                        'input_tokens' => $r['input_tokens_with_compression'] ?? null,
                        'cached_input_tokens' => $r['cached_input_tokens_with_compression'] ?? null,
                        'output_tokens' => $r['output_tokens_with_compression'] ?? null,
                        'jev_input_tokens' => $r['jev_input_tokens_with_compression'] ?? null,
                        'weighted_tokens' => $r['weighted_tokens_with_compression'] ?? null,
                        'time_taken_seconds' => $r['time_taken_seconds'] ?? null,
                    ];
                }
                foreach (array_chunk($rows, 500) as $chunk) {
                    TaskRun::insert($chunk);
                }
            }
            // Tasks the page no longer lists (a rolled-back stage-2 selection takes the full-evaluation tasks away).
            $shown = array_values(array_filter(array_map(fn ($t) => $t['task_name'] ?? null, $tasks)));
            if ($shown) {
                $stale = MinerTask::where('miner_id', $miner->id)->whereNotIn('task_name', $shown)->pluck('id');
                TaskRun::whereIn('miner_task_id', $stale)->delete();
                MinerTask::whereIn('id', $stale)->delete();
            }
        }, 5);    // retried on deadlock: the queue and an import can rewrite runs at the same time
        $miner->forceFill([
            'tasks_synced_at' => now(),
            'fingerprint' => $this->fingerprint($miner, $referenceShape),
        ])->save();
    }

    /**
     * Stage-1 shape of a miner, or null while a stage-1 problem still has unfinished runs (the runs that finish
     * first are the short ones - soma-miner/docs/REGRESSIONS.md 43).
     *
     * @return array<string, array<string, mixed>>|null
     */
    public function stage1Shape(Miner $miner): ?array
    {
        $problems = Stage1::problems($miner->competition_id);
        $tasks = MinerTask::where('miner_id', $miner->id)->where('is_screener', true)
            ->whereIn('task_name', $problems)->with('runs')->get();
        if ($tasks->count() < count($problems)) {
            return null;
        }
        foreach ($tasks as $t) {
            if ($t->baseline_total && $t->finished_runs < $t->baseline_total) {
                return null;
            }
        }

        return Fingerprint::perProblem($tasks->map(fn (MinerTask $t) => [
            'task_name' => $t->task_name,
            'runs' => $t->runs->map(fn (TaskRun $r) => [
                'steps' => (int) $r->agent_steps,
                'input' => (int) $r->input_tokens,
                'cached' => (int) $r->cached_input_tokens,
                'output' => (int) $r->output_tokens,
                'passed' => $r->passed,
            ])->all(),
        ])->all(), $miner->competition_id);
    }

    /**
     * The reference miner's stage-1 shape in a competition (config soma.reference_hotkey_prefix).
     *
     * @return array<string, array<string, mixed>>|null
     */
    public function referenceShape(int $competitionId): ?array
    {
        $ref = Miner::where('competition_id', $competitionId)
            ->where('hotkey', 'like', config('soma.reference_hotkey_prefix').'%')->first();

        return $ref ? $this->stage1Shape($ref) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fingerprint(Miner $miner, ?array $referenceShape = null): ?array
    {
        if ($referenceShape === []) {
            return null;    // the caller fills fingerprints later (bulk import)
        }
        $shape = $this->stage1Shape($miner);
        $ref = $referenceShape ?? $this->referenceShape($miner->competition_id);
        $vsReference = $shape && $ref ? Fingerprint::compare($shape, $ref, $miner->competition_id) : null;
        $tasks = MinerTask::where('miner_id', $miner->id)->where('is_screener', true)->whereNull('complexity')
            ->get()->map(fn (MinerTask $t) => ['task_name' => $t->task_name, 'tokens' => $t->tokens, 'ratio' => $t->weighted_ratio, 'steps' => $t->mean_steps, 'baseline_total' => $t->baseline_total])->all();
        $steps = in_array($miner->competition_id, config('soma.untouched_steps_competitions'), true) ? config('soma.untouched_output_per_step') : null;
        // the untouched part needs every stage-1 problem finished, like the reference part
        $vsUntouched = $shape !== null && count($shape) === count(Stage1::problems($miner->competition_id)) ? Fingerprint::untouched($tasks, $steps, $miner->competition_id) : null;

        return $vsReference || $vsUntouched ? [...($vsReference ?? []), ...($vsUntouched ?? [])] : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows  metagraph rows (Taostats or the watcher's state file)
     * @return Collection<int, WatchEvent>
     */
    public function metagraph(int $netuid, array $rows, bool $detect, ?CarbonInterface $now = null): Collection
    {
        $now ??= now();
        $known = Neuron::where('netuid', $netuid)->where('registered', true)->pluck('hotkey')->flip();
        $first = Neuron::where('netuid', $netuid)->doesntExist();
        $oursCold = $this->oursColdkeys();
        $seen = [];
        $events = collect();
        foreach ($rows as $r) {
            $hotkey = Taostats::ss58($r['hotkey'] ?? null);
            $coldkey = Taostats::ss58($r['coldkey'] ?? null);
            if ($hotkey === '' || $coldkey === '') {
                continue;
            }
            $seen[] = $hotkey;
            $neuron = Neuron::firstOrNew(['netuid' => $netuid, 'hotkey' => $hotkey]);
            $neuron->fill([
                'coldkey' => $coldkey,
                'uid' => $r['uid'] ?? null,
                'registered_at_block' => $r['registered_at_block'] ?? ($r['block'] ?? null),
                'daily_tao' => isset($r['daily_mining_alpha_as_tao']) ? ((float) $r['daily_mining_alpha_as_tao']) / 1e9 : $neuron->daily_tao,
                'incentive' => isset($r['incentive']) ? (float) $r['incentive'] : $neuron->incentive,
                'registered' => true,
                'last_seen_at' => $now,
            ]);
            $neuron->first_seen_at ??= $now;
            $neuron->save();
            Owner::ensure($coldkey);
            if ($detect && ! $first && ! $known->has($hotkey)) {
                $events->push(WatchEvent::create([
                    'occurred_at' => $now,
                    'type' => 'registration',
                    'competition_id' => config('soma.competition'),
                    'hotkey' => $hotkey,
                    'coldkey' => $coldkey,
                    'uid' => $r['uid'] ?? null,
                    'is_ours' => in_array($coldkey, $oursCold, true),
                    'data' => ['block' => $r['registered_at_block'] ?? null],
                ]));
            }
        }
        if ($seen) {
            Neuron::where('netuid', $netuid)->whereNotIn('hotkey', $seen)->update(['registered' => false]);
        }
        $this->linkMiners();

        return $events;
    }

    /**
     * Copy coldkey and uid from the chain onto miners, then mark our entries (S1, S2, … per competition, by upload time).
     */
    public function linkMiners(): void
    {
        $chain = Neuron::where('netuid', config('soma.netuid'))->get(['hotkey', 'coldkey', 'uid', 'registered'])->keyBy('hotkey');
        Miner::query()->select(['id', 'hotkey', 'coldkey', 'uid'])->chunkById(500, function ($miners) use ($chain) {
            foreach ($miners as $m) {
                $n = $chain->get($m->hotkey);
                if ($n && ($m->coldkey !== $n->coldkey || ($n->registered && $m->uid !== $n->uid))) {
                    Miner::whereKey($m->id)->update(['coldkey' => $n->coldkey, 'uid' => $n->registered ? $n->uid : $m->uid]);
                }
            }
        });
        $ours = $this->oursColdkeys();
        Miner::query()->update(['is_ours' => false, 'our_label' => null]);
        if (! $ours) {
            return;
        }
        \App\Models\Owner::whereIn('coldkey', $ours)->update(['label' => 'ours']);
        Miner::whereIn('coldkey', $ours)->orderBy('last_submit_at')->get()->groupBy('competition_id')
            ->each(function (Collection $list) {
                $list->values()->each(fn (Miner $m, int $i) => $m->forceFill(['is_ours' => true, 'our_label' => 'S'.($i + 1)])->save());
            });
    }

    /**
     * Coldkeys of our known hotkeys (config soma.ours_hotkey_prefixes); later entries share these coldkeys.
     *
     * @return array<int, string>
     */
    public function oursColdkeys(): array
    {
        $q = Neuron::query();
        $q->where(function ($w) {
            foreach (config('soma.ours_hotkey_prefixes') as $p) {
                $w->orWhere('hotkey', 'like', $p.'%');
            }
        });
        $fromChain = $q->pluck('coldkey');
        $fromMiners = Miner::query()->whereNotNull('coldkey')->where(function ($w) {
            foreach (config('soma.ours_hotkey_prefixes') as $p) {
                $w->orWhere('hotkey', 'like', $p.'%');
            }
        })->pluck('coldkey');

        return $fromChain->merge($fromMiners)->filter()->unique()->values()->all();
    }

    private static function stamp(?CarbonInterface $t): ?string
    {
        return $t?->format('Y-m-d H:i:s');    // Eloquent stores whole seconds
    }
}
