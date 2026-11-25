<?php

namespace Tests\Feature;

use App\Jobs\Sn34\SyncDiscord;
use App\Models\Sn34\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The server reads the Yugos Discord channel itself, and writes its watch log from its own events. */
class Sn34DiscordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sn34.discord.token' => 'test-token', 'sn34.discord.channel' => '111', 'sn34.discord.min_gap_seconds' => 0]);
    }

    public function test_new_messages_are_stored_once_and_edits_update_the_text(): void
    {
        $msg = fn (string $id, string $text, ?string $edited = null) => ['id' => $id, 'timestamp' => '2026-11-25T10:00:00+00:00', 'content' => $text,
            'author' => ['username' => 'kenobi', 'global_name' => 'Kenobi'], 'edited_timestamp' => $edited, 'attachments' => [], 'embeds' => []];
        Http::fakeSequence('discord.com/*')
            ->push([$msg('1564516173981155379', 'first'), $msg('1564516342319677451', 'second')])   // after: the new messages
            ->push([$msg('1564516342319677451', 'second, edited', '2026-11-25T10:05:00+00:00')]);    // newest 50: one edit
        (new SyncDiscord)->handle(app(\App\Services\Sn34\DiscordReader::class));

        $this->assertSame(2, DB::table('sn34_discord')->count());
        $this->assertSame('second, edited', DB::table('sn34_discord')->where('message_id', '1564516342319677451')->value('text'));
        $this->assertSame('Kenobi', DB::table('sn34_discord')->where('message_id', '1564516173981155379')->value('author'));
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'test-token') && str_contains($r->url(), '/channels/111/messages'));
    }

    public function test_without_a_token_nothing_is_called(): void
    {
        config(['sn34.discord.token' => '']);
        Http::fake();
        (new SyncDiscord)->handle(app(\App\Services\Sn34\DiscordReader::class));
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
