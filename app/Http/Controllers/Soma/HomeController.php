<?php

namespace App\Http\Controllers\Soma;

use App\Http\Controllers\Controller;
use App\Models\Competition;
use App\Services\Soma\Hotkeys;
use App\Services\Soma\Presenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    /**
     * The first page: where the current round stands and where our entries are, at a glance (user 2026-11-25:
     * "첫 페지에서 필요한 정보를 한눈에 볼수 있게 하라"). Same data as the leaderboard page, drawn as a summary.
     */
    public function __invoke(Request $request, Presenter $presenter, Hotkeys $hotkeys): Response
    {
        $id = $request->integer('comp', (int) config('soma.competition'));
        $competition = Competition::find($id) ?? Competition::ensure((int) config('soma.competition'));

        return Inertia::render('soma/home', $presenter->overview($competition) + ['hotkeys' => self::hotkeys($hotkeys)]);
    }

    /**
     * Our registered SN114 hotkeys for the round page (user 2026-11-25: "해당 서브넷페지에 해당 등록된 핫키의 정보도 입력하라"):
     * the chain snapshot's time and pace, and per registered hotkey its uid, emission, place in the deregistration queue
     * and the label of its entry in the current competition. The full table stays on the Our hotkeys page.
     */
    private static function hotkeys(Hotkeys $hotkeys): array
    {
        $page = $hotkeys->page();
        $current = (int) ($page['current']['id'] ?? 0);

        return [
            'taken_at' => $page['snapshot']['taken_at'] ?? null,
            'per_day_24h' => $page['snapshot']['per_day_24h'] ?? null,
            'per_day_7d' => $page['snapshot']['per_day_7d'] ?? null,
            'ours' => collect($page['ours'] ?? [])->where('registered', true)->map(fn ($o) => [
                'name' => $o['name'], 'hotkey' => $o['hotkey'], 'uid' => $o['uid'], 'emission' => $o['emission'], 'immune' => $o['immune'],
                'immune_hours_left' => $o['immune_hours_left'], 'rank' => $o['rank'], 'rank_day_ago' => $o['rank_day_ago'],
                'eta_days_24h' => $o['eta_days_24h'], 'eta_days_7d' => $o['eta_days_7d'], 'age_days' => $o['age_days'],
                'label' => collect($o['entries'] ?? [])->firstWhere('competition', $current)['label'] ?? null,
            ])->values()->all(),
        ];
    }
}
