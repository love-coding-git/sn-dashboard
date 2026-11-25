<?php

namespace Tests\Feature\Soma;

use App\Services\Soma\DashboardClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DashboardClientTest extends TestCase
{
    public function test_leaderboard_rows_are_decoded_from_the_page(): void
    {
        $rows = [['hotkey' => '5AAA', 'status' => 'qualified', 'note' => 'brackets ] and quotes " inside']];
        $html = '<script>self.__next_f.push([1,'.json_encode('a:{"rows":'.json_encode($rows).',"x":2}').'])</script>';
        Http::fake(['*' => Http::response($html)]);

        $this->assertSame($rows, (new DashboardClient)->leaderboard(148));
    }

    public function test_miner_tasks_are_decoded_and_the_wrong_page_is_refused(): void
    {
        $tasks = [['task_name' => 'sympy__sympy-15875', 'runs' => [['pass_with_compression' => true]]]];
        $html = '<p>5BBB</p><script>self.__next_f.push([1,'.json_encode('b:{"tasks":'.json_encode($tasks).',"y":[1,2]}').'])</script>';
        Http::fake(['*/miner/148/5BBB' => Http::response($html), '*' => Http::response('<p>other</p>')]);

        $this->assertSame($tasks, (new DashboardClient)->minerTasks(148, '5BBB'));
        $this->expectException(\RuntimeException::class);
        (new DashboardClient)->minerTasks(148, '5CCC');
    }

    public function test_a_network_failure_does_not_read_as_a_lost_database_connection(): void
    {
        // 2026-10-22 15:10 UTC: "cURL error 56: Recv failure: Connection reset by peer" stopped the queue worker for good
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 56: Recv failure: Connection reset by peer'));
        $detector = new class
        {
            use \Illuminate\Database\DetectsLostConnections;

            public function check(\Throwable $e): bool
            {
                return $this->causedByLostConnection($e);
            }
        };
        try {
            (new DashboardClient)->leaderboard(149);
            $this->fail('expected a RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('dashboard unreachable', $e->getMessage());
            $this->assertFalse($detector->check($e));
        }
        $this->assertTrue($detector->check(new \Illuminate\Http\Client\ConnectionException('Connection reset by peer')));
    }

    public function test_extract_value_respects_strings_and_nesting(): void
    {
        $s = 'zz[{"a":"]}","b":[1,[2]]},3]tail';
        $this->assertSame('[{"a":"]}","b":[1,[2]]},3]', DashboardClient::extractValue($s, 2));
        $this->assertNull(DashboardClient::extractValue($s, 0));
    }
}
