<?php

namespace App\Services\Soma;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Reads the public SOMA dashboard. The pages are Next.js server components: the data sits in
 * `self.__next_f.push([1,"..."])` chunks, which are joined and searched for a JSON marker.
 */
class DashboardClient
{
    /**
     * Leaderboard rows of one competition (`?comp=` selects it; an unknown parameter serves the current one).
     *
     * @return array<int, array<string, mixed>>
     */
    public function leaderboard(int $competition): array
    {
        $html = $this->fetch(config('soma.dashboard_url').'?comp='.$competition);
        $rows = self::decodeAt(self::rscPayload($html), '"rows":[', strlen('"rows":'));

        return is_array($rows) ? $rows : [];
    }

    /**
     * One miner's tasks with their runs.
     *
     * @return array<int, array<string, mixed>>
     */
    public function minerTasks(int $competition, string $hotkey): array
    {
        $html = $this->fetch(config('soma.dashboard_url')."/miner/$competition/$hotkey");
        if (! str_contains($html, $hotkey)) {
            throw new RuntimeException('page for '.substr($hotkey, 0, 8).' not served (CloudFront mix-up)');
        }
        $data = self::decodeAt(self::rscPayload($html), '{"tasks":[', 0);

        return is_array($data['tasks'] ?? null) ? $data['tasks'] : [];
    }

    protected function fetch(string $url): string
    {
        try {
            return Http::withHeaders(['User-Agent' => 'Mozilla/5.0'])
                ->timeout(60)
                ->retry(3, 3000)
                ->get($url)
                ->throw()
                ->body();
        } catch (ConnectionException) {
            // Rethrown without the cURL text: "reset by peer" and the like read as a lost database connection to the
            // queue worker, which then stops for good (2026-10-22 15:10 UTC). Only this job fails; the next poll retries.
            throw new RuntimeException('dashboard unreachable after 3 tries: '.parse_url($url, PHP_URL_PATH));
        }
    }

    /**
     * Join the server-component chunks of a page into one string.
     */
    public static function rscPayload(string $html): string
    {
        $needle = 'self.__next_f.push([1,"';
        $out = '';
        $pos = 0;
        $len = strlen($html);
        while (($start = strpos($html, $needle, $pos)) !== false) {
            $i = $start + strlen($needle);
            $j = $i;
            while ($j < $len && $html[$j] !== '"') {
                $j += $html[$j] === '\\' ? 2 : 1;
            }
            $decoded = json_decode('"'.substr($html, $i, $j - $i).'"');
            if (is_string($decoded)) {
                $out .= $decoded;
            }
            $pos = $j + 1;
        }

        return $out;
    }

    /**
     * Decode the JSON value that starts `$offset` characters after the first `$marker`.
     */
    public static function decodeAt(string $payload, string $marker, int $offset): mixed
    {
        $start = strpos($payload, $marker);
        if ($start === false) {
            return null;
        }
        $json = self::extractValue($payload, $start + $offset);

        return $json === null ? null : json_decode($json, true);
    }

    /**
     * The object or array that starts at `$pos`, found by matching brackets outside strings.
     */
    public static function extractValue(string $s, int $pos): ?string
    {
        $open = $s[$pos] ?? '';
        if ($open !== '{' && $open !== '[') {
            return null;
        }
        $depth = 0;
        $inString = false;
        $escape = false;
        for ($i = $pos, $len = strlen($s); $i < $len; $i++) {
            $c = $s[$i];
            if ($inString) {
                if ($escape) {
                    $escape = false;
                } elseif ($c === '\\') {
                    $escape = true;
                } elseif ($c === '"') {
                    $inString = false;
                }

                continue;
            }
            if ($c === '"') {
                $inString = true;
            } elseif ($c === '{' || $c === '[') {
                $depth++;
            } elseif ($c === '}' || $c === ']') {
                $depth--;
                if ($depth === 0) {
                    return substr($s, $pos, $i - $pos + 1);
                }
            }
        }

        return null;
    }
}
