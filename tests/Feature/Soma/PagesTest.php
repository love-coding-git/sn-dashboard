<?php

namespace Tests\Feature\Soma;

use App\Models\Competition;
use App\Models\Owner;
use App\Services\Soma\Ingest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['soma.competition' => 148]);   // the fixtures are a round-11 competition; the watcher's own round moves on
    }

    public function test_overview_and_miner_pages_render(): void
    {
        $ingest = new Ingest;
        $c = Competition::ensure(148);
        $ingest->leaderboard($c, [['hotkey' => '5AAA', 'status' => 'qualified', 'lastSubmit' => '2026-10-12T10:00:00Z', 'stage1' => ['score' => 0.3]]], false);
        $miner = $c->miners()->first();
        $ingest->tasks($miner, [[
            'task_name' => 'sympy__sympy-15875', 'is_screener' => true,
            'runCounts' => ['baselinePassed' => 11, 'baselineTotal' => 20, 'compressionPassed' => 1, 'compressionTotal' => 20],
            'runs' => [['pass_with_compression' => true, 'agent_steps' => 20, 'input_tokens_with_compression' => 100, 'cached_input_tokens_with_compression' => 1000, 'output_tokens_with_compression' => 50]],
        ]]);

        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->component('hub'));
        $this->get(route('soma.overview'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('soma/overview')
            ->has('miners', 1)
            ->where('miners.0.hotkey', '5AAA')
            ->where('miners.0.stage1.sympy__sympy-15875.passed', 1));
        $this->get(route('soma.miner', ['competition' => 148, 'hotkey' => '5AAA']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('soma/miner')
            ->where('miner.hotkey', '5AAA')
            ->has('tasks', 1));
        $this->get(route('soma.miner', ['competition' => 148, 'hotkey' => 'nope']))->assertNotFound();
    }

    public function test_qualified_entries_read_as_stage_one_until_the_upload_deadline(): void
    {
        $c = Competition::ensure(148);
        (new Ingest)->leaderboard($c, [['hotkey' => '5AAA', 'status' => 'qualified', 'lastSubmit' => '2026-10-12T10:00:00Z', 'stage1' => ['score' => 0.3]]], false);

        $c->update(['upload_deadline_at' => now()->addDay()]);
        $this->get(route('soma.overview'))->assertInertia(fn (Assert $page) => $page->where('phase', 'stage1'));
        $c->update(['upload_deadline_at' => now()->subDay()]);
        $this->get(route('soma.overview'))->assertInertia(fn (Assert $page) => $page->where('phase', 'full'));
    }

    public function test_families_rank_by_the_full_evaluation_once_it_is_scored(): void
    {
        $ingest = new Ingest;
        $c = Competition::ensure(148);
        $row = fn (string $hotkey, string $status, ?float $stage1, ?float $overall, array $bands = []) => [
            'hotkey' => $hotkey, 'status' => $status, 'lastSubmit' => '2026-10-12T10:00:00Z', 'stage1' => ['score' => $stage1],
            'overall' => $overall, 'categories' => $bands,
        ];
        $ingest->leaderboard($c, [
            $row('5AAA1', 'scored', 0.30, 0.35, ['short' => 0.31, 'medium' => 0.28, 'long' => 0.47]),
            $row('5AAA2', 'scored', 0.36, 0.32, ['short' => 0.36, 'medium' => 0.20, 'long' => 0.42]),
            $row('5AAA3', 'failed review', 0.60, 0.90, ['short' => 0.90, 'medium' => 0.90, 'long' => 0.90]),
            $row('5AAA4', 'not qualified', 0.10, null),
            $row('5BBB1', 'scored', 0.55, 0.61, ['short' => 0.57, 'medium' => 0.60, 'long' => 0.66]),
        ], false);
        foreach (['5AAA1', '5AAA2', '5AAA3', '5AAA4'] as $hk) {
            $c->miners()->where('hotkey', $hk)->update(['coldkey' => '5FAMILYA']);
        }
        $c->miners()->where('hotkey', '5BBB1')->update(['coldkey' => '5FAMILYB']);
        Owner::ensure('5FAMILYA');
        Owner::ensure('5FAMILYB');

        $this->get(route('soma.overview'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('phase', 'scored')
            ->where('families.0.key', '5FAMILYB')
            ->where('families.1.key', '5FAMILYA')
            // the failed-review entry never counts; the best per band may come from different entries
            ->where('families.1.best_overall.score', 0.35)
            ->where('families.1.best_overall.hotkey', '5AAA1')
            ->where('families.1.best_overall.estimate', false)
            ->where('families.1.best_bands.long.score', 0.47)
            ->where('families.1.best_bands.short.hotkey', '5AAA2')
            // an element is one entry's mean of its bands: 5AAA1 (0.31 + 0.28) / 2, not the two band bests mixed (0.32)
            ->where('families.1.best_pairs.short+medium.hotkey', '5AAA1')
            ->where('families.1.best_pairs.short+medium.score', fn ($v) => abs($v - 0.295) < 1e-9)
            ->where('families.1.best_pairs.medium+long.score', fn ($v) => abs($v - 0.375) < 1e-9)
            ->where('families.1.best_stage1', 0.6)
            ->where('families.1.statuses', ['scored' => 2, 'failed review' => 1, 'not qualified' => 1]));
    }
}
