<?php

namespace Tests\Feature\Soma;

use App\Jobs\Soma\ProfileOwner;
use App\Jobs\Soma\ResolveOwner;
use App\Models\Competition;
use App\Models\Miner;
use App\Models\Owner;
use App\Models\OwnerRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** An entry whose hotkey left the metagraph before a poll saw it gets its owner from the SN114 registration record. */
class ResolveOwnerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $env = tempnam(sys_get_temp_dir(), 'soma-env');
        file_put_contents($env, "TAO_API_KEY=test\n");
        config(['soma.keys_env' => $env, 'soma.competition' => 150, 'soma.family_merges' => [], 'soma.family_names' => [],
            'soma.dossier_dir' => '/nonexistent', 'soma.family_link_wallet_kinds' => [], 'soma.ours_hotkey_prefixes' => []]);
    }

    public function test_the_owner_of_a_deregistered_hotkey_comes_from_its_registration(): void
    {
        Queue::fake();
        $c = Competition::ensure(150);
        $hotkey = str_pad('5HKAAAAA', 48, 'h');
        $coldkey = str_pad('5CKBBBBB', 48, 'c');
        Miner::create(['competition_id' => $c->id, 'hotkey' => $hotkey]);
        Http::fake(['*/v1/subnets/neuron-registration-events*' => Http::response(['data' => [
            ['block_number' => 100, 'timestamp' => '2026-11-09T19:57:24Z', 'netuid' => 114, 'uid' => 49, 'hotkey' => ['ss58' => $hotkey],
                'coldkey' => ['ss58' => $coldkey], 'registration_cost' => 5e8],
        ]])]);

        $queued = ResolveOwner::queueMissing();
        $this->assertSame([$hotkey], $queued->all());
        Queue::assertPushed(ResolveOwner::class, fn (ResolveOwner $j) => $j->hotkey === $hotkey);
        $this->assertCount(0, ResolveOwner::queueMissing(), 'a hotkey is asked at most once per 15 minutes');
        $this->travel(16)->minutes();
        $this->assertSame([$hotkey], ResolveOwner::queueMissing()->all(), 'and asked again after them');
        $this->travelBack();

        app()->call([new ResolveOwner($hotkey), 'handle']);
        $miner = Miner::where('hotkey', $hotkey)->first();
        $this->assertSame($coldkey, $miner->coldkey);
        $this->assertNull($miner->uid, 'a deregistered uid belongs to someone else now');
        $this->assertNotNull(Owner::where('coldkey', $coldkey)->value('family'));
        $this->assertSame(49, OwnerRegistration::where('hotkey', $hotkey)->value('uid'));
        Queue::assertPushed(ProfileOwner::class, fn (ProfileOwner $j) => $j->coldkey === $coldkey);
    }

    public function test_owner_jobs_run_before_the_earnings_reads(): void
    {
        // the Taostats worker reads --queue=resolve,owners,profile: lookups first, then traces, then earnings
        $this->assertSame('resolve', (new ResolveOwner('5X'))->queue);
        $this->assertSame('owners', (new ProfileOwner('5X'))->queue);
        $this->assertSame('profile', (new \App\Jobs\Soma\FetchEarnings(114, null, '5X'))->queue);
        $this->assertStringContainsString('--queue=resolve,owners,profile', file_get_contents(base_path('bin/soma-watch')));
    }

    public function test_a_trace_reads_the_oldest_transfer_pages_too(): void
    {
        Queue::fake();
        $coldkey = str_pad('5CKOLDXX', 48, 'c');
        $funder = str_pad('5FUNDERX', 48, 'f');
        $transfer = fn (string $id, string $from, string $to, string $ts) => ['id' => $id, 'from' => ['ss58' => $from], 'to' => ['ss58' => $to],
            'amount' => 1e9, 'block_number' => 1, 'timestamp' => $ts];
        Http::fake(function ($request) use ($coldkey, $funder, $transfer) {
            $q = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
            if (str_contains($request->url(), '/v1/transfers') && ($q['address'] ?? '') === $coldkey) {
                $page = (int) ($q['page'] ?? 1);
                $data = ['1' => [$transfer('t-new', $coldkey, '5OTHER', '2026-11-10T00:00:00Z')], '2' => [$transfer('t-mid', '5MID', $coldkey, '2026-10-01T00:00:00Z')],
                    '3' => [$transfer('t-old', $funder, $coldkey, '2026-08-01T00:00:00Z')]][(string) $page];

                return Http::response(['data' => $data, 'pagination' => ['current_page' => $page, 'total_items' => 401, 'total_pages' => 3]]);
            }

            return Http::response(['data' => [], 'pagination' => ['total_items' => 0, 'total_pages' => 0]]);
        });

        app(\App\Services\Soma\OwnerProfiler::class)->profile($coldkey);
        $this->assertEqualsCanonicalizing(['t-new', 't-mid', 't-old'], \App\Models\Transfer::pluck('external_id')->all(), 'the funding on the last page is read');
    }

    public function test_an_unknown_hotkey_changes_nothing(): void
    {
        Queue::fake();
        $c = Competition::ensure(150);
        Miner::create(['competition_id' => $c->id, 'hotkey' => '5UNKNOWN']);
        Http::fake(['*' => Http::response(['data' => []])]);

        app()->call([new ResolveOwner('5UNKNOWN'), 'handle']);
        $this->assertNull(Miner::where('hotkey', '5UNKNOWN')->value('coldkey'));
        Queue::assertNotPushed(ProfileOwner::class);
    }
}
