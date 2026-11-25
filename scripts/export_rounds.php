<?php
// Export the dashboard MySQL data (rounds 9-11: entries, tasks, runs, owners) to JSON for soma-miner/rounds/round11/harness/compare11.
// Usage: php scripts/export_rounds.php <out.json>   (run from the soma-dashboard folder)
require '/home/admin/projects/sn-dashboard/vendor/autoload.php';
$app = require '/home/admin/projects/sn-dashboard/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$comps = [148, 147, 146];
$miners = DB::table('miners')->whereIn('competition_id', $comps)->get();
$owners = DB::table('owners')->get()->keyBy('coldkey');
$neurons = DB::table('neurons')->get()->keyBy('hotkey');
$tasks = DB::table('miner_tasks')->whereIn('miner_id', $miners->pluck('id'))->get()->groupBy('miner_id');
$runs = DB::table('task_runs')->whereIn('miner_task_id', $tasks->flatten()->pluck('id'))
    ->orderBy('miner_task_id')->orderBy('seq')->get()->groupBy('miner_task_id');

$out = [];
foreach ($miners as $m) {
    $ck = $m->coldkey ?? ($neurons[$m->hotkey]->coldkey ?? null);
    $o = $ck ? ($owners[$ck] ?? null) : null;
    $out[] = [
        'comp' => $m->competition_id, 'hotkey' => $m->hotkey, 'coldkey' => $ck, 'uid' => $m->uid, 'status' => $m->status,
        'place' => $m->place, 'stage1' => $m->stage1_score, 'stage1_verdict' => $m->stage1_verdict,
        'stage2' => $m->stage2_score, 'stage2_verdict' => $m->stage2_verdict, 'overall' => $m->overall,
        'cat' => ['short' => $m->cat_short, 'medium' => $m->cat_medium, 'long' => $m->cat_long],
        'last_submit' => $m->last_submit_at, 'is_ours' => (bool) $m->is_ours, 'label' => $m->our_label,
        'fingerprint' => $m->fingerprint ? json_decode($m->fingerprint, true) : null,
        'family' => $o->family ?? null, 'family_name' => $o->family_name ?? null,
        'tasks' => collect($tasks[$m->id] ?? [])->map(fn ($t) => [
            'name' => $t->task_name, 'screener' => (bool) $t->is_screener, 'complexity' => $t->complexity,
            'base' => [$t->baseline_passed, $t->baseline_total], 'comp' => [$t->compression_passed, $t->compression_total],
            'finished' => $t->finished_runs, 'score' => $t->platform_score, 'ratio' => $t->weighted_ratio, 'steps' => $t->mean_steps,
            'runs' => collect($runs[$t->id] ?? [])->map(fn ($r) => [
                'passed' => $r->passed === null ? null : (bool) $r->passed, 'steps' => $r->agent_steps, 'in' => $r->input_tokens,
                'cached' => $r->cached_input_tokens, 'out' => $r->output_tokens, 'jev' => $r->jev_input_tokens,
                'w' => $r->weighted_tokens, 'secs' => $r->time_taken_seconds,
            ])->values(),
        ])->values(),
    ];
}
file_put_contents($argv[1], json_encode(['exported_at' => gmdate('Y-m-d H:i:s').' UTC', 'miners' => $out]));
echo count($out), " miners, ", $tasks->flatten()->count(), " tasks, ", $runs->flatten()->count(), " runs\n";
