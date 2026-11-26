<?php

namespace App\Http\Controllers;

use App\Models\Competition;
use App\Services\Sn34\Presenter as Sn34Presenter;
use App\Services\Soma\Hotkeys;
use App\Services\Soma\Presenter as SomaPresenter;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The cross-subnet overview at "/" (user 2026-11-25: "overview를 click하면 soma와 SN34의 현재 정보들을 보여주는 페지"):
 * where the current SOMA round and the Yugos competition stand, side by side. Both parts are cached for a minute - the
 * SOMA presenter builds the whole leaderboard (about 3 s), and this page needs only a slice of it.
 */
class HubController extends Controller
{
    public function __invoke(SomaPresenter $soma, Sn34Presenter $sn34, Hotkeys $hotkeys): Response
    {
        return Inertia::render('hub', [
            // cached as plain arrays: this app's cache does not unserialize objects (a Collection came back as an incomplete class)
            'soma' => Cache::remember('hub:soma:v4', 60, fn () => self::plain($this->soma($soma) + ['hotkeys' => self::hotkeys($hotkeys)])),
            'sn34' => Cache::remember('hub:sn34:v3', 60, fn () => self::plain($sn34->summary())),
        ]);
    }

    /** Our registered SN114 hotkeys in short: how many, and the one nearest to deregistration. */
    private static function hotkeys(Hotkeys $hotkeys): array
    {
        $page = $hotkeys->page();
        $ours = collect($page['ours'] ?? [])->where('registered', true);
        $front = $ours->whereNotNull('rank')->sortBy('rank')->first();

        return [
            'registered' => $ours->count(),
            'total' => collect($page['ours'] ?? [])->count(),
            'taken_at' => $page['snapshot']['taken_at'] ?? null,
            'front' => $front ? ['name' => $front['name'], 'rank' => $front['rank'], 'eta_days_7d' => $front['eta_days_7d'], 'eta_days_24h' => $front['eta_days_24h']] : null,
        ];
    }

    private static function plain(array $data): array
    {
        return json_decode(json_encode($data), true);
    }

    /** The current SOMA round reduced to what the overview draws: entries with their stage-2 cells, timing, freshness. */
    private function soma(SomaPresenter $presenter): array
    {
        $competition = Competition::find((int) config('soma.competition')) ?? Competition::ensure((int) config('soma.competition'));
        $data = $presenter->overview($competition);
        $miners = collect($data['miners'] ?? [])->map(function ($m) {
            $m = (array) $m;

            return [
                'hotkey' => $m['hotkey'] ?? null, 'is_ours' => (bool) ($m['is_ours'] ?? false), 'our_label' => $m['our_label'] ?? null,
                'status' => $m['status'] ?? null, 'stage1_score' => $m['stage1_score'] ?? null, 'stage2_score' => $m['stage2_score'] ?? null,
                // the full evaluation: the platform's overall once scored, the runs scored so far, our running estimate
                'overall' => $m['overall'] ?? null, 'full_progress' => $m['full_progress'] ?? null,
                'live' => isset($m['live']) ? ['overall' => $m['live']['overall'] ?? null, 'tasks' => $m['live']['tasks'] ?? 0] : null,
                'family' => $m['family'] ?? null, 'family_name' => $m['family_name'] ?? null, 'coldkey' => $m['coldkey'] ?? null,
                'stage2' => collect($m['stage2'] ?? [])->map(fn ($c) => [
                    'finished' => $c['finished'] ?? 0, 'total' => $c['total'] ?? null, 'score' => $c['score'] ?? null,
                    'passed' => $c['passed'] ?? null, 'baseline_passed' => $c['baseline_passed'] ?? null, 'baseline_total' => $c['baseline_total'] ?? null,
                ])->all(),
            ];
        })->values()->all();

        return [
            'competition' => $data['competition'] ?? null,
            'phase' => $data['phase'] ?? null,
            'stage2_problems' => $data['stage2_problems'] ?? [],
            'watcher' => [
                'leaderboard_synced_at' => $data['watcher']['leaderboard_synced_at'] ?? null,
                'metagraph_synced_at' => $data['watcher']['metagraph_synced_at'] ?? null,
            ],
            'miners' => $miners,
            'built_at' => now()->toIso8601String(),
        ];
    }
}
