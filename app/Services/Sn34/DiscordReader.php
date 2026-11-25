<?php

namespace App\Services\Sn34;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Reads one Discord text channel through the REST API with the owner's user token (config sn34.discord.token) - the
 * reader of ai_tr/tools/discord_watch.py in PHP: a random 1.5 - 4 s between calls, a 429 waits for Discord's retry_after,
 * a 5xx or a network failure backs off. A failure comes back as ['_error' => ...], never as an exception carrying the
 * cURL text (that reads as a lost database connection to the queue worker). The token is never logged.
 */
class DiscordReader
{
    private float $last = 0.0;

    public int $calls = 0;

    public const EPOCH_MS = 1420070400000;

    /** The snowflake id of the first moment of a day: messages "after" it are the ones from that day on. */
    public static function snowflakeAt(int $unixSeconds): string
    {
        return (string) (($unixSeconds * 1000 - self::EPOCH_MS) << 22);
    }

    /**
     * @return array<int, array<string, mixed>>|array{_error: string}
     */
    public function get(string $path, array $params = []): array
    {
        $token = (string) config('sn34.discord.token');
        if ($token === '') {
            return ['_error' => 'no DISCORD_USER_TOKEN'];
        }
        $url = rtrim((string) config('sn34.discord.api'), '/').$path;
        $error = 'no response after retries';
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $min = (float) config('sn34.discord.min_gap_seconds', 1.5);
            $gap = $min + mt_rand() / mt_getrandmax() * max(0.0, (float) config('sn34.discord.max_gap_seconds', 4.0) - $min);
            $pause = $gap - (microtime(true) - $this->last);
            if ($pause > 0) {
                usleep((int) ($pause * 1e6));
            }
            $this->last = microtime(true);
            $this->calls++;
            try {
                $res = Http::withHeaders([
                    'Authorization' => $token,
                    'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36',
                    'Accept' => 'application/json',
                ])->timeout(30)->get($url, array_filter($params, fn ($v) => $v !== null));
            } catch (ConnectionException) {
                $error = 'network';
                sleep(5 * ($attempt + 1));

                continue;
            }
            if ($res->status() === 429) {
                sleep((int) ceil(min(60.0, (float) ($res->json('retry_after') ?? 5)) + 0.5));
                $error = 'rate limited';

                continue;
            }
            if ($res->serverError()) {
                $error = 'HTTP '.$res->status();
                sleep(5 * ($attempt + 1));

                continue;
            }
            if ($res->failed()) {
                return ['_error' => 'HTTP '.$res->status()];
            }

            return is_array($res->json()) ? $res->json() : ['_error' => 'not json'];
        }

        return ['_error' => $error];
    }

    /**
     * Every message with an id above $afterId, oldest first, up to $maxPages pages of 100.
     *
     * @return array<int, array<string, mixed>>|array{_error: string}
     */
    public function after(string $channel, string $afterId, int $maxPages): array
    {
        $out = [];
        $cursor = $afterId;
        for ($page = 0; $page < $maxPages; $page++) {
            $batch = $this->get("/channels/$channel/messages", ['limit' => 100, 'after' => $cursor]);
            if (isset($batch['_error'])) {
                return $out ? $out : $batch;
            }
            if (! $batch) {
                break;
            }
            usort($batch, fn ($a, $b) => strcmp(str_pad((string) $a['id'], 25, '0', STR_PAD_LEFT), str_pad((string) $b['id'], 25, '0', STR_PAD_LEFT)));
            $out = array_merge($out, $batch);
            $cursor = (string) end($batch)['id'];
            if (count($batch) < 100) {
                break;
            }
        }

        return $out;
    }
}
