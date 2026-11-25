<?php

namespace App\Jobs\Sn34;

use App\Services\Sn34\DiscordReader;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Read the Yugos Discord channel (#34 mind) into sn34_discord at random intervals of 10 - 30 minutes, to the second
 * (user 2026-11-25 18:4x UTC: "1분은 너무 작다. 간격을 10 ~ 30분 사이에 랜돔으로 초도 랜돔으로"): a fixed one-minute
 * cadence around the clock is the plainest sign of an automated user account. The scheduler calls tick() every minute;
 * tick() queues one read for the exact second it is due, and every read (also a failed one) picks the next moment.
 *
 * One read is one call when nothing much happened: the newest 100 messages hold every new message as long as they reach
 * back to the newest stored one, and they also carry recent edits. Only the first read, or more than 100 new messages,
 * pages forward from the stored newest (from config sn34.discord.since the first time). The server does this itself with
 * its own token (no other machine is involved).
 */
class SyncDiscord implements ShouldQueue
{
    use Queueable;

    public const NEXT_KEY = 'sn34:discord:next_at';     // unix second the next read is due

    public const READ_KEY = 'sn34:discord:read_at';     // unix second of the last read

    public const QUEUED_KEY = 'sn34:discord:queued_for'; // a read is in the queue (expires if the job is lost)

    public int $timeout = 600;

    public int $tries = 1;

    /** Every minute from the scheduler: queue the next read for the second it is due, once. */
    public static function tick(): void
    {
        if ((string) config('sn34.discord.token') === '' || Cache::has(self::QUEUED_KEY)) {
            return;
        }
        $now = now()->getTimestamp();
        $due = (int) Cache::get(self::NEXT_KEY, $now);
        if ($due - $now >= 60) {
            return;
        }
        $wait = max(0, $due - $now);
        Cache::put(self::QUEUED_KEY, $due, $wait + 1800);
        self::dispatch()->onQueue('sn34')->delay($wait);
    }

    /** After a read: the next one comes a random number of seconds later (config sn34.discord.poll_min/max_seconds). */
    public static function planNext(): int
    {
        $min = (int) config('sn34.discord.poll_min_seconds', 600);
        $max = max($min, (int) config('sn34.discord.poll_max_seconds', 1800));
        $now = now()->getTimestamp();
        $next = $now + random_int($min, $max);
        Cache::put(self::READ_KEY, $now, now()->addDays(7));
        Cache::put(self::NEXT_KEY, $next, now()->addDays(7));
        Cache::forget(self::QUEUED_KEY);

        return $next;
    }

    public function handle(DiscordReader $reader): void
    {
        if ((string) config('sn34.discord.token') === '') {
            return;
        }
        // a second copy of a read that ran minutes ago (queued again after its marker expired): skip it
        $readAt = Cache::get(self::READ_KEY);
        if ($readAt !== null && now()->getTimestamp() - (int) $readAt < 300) {
            return;
        }
        try {
            $this->read($reader);
        } finally {
            self::planNext();
        }
    }

    private function read(DiscordReader $reader): void
    {
        $channel = (string) config('sn34.discord.channel');
        $newest = DB::table('sn34_discord')->where('channel', $channel)->orderByDesc('posted_at')->orderByDesc('message_id')->value('message_id');

        $latest = $reader->get("/channels/$channel/messages", ['limit' => 100]);
        if (isset($latest['_error'])) {
            Log::warning('sn34: discord read failed - '.$latest['_error']);

            return;
        }
        $reaches = $newest !== null && collect($latest)->contains(fn ($m) => self::cmp((string) $m['id'], (string) $newest) <= 0);
        if ($reaches) {
            $fresh = array_values(array_filter($latest, fn ($m) => self::cmp((string) $m['id'], (string) $newest) > 0));
        } else {
            $after = $newest ?: DiscordReader::snowflakeAt((int) Carbon::parse((string) config('sn34.discord.since'))->utc()->timestamp);
            $fresh = $reader->after($channel, (string) $after, (int) config('sn34.discord.max_pages', 30));
            if (isset($fresh['_error'])) {
                Log::warning('sn34: discord read failed - '.$fresh['_error']);

                return;
            }
        }
        $added = $fresh ? DB::table('sn34_discord')->insertOrIgnore(array_map(fn ($m) => self::row($m, $channel), $fresh)) : 0;

        // edits among the newest messages (a later edit replaces the text)
        $edited = 0;
        foreach ($latest as $m) {
            if (! empty($m['edited_timestamp'])) {
                $edited += DB::table('sn34_discord')->where('message_id', (string) $m['id'])
                    ->where(fn ($q) => $q->whereNull('edited_at')->orWhere('edited_at', '<', Carbon::parse($m['edited_timestamp'])->utc()))
                    ->update(['text' => self::text($m), 'edited_at' => Carbon::parse($m['edited_timestamp'])->utc()]);
            }
        }
        if ($added || $edited) {
            Log::info("sn34: discord +$added messages, $edited edited ({$reader->calls} calls)");
        }
    }

    /** Snowflake ids compared as numbers (they are decimal strings of growing length). */
    private static function cmp(string $a, string $b): int
    {
        return strlen($a) <=> strlen($b) ?: strcmp($a, $b);
    }

    /** One Discord message as a sn34_discord row. */
    public static function row(array $m, string $channel): array
    {
        $ref = $m['referenced_message'] ?? null;
        $links = array_values(array_filter(array_merge(
            array_map(fn ($a) => (string) ($a['url'] ?? ''), $m['attachments'] ?? []),
            array_map(fn ($e) => (string) ($e['url'] ?? ''), $m['embeds'] ?? []),
        )));

        return [
            'message_id' => (string) $m['id'],
            'channel' => $channel,
            'author' => self::name($m['author'] ?? []),
            'posted_at' => Carbon::parse((string) $m['timestamp'])->utc(),
            'text' => self::text($m),
            'reply_author' => is_array($ref) ? self::name($ref['author'] ?? []) : null,
            'reply_text' => is_array($ref) ? mb_substr((string) ($ref['content'] ?? ''), 0, 2000) : null,
            'links' => json_encode(array_slice($links, 0, 20)),
            'url' => 'https://discord.com/channels/'.config('sn34.discord.guild').'/'.$channel.'/'.$m['id'],
            'edited_at' => ! empty($m['edited_timestamp']) ? Carbon::parse($m['edited_timestamp'])->utc() : null,
            'received_at' => now(),
        ];
    }

    private static function name(array $author): ?string
    {
        $name = $author['global_name'] ?? $author['username'] ?? null;

        return $name !== null ? mb_substr((string) $name, 0, 120) : null;
    }

    private static function text(array $m): string
    {
        return mb_substr((string) ($m['content'] ?? ''), 0, 8000);
    }
}
