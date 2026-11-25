<?php

namespace App\Services\Soma;

/**
 * Stage-1 fingerprint of a miner against a reference miner of the same competition - the same numbers as
 * soma-miner/rounds/round11/tools/rival_profile.py: steps, steps on the two short problems, output per step, context at step 15
 * on the three longer problems, and the re-billing slope (uncached input per step over output per step).
 */
class Fingerprint
{
    /**
     * Per-problem shape from stage-1 tasks. Each task: ['task_name' => ..., 'tokens' => [...], 'platform_score' => ...,
     * 'baseline' => 'x/y', 'runs' => [['steps' => int, 'input' => int, 'cached' => int, 'output' => int, 'passed' => bool], ...]].
     *
     * @param  array<int, array<string, mixed>>  $tasks
     * @return array<string, array<string, mixed>>
     */
    public static function perProblem(array $tasks, ?int $competitionId = null): array
    {
        $out = [];
        $problems = Stage1::problems($competitionId);
        foreach ($tasks as $t) {
            if (! in_array($t['task_name'], $problems, true)) {
                continue;
            }
            $runs = array_values(array_filter($t['runs'], fn ($r) => $r['passed'] !== null && $r['steps'] > 0));
            if (count($runs) < 3) {
                continue;
            }
            $steps = array_sum(array_column($runs, 'steps'));
            [$c0, $g] = self::fit(
                array_map(fn ($r) => ($r['steps'] - 1) / 2, $runs),
                array_map(fn ($r) => ($r['input'] + $r['cached']) / $r['steps'], $runs),
            );
            [, $slope] = self::fit(array_column($runs, 'steps'), array_column($runs, 'input'));
            $ops = array_sum(array_column($runs, 'output')) / $steps;
            $out[$t['task_name']] = [
                'n' => count($runs),
                'pass' => count(array_filter($runs, fn ($r) => $r['passed'])),
                'steps' => $steps / count($runs),
                'ops' => $ops,
                'ctx15' => $c0 + 7 * $g,
                'unc' => $ops > 0 ? $slope / $ops : null,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, array<string, mixed>>  $p
     * @param  array<string, array<string, mixed>>  $ref
     * @return array<string, mixed>|null
     */
    public static function compare(array $p, array $ref, ?int $competitionId = null): ?array
    {
        $lists = Stage1::lists($competitionId);
        $all = $lists['problems'];
        foreach ($all as $t) {
            if (! isset($p[$t], $ref[$t])) {
                return null;
            }
        }
        $sum = fn (array $x, array $names) => array_sum(array_map(fn ($t) => $x[$t]['steps'], $names));
        $steps = $sum($p, $all) / $sum($ref, $all);
        $short = $sum($p, $lists['short']) / $sum($ref, $lists['short']);
        $ops = exp(array_sum(array_map(fn ($t) => log($p[$t]['ops'] / $ref[$t]['ops']), $all)) / count($all));
        $longer = $lists['longer'];
        $ctx = array_sum(array_map(fn ($t) => $p[$t]['ctx15'] / $ref[$t]['ctx15'], $longer)) / count($longer);
        $unc = self::median(array_filter(array_map(fn ($t) => $p[$t]['unc'], $all), fn ($v) => $v !== null));
        $class = match (true) {
            $steps < 0.3 => 'failed-runs',    // runs died within a step or two - not a design
            $steps <= 0.75 && $short <= 0.75 => 'deep-all',
            $short <= 0.75 => 'short-only',
            $steps <= 0.92 => 'notice-like',
            default => 'light',
        };

        return [
            'steps_x' => round($steps, 3),
            'short_x' => round($short, 3),
            'out_step_x' => round($ops, 3),
            'ctx_x' => round($ctx, 3),
            'rebill_slope' => $unc === null ? null : round($unc, 2),
            'class' => $class,
        ];
    }

    /**
     * Stage-1 fingerprint against the untouched agent. Tokens per run come from the platform's own figures for each task
     * (the entry's runs and the untouched baseline's runs, both published), so they are exact; the ratio of a component
     * is (its share of the entry's weighted tokens / its share of the baseline's) x the platform's weighted ratio, which
     * needs no run counts. The platform does not publish the baseline's steps: they are estimated as the baseline's
     * output tokens per run / the untouched agent's output per step (config soma.untouched_output_per_step) - approximate.
     *
     * @param  array<int, array{task_name: string, tokens: array<string, float|int>|null, ratio: float|null, steps: float|null, baseline_total: int|null}>  $tasks
     * @param  array<string, float>|null  $outputPerStep
     * @return array<string, float|null>|null
     */
    public static function untouched(array $tasks, ?array $outputPerStep, ?int $competitionId = null): ?array
    {
        $lists = Stage1::lists($competitionId);
        $all = $lists['problems'];
        $by = [];
        foreach ($tasks as $t) {
            if (in_array($t['task_name'], $all, true)) {
                $by[$t['task_name']] = $t;
            }
        }
        $logs = ['tokens' => [], 'output' => [], 'uncached' => [], 'cached' => []];
        foreach ($all as $name) {
            $t = $by[$name] ?? null;
            $k = $t['tokens'] ?? null;
            if (! $t || ! $k || ! ($t['ratio'] > 0) || ! ($k['miner_weighted_tokens'] ?? 0) || ! ($k['baseline_weighted_tokens'] ?? 0)) {
                return null;
            }
            $logs['tokens'][] = log($t['ratio']);
            foreach (['output' => 'output_tokens', 'uncached' => 'input_tokens', 'cached' => 'cached_input_tokens'] as $key => $field) {
                $mine = ($k["miner_{$field}"] ?? 0) / $k['miner_weighted_tokens'];
                $base = ($k["baseline_{$field}"] ?? 0) / $k['baseline_weighted_tokens'];
                if ($mine <= 0 || $base <= 0) {
                    return null;
                }
                $logs[$key][] = log($mine / $base * $t['ratio']);
            }
        }
        $geo = fn (array $xs) => round(exp(array_sum($xs) / count($xs)), 3);
        $out = [
            'u_tokens_x' => $geo($logs['tokens']),
            'u_output_x' => $geo($logs['output']),
            'u_uncached_x' => $geo($logs['uncached']),
            'u_cached_x' => $geo($logs['cached']),
            'u_steps_x' => null,
            'u_short_x' => null,
        ];
        $baseSteps = [];
        foreach ($all as $name) {
            $t = $by[$name];
            $perRun = ($t['baseline_total'] ?? 0) > 0 ? ($t['tokens']['baseline_output_tokens'] ?? 0) / $t['baseline_total'] : 0;
            if (! $outputPerStep || ! ($outputPerStep[$name] ?? 0) || $perRun <= 0 || ! (($t['steps'] ?? 0) > 0)) {
                $baseSteps = null;
                break;
            }
            $baseSteps[$name] = $perRun / $outputPerStep[$name];
        }
        if ($baseSteps) {
            $sum = fn (array $names, callable $get) => array_sum(array_map($get, $names));
            $out['u_steps_x'] = round($sum($all, fn ($n) => $by[$n]['steps']) / $sum($all, fn ($n) => $baseSteps[$n]), 3);
            $short = $lists['short'];
            $out['u_short_x'] = round($sum($short, fn ($n) => $by[$n]['steps']) / $sum($short, fn ($n) => $baseSteps[$n]), 3);
        }

        return $out;
    }

    /**
     * Least squares line: [intercept, slope].
     *
     * @param  array<int, float|int>  $xs
     * @param  array<int, float|int>  $ys
     * @return array{0: float, 1: float}
     */
    public static function fit(array $xs, array $ys): array
    {
        $n = count($xs);
        $mx = array_sum($xs) / $n;
        $my = array_sum($ys) / $n;
        $sxx = 0.0;
        $sxy = 0.0;
        foreach ($xs as $i => $x) {
            $sxx += ($x - $mx) ** 2;
            $sxy += ($x - $mx) * ($ys[$i] - $my);
        }
        $b = $sxx > 0 ? $sxy / $sxx : 0.0;

        return [$my - $b * $mx, $b];
    }

    /**
     * @param  array<int, float>  $values
     */
    public static function median(array $values): ?float
    {
        $values = array_values($values);
        sort($values);
        $n = count($values);
        if ($n === 0) {
            return null;
        }

        return $n % 2 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
    }
}
