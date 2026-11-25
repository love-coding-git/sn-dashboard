<?php

namespace Tests\Feature\Soma;

use App\Models\Earning;
use App\Models\Owner;
use App\Services\Soma\Earnings;
use App\Services\Soma\OwnerProfiler;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Taostats retired its /api/ paths (410 from 2026-11-16 22:xx UTC); the /v1 API caps most pages at 200 rows. */
class TaostatsV1Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $env = tempnam(sys_get_temp_dir(), 'soma-env');
        file_put_contents($env, "TAO_API_KEY=test\n");
        config(['soma.keys_env' => $env, 'soma.family_merges' => [], 'soma.family_names' => [], 'soma.dossier_dir' => '/nonexistent',
            'soma.family_link_wallet_kinds' => [], 'soma.ours_hotkey_prefixes' => []]);
    }

    public function test_a_subnet_day_reads_every_page_of_the_metagraph_history(): void
    {
        $row = fn (string $hotkey, string $tao) => ['hotkey' => $hotkey, 'coldkey' => '5COLD'.$hotkey, 'timestamp' => '2026-11-15T23:33:00.000Z',
            'daily_mining_alpha_as_tao' => $tao, 'daily_mining_alpha' => '45044632', 'incentive' => '0.5'];
        Http::fake(function ($request) use ($row) {
            $this->assertStringContainsString('/v1/subnets/metagraph/history', $request->url());
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
            $this->assertSame('200', $q['limit']);

            return ($q['page'] ?? '1') === '1'
                ? Http::response(['data' => [$row('5HKA', '742052')], 'pagination' => ['next_page' => 2]])
                : Http::response(['data' => [$row('5HKB', '1000000000')], 'pagination' => ['next_page' => null]]);
        });

        $this->assertSame(2, (new Earnings(app(\App\Services\Soma\Taostats::class)))->subnetDay(114, Carbon::parse('2026-11-15')));
        $this->assertEqualsWithDelta(0.000742052, Earning::where('hotkey', '5HKA')->value('tao'), 1e-12);
        $this->assertEqualsWithDelta(1.0, Earning::where('hotkey', '5HKB')->value('tao'), 1e-12);
    }

    public function test_a_failed_read_leaves_the_owner_as_it_was(): void
    {
        $owner = Owner::ensure('5COLDOWNER');
        $owner->update(['balance_tao' => 1.5, 'created_on' => '2026-10-01']);
        Http::fake(['*' => Http::response(['error' => 'This endpoint has been retired.'], 410)]);

        try {
            app(OwnerProfiler::class)->profile('5COLDOWNER');
            $this->fail('the trace should stop on a failed read');
        } catch (\RuntimeException $e) {
            $this->assertSame('taostats: 410', $e->getMessage());
        }
        $owner->refresh();
        $this->assertSame(1.5, $owner->balance_tao);
        $this->assertNull($owner->profiled_at);
    }
}
