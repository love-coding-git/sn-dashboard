<?php

namespace Tests\Feature\Soma;

use App\Services\Soma\Fingerprint;
use App\Services\Soma\Stage1;
use Tests\TestCase;

class Stage1Test extends TestCase
{
    public function test_rounds_13_and_14_screen_on_their_own_five_and_earlier_rounds_on_the_swe_bench_five(): void
    {
        $r13 = Stage1::lists(150);
        $this->assertSame(['LMCache__LMCache-4380', 'algorithmicsuperintelligence__openevolve-260', 'bentoml__BentoML-5597',
            'kernc__backtesting.py-1369', 'lastmile-ai__mcp-agent-303'], $r13['problems']);
        $this->assertSame(['kernc__backtesting.py-1369', 'lastmile-ai__mcp-agent-303'], $r13['short']);
        $this->assertCount(3, $r13['longer']);
        $this->assertSame($r13, Stage1::lists(151));   // round 14 keeps round 13's stage-1 tasks
        $this->assertSame(config('soma.stage1_problems'), Stage1::problems(148));
        $this->assertSame(config('soma.stage1_problems'), Stage1::problems(149));
        $this->assertSame(config('soma.stage1_problems'), Stage1::problems());
    }

    public function test_the_per_problem_shape_keeps_only_the_competitions_stage1_problems(): void
    {
        $runs = array_fill(0, 3, ['steps' => 10, 'input' => 1000, 'cached' => 20000, 'output' => 4000, 'passed' => true]);
        $tasks = [
            ['task_name' => 'lastmile-ai__mcp-agent-303', 'runs' => $runs],
            ['task_name' => 'django__django-13821', 'runs' => $runs],
        ];
        $this->assertSame(['lastmile-ai__mcp-agent-303'], array_keys(Fingerprint::perProblem($tasks, 150)));
        $this->assertSame(['django__django-13821'], array_keys(Fingerprint::perProblem($tasks, 148)));
    }
}
