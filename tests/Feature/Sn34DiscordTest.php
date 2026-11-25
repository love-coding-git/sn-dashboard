<?php

namespace Tests\Feature;

use App\Jobs\Sn34\SyncDiscord;
use App\Models\Sn34\Event;
use App\Services\Sn34\DiscordReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** The server reads the Yugos Discord channel itself, and writes its watch log from its own events. */
class Sn34DiscordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sn34.discord.token' => 'test-token', 'sn34.discord.channel' => '111', 'sn34.discord.min_gap_seconds' => 0, 'sn34.discord.max_gap_seconds' => 0]);
    }

    private static function msg(string $id, string $text, ?string $edited = null): array
    {
        return ['id' => $id, 'timestamp' => '2026-11-25T10:00:00+00:00', 'content' => $text,
            'author' => ['username' => 'kenobi', 'global_name' => 'Kenobi'], 'edited_timestamp' => $edited, 'attachments' => [], 'embeds' => []];
    }

    public function test_the_first_read_pages_from_the_start_and_edits_update_the_text(): void
    {
        Http::fakeSequence('discord.com/*')
            ->push([self::msg('1564516342319677451', 'second, edited', '2026-11-25T10:05:00+00:00'), self::msg('1564516173981155379', 'first')]) // newest 100
            ->push([self::msg('1564516173981155379', 'first'), self::msg('1564516342319677451', 'second')]);                                      // paging from "since"
        (new SyncDiscord)->handle(app(DiscordReader::class));

        $this->assertSame(2, DB::table('sn34_discord')->count());
        $this->assertSame('second, edited', DB::table('sn34_discord')->where('message_id', '1564516342319677451')->value('text'));
        $this->assertSame('Kenobi', DB::table('sn34_discord')->where('message_id', '1564516173981155379')->value('author'));
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'test-token') && str_contains($r->url(), '/channels/111/messages'));
        Http::assertSentCount(2);
    }

    public function test_a_later_read_is_one_call_when_the_newest_100_reach_the_stored_newest(): void
    {
        DB::table('sn34_discord')->insert(SyncDiscord::row(self::msg('1564516173981155379', 'first'), '111'));
        Http::fakeSequence('discord.com/*')
            ->push([self::msg('1564516342319677451', 'second'), self::msg('1564516173981155379', 'first')]);
        (new SyncDiscord)->handle(app(DiscordReader::class));

        $this->assertSame(2, DB::table('sn34_discord')->count());
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'after='));
    }

    public function test_reads_come_at_random_10_to_30_minute_intervals_queued_to_the_second(): void
    {
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-11-25 18:00:00'));

        SyncDiscord::tick();                                  // nothing planned yet: the first read is due now
        SyncDiscord::tick();                                  // already queued: no second copy
        Queue::assertPushedOn('sn34', SyncDiscord::class);
        Queue::assertPushed(SyncDiscord::class, 1);

        $next = SyncDiscord::planNext();                      // what a read does when it ends
        $gap = $next - now()->getTimestamp();
        $this->assertGreaterThanOrEqual(600, $gap);
        $this->assertLessThanOrEqual(1800, $gap);

        SyncDiscord::tick();                                  // more than a minute before it is due: nothing
        Queue::assertPushed(SyncDiscord::class, 1);

        $this->travelTo(Carbon::createFromTimestamp($next - 37));
        SyncDiscord::tick();                                  // within the minute: queued with the exact delay
        Queue::assertPushed(SyncDiscord::class, fn ($job) => $job->delay === 37);
        Queue::assertPushed(SyncDiscord::class, 2);
    }

    public function test_a_second_copy_of_a_read_that_just_ran_does_nothing(): void
    {
        Cache::put(SyncDiscord::READ_KEY, now()->getTimestamp() - 20);
        Http::fake();
        (new SyncDiscord)->handle(app(DiscordReader::class));
        Http::assertNothingSent();
    }

    public function test_without_a_token_nothing_is_called(): void
    {
        config(['sn34.discord.token' => '']);
        Http::fake();
        (new SyncDiscord)->handle(app(DiscordReader::class));
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('sn34_discord')->count());
    }

    public function test_the_watch_log_is_written_from_the_events(): void
    {
        Event::create(['occurred_at' => '2026-11-25 12:01:30', 'type' => 'verdict', 'run_id' => 'billing-eda26fcc-0000', 'hotkey' => '5GnYYSwLxxxx', 'coldkey' => '5GnYYSwLcold',
            'data' => ['eligible' => true, 'promoted' => false, 'net_value' => 0.246, 'lower_bound' => -0.257, 'reasons' => [], 'near_miss' => false]]);
        Event::create(['occurred_at' => '2026-11-25 12:01:40', 'type' => 'queue_error', 'run_id' => 'billing-78b63615-0000', 'hotkey' => '5CSHfySNxxxx', 'coldkey' => null,
            'data' => ['reason' => 'Evaluation could not be completed.']]);
        $this->get(route('sn34.overview'))->assertOk()->assertInertia(fn ($page) => $page
            ->where('notes.watch_rows.0.0', '2026-11-25 12:01')
            ->where('notes.watch_rows.0.1', 'board')
            ->where('notes.watch_rows.0.2', fn ($t) => str_contains($t, 'decision eda26fcc (5GnYYSwL): eligible, NV +0.246') && str_contains($t, 'queue error'))
            ->where('notes.watch_rows.0.3', fn ($t) => str_contains($t, 'eligible eda26fcc') && str_contains($t, 'queue error 78b63615 (may still finish)')));
    }
}
