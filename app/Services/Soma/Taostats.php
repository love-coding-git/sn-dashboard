<?php

namespace App\Services\Soma;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Small Taostats client. The API key is read from the workspace's .env (config soma.keys_env) at run time;
 * it is never logged, stored in the database or sent anywhere but api.taostats.io.
 */
class Taostats
{
    private ?string $key = null;

    /**
     * @return array<string, mixed> the decoded response, or ['_error' => code]
     */
    public function get(string $path, array $params = []): array
    {
        $key = $this->key();
        if ($key === '') {
            return ['_error' => 'no TAO_API_KEY'];
        }
        $error = 'rate limited';
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                $response = Http::withHeaders(['Authorization' => $key, 'accept' => 'application/json', 'User-Agent' => 'soma-dashboard'])
                    ->timeout(40)
                    ->get(rtrim(config('soma.taostats_url'), '/').$path, $params);
            } catch (ConnectionException) {
                // A network failure is never rethrown: its cURL text ("reset by peer", ...) reads as a lost database
                // connection to the queue worker, which then stops for good (2026-10-22 15:10 UTC).
                $error = 'network';
                sleep(5 * $attempt);

                continue;
            }
            if ($response->status() === 429) {
                $error = 'rate limited';
                sleep(15 * $attempt);

                continue;
            }
            if ($response->failed()) {
                // logged: the legacy /api/ paths answered 410 from 2026-11-16 22:xx UTC and the log only said "came back empty"
                Log::warning('soma: taostats '.$path.' answered '.$response->status());

                return ['_error' => $response->status()];
            }

            return $response->json() ?? [];
        }

        return ['_error' => $error];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function metagraph(int $netuid): array
    {
        $res = $this->get('/v1/subnets/metagraph', ['netuid' => $netuid, 'limit' => 256]);

        return is_array($res['data'] ?? null) ? $res['data'] : [];
    }

    public static function ss58(mixed $x): string
    {
        return is_array($x) ? (string) ($x['ss58'] ?? '') : (string) ($x ?? '');
    }

    private function key(): string
    {
        if ($this->key === null) {
            $this->key = '';
            $file = config('soma.keys_env');
            if (is_string($file) && is_readable($file)) {
                foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                    if (str_starts_with($line, 'TAO_API_KEY=')) {
                        $this->key = trim(substr($line, strlen('TAO_API_KEY=')), " \t\"'");
                        break;
                    }
                }
            }
        }

        return $this->key;
    }
}
