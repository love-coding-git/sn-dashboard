<?php

namespace App\Services\Sn34;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reads the public Yugos API (https://upload.yugos.ai, no key). Every answer is untrusted data. A failed read returns
 * ['_error' => code] and never throws the cURL text (it reads as a lost database connection to the queue worker).
 */
class YugosClient
{
    /** Current scope: policy_id, phase, paused, membership and the open runs. */
    public function current(): array
    {
        return $this->get('/competition/submissions', ['netuid' => config('sn34.netuid')]);
    }

    /** The newest 500 runs of every pack (truncated: older runs fall off; keep what was read). */
    public function history(): array
    {
        return $this->get('/competition/submissions', ['netuid' => config('sn34.netuid'), 'scope' => 'history', 'limit' => 500]);
    }

    public function board(string $policyId): array
    {
        return $this->get('/competition/board', ['netuid' => config('sn34.netuid'), 'policy_id' => $policyId]);
    }

    /**
     * Progress and summaries of up to 20 runs.
     *
     * @param  array<int, string>  $runIds
     */
    public function results(array $runIds): array
    {
        return $this->get('/competition/results', ['netuid' => config('sn34.netuid'), 'run_ids' => implode(',', $runIds)]);
    }

    public function card(): array
    {
        return $this->get('/world/cards/active');
    }

    private function get(string $path, array $params = []): array
    {
        try {
            $response = Http::withHeaders(['accept' => 'application/json', 'User-Agent' => 'sn-dashboard'])
                // worst case about 2 x 20 s + 2 s, so a job's $timeout never kills the worker (review 2026-11-25)
                ->timeout(20)
                ->retry(2, 2000, throw: false)
                ->get(rtrim(config('sn34.api_url'), '/').$path, $params);
        } catch (ConnectionException) {
            return ['_error' => 'network'];
        }
        if ($response->failed()) {
            Log::warning('sn34: '.$path.' answered '.$response->status());

            return ['_error' => $response->status()];
        }

        $data = $response->json();

        return is_array($data) ? $data : ['_error' => 'not json'];
    }
}
