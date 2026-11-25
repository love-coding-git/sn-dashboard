<?php

namespace Tests\Feature\Soma;

use App\Models\Competition;
use App\Models\Miner;
use App\Models\Neuron;
use App\Models\WatchEvent;
use App\Services\Soma\Ingest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IngestTest extends TestCase
{
    use RefreshDatabase;

    private function row(string $hotkey, string $status, string $submit, ?float $stage1 = null): array
    {
        return ['hotkey' => $hotkey, 'status' => $status, 'lastSubmit' => $submit, 'stage1' => ['score' => $stage1, 'verdict' => null]];
    }

    public function test_events_follow_the_python_watcher_rules(): void
    {
        $ingest = new Ingest;
        $c = Competition::ensure(148);
        $ingest->leaderboard($c, [$this->row('5AAA', 'screening', '2026-10-12T10:00:00.123456Z')], false);
        $this->assertSame(0, WatchEvent::count());

        // unchanged row (microseconds included) - no event
        $this->assertCount(0, $ingest->leaderboard($c, [$this->row('5AAA', 'screening', '2026-10-12T10:00:00.123456Z')], true));

        // leaving screening = stage1_done; a new hotkey = upload (its is_ours flag must be a boolean)
        $events = $ingest->leaderboard($c, [
            $this->row('5AAA', 'qualified', '2026-10-12T10:00:00.123456Z', 0.3),
            $this->row('5BBB', 'screening', '2026-10-12T11:00:00.5Z'),
        ], true);
        $this->assertEqualsCanonicalizing(['stage1_done', 'upload'], $events->pluck('type')->all());
        $this->assertFalse(WatchEvent::where('hotkey', '5BBB')->first()->is_ours);

        // "in queue" is still running: a status event, and the final verdict after it is stage1_done
        $ingest->leaderboard($c, [$this->row('5AAA', 'qualified', '2026-10-12T10:00:00.123456Z', 0.3), $this->row('5BBB', 'in queue', '2026-10-12T11:00:00.5Z')], true);
        $this->assertSame('status', WatchEvent::where('hotkey', '5BBB')->latest('id')->first()->type);
        $ingest->leaderboard($c, [$this->row('5AAA', 'qualified', '2026-10-12T10:00:00.123456Z', 0.3), $this->row('5BBB', 'qualified', '2026-10-12T11:00:00.5Z')], true);
        $this->assertSame('stage1_done', WatchEvent::where('hotkey', '5BBB')->latest('id')->first()->type);

        // a re-upload changes lastSubmit
        $this->assertSame(['upload'], $ingest->leaderboard($c, [$this->row('5AAA', 'qualified', '2026-10-12T12:00:00Z', 0.3)], true)->pluck('type')->all());
    }

    public function test_registrations_and_our_labels(): void
    {
        $ingest = new Ingest;
        $c = Competition::ensure(148);
        $ingest->metagraph(114, [['uid' => 1, 'hotkey' => '5HbfSFdnAAAA', 'coldkey' => '5OURS'], ['uid' => 2, 'hotkey' => '5XXX', 'coldkey' => '5OTHER']], true);
        $this->assertSame(0, WatchEvent::count(), 'the first snapshot is a baseline');

        $events = $ingest->metagraph(114, [
            ['uid' => 1, 'hotkey' => '5HbfSFdnAAAA', 'coldkey' => '5OURS'],
            ['uid' => 3, 'hotkey' => '5NEWOURS', 'coldkey' => '5OURS'],
        ], true);
        $this->assertSame(['registration'], $events->pluck('type')->all());
        $this->assertTrue($events->first()->is_ours);
        $this->assertFalse(Neuron::where('hotkey', '5XXX')->first()->registered);

        $ingest->leaderboard($c, [$this->row('5HbfSFdnAAAA', 'qualified', '2026-10-08T20:07:00Z'), $this->row('5NEWOURS', 'screening', '2026-10-12T15:29:00Z')], false);
        $ingest->linkMiners();
        $this->assertSame(['S1', 'S2'], Miner::where('is_ours', true)->orderBy('our_label')->pluck('our_label')->all());
    }
}
