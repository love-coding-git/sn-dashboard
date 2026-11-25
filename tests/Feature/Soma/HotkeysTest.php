<?php

namespace Tests\Feature\Soma;

use App\Models\Competition;
use App\Models\HotkeyState;
use App\Services\Soma\Hotkeys;
use App\Services\Soma\Ingest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HotkeysTest extends TestCase
{
    use RefreshDatabase;

    /** Block 10000, immunity 5000: uid 4 registered 10 blocks ago is immune; the rest queue by emission, then age. */
    private function data(): array
    {
        return [
            'netuid' => 114,
            'block' => 10000,
            'immunity_period' => 5000,
            'neurons' => [
                ['uid' => 1, 'hotkey' => '5OURS1', 'coldkey' => '5C1', 'emission' => 0, 'registered_at_block' => 100, 'validator' => false],
                ['uid' => 2, 'hotkey' => '5OLDER', 'coldkey' => '5C2', 'emission' => 0, 'registered_at_block' => 50, 'validator' => false],
                ['uid' => 3, 'hotkey' => '5PAID', 'coldkey' => '5C3', 'emission' => 5, 'registered_at_block' => 10, 'validator' => false],
                ['uid' => 4, 'hotkey' => '5NEW', 'coldkey' => '5C4', 'emission' => 0, 'registered_at_block' => 9990, 'validator' => false],
            ],
            'ours' => ['5OURS1' => 'w/a', '5GONE' => 'w/b'],
            'notes' => ['w/b' => 'round-11 review'],
        ];
    }

    public function test_queue_runs_by_emission_then_registration_and_skips_immune_uids(): void
    {
        $this->assertSame([2 => 1, 1 => 2, 3 => 3], Hotkeys::ranks($this->data()['neurons'], 10000, 5000));

        $snapshot = (new Hotkeys)->record($this->data());
        $this->assertSame(1, $snapshot->immune);
        $this->assertSame(3, $snapshot->zero_emission);
        $a = HotkeyState::where('name', 'w/a')->first();
        $this->assertTrue($a->registered);
        $this->assertSame(2, $a->rank);
        $b = HotkeyState::where('name', 'w/b')->first();
        $this->assertFalse($b->registered);
        $this->assertNull($b->rank);
        $this->assertSame('round-11 review', $b->note);
    }

    public function test_page_shows_our_hotkeys_the_queue_and_the_change_over_a_day(): void
    {
        $hotkeys = new Hotkeys;
        $day = $this->data();
        $day['neurons'][1]['emission'] = 7;   // a day ago the older uid had emission, so ours stood first
        $hotkeys->record($day, now()->subDay());
        $hotkeys->record($this->data());
        $ingest = new Ingest;
        $ingest->leaderboard(Competition::ensure(150), [['hotkey' => '5OURS1', 'status' => 'scored', 'lastSubmit' => '2026-11-11T10:00:00Z', 'stage1' => ['score' => 0.2]]], false);

        $this->get(route('soma.hotkeys'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('soma/hotkeys')
            ->where('snapshot.block', 10000)
            ->where('snapshot.registrations.h24', 1)
            ->has('ours', 2)
            ->where('ours.0.name', 'w/a')
            ->where('ours.0.rank', 2)
            ->where('ours.0.rank_day_ago', 1)
            ->where('ours.0.eta_days_24h', 2)
            ->where('ours.0.entries.0.competition', 150)
            ->where('ours.1.registered', false)
            ->has('queue', 4)
            ->where('queue.0.uid', 2)
            ->where('queue.1.ours', 'w/a')
            ->where('queue.3.rank', null)
            ->has('history', 2)
            ->where('names', ['w/a']));
    }

    public function test_page_renders_before_the_first_snapshot(): void
    {
        $this->get(route('soma.hotkeys'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('soma/hotkeys')
            ->where('snapshot', null)
            ->has('ours', 0));
    }
}
