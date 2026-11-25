<?php

namespace App\Jobs\Sn34;

use App\Services\Sn34\DiscordReader;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Read the Yugos Discord channel (#34 mind) into sn34_discord, every minute: the messages newer than the newest stored
 * one (the first run fetches everything since config sn34.discord.since), then the newest 50 again so an edit updates
 * the stored text. The server does this itself with its own token (no other machine is involved).
 */
class SyncDiscord implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function handle(DiscordReader $reader): void
    {
        if ((string) config('sn34.discord.token') === '') {
            return;
        }
        $channel = (string) config('sn34.discord.channel');
        $newest = DB::table('sn34_discord')->where('channel', $channel)->orderByDesc('posted_at')->orderByDesc('message_id')->value('message_id');
        $after = $newest ?: DiscordReader::snowflakeAt((int) Carbon::parse((string) config('sn34.discord.since'))->utc()->timestamp);

        $fresh = $reader->after($channel, (string) $after, (int) config('sn34.discord.max_pages', 30));
        if (isset($fresh['_error'])) {
            Log::warning('sn34: discord read failed - '.$fresh['_error']);

            return;
        }
        $added = $fresh ? DB::table('sn34_discord')->insertOrIgnore(array_map(fn ($m) => self::row($m, $channel), $fresh)) : 0;

        // edits of the newest messages (a later edit replaces the text)
        $latest = $reader->get("/channels/$channel/messages", ['limit' => 50]);
        $edited = 0;
        if (! isset($latest['_error'])) {
            foreach ($latest as $m) {
                if (! empty($m['edited_timestamp'])) {
                    $edited += DB::table('sn34_discord')->where('message_id', (string) $m['id'])
                        ->where(fn ($q) => $q->whereNull('edited_at')->orWhere('edited_at', '<', Carbon::parse($m['edited_timestamp'])->utc()))
                        ->update(['text' => self::text($m), 'edited_at' => Carbon::parse($m['edited_timestamp'])->utc()]);
                }
            }
        }
        if ($added || $edited) {
            Log::info("sn34: discord +$added messages, $edited edited ({$reader->calls} calls)");
        }
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
