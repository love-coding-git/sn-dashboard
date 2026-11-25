<?php

namespace App\Http\Controllers\Soma;

use App\Http\Controllers\Controller;
use App\Models\Competition;
use App\Models\Miner;
use App\Services\Soma\Presenter;
use Inertia\Inertia;
use Inertia\Response;

class MinerController extends Controller
{
    /**
     * One miner in full: tasks, runs, owner, family, money and earlier rounds.
     */
    public function __invoke(int $competition, string $hotkey, Presenter $presenter): Response
    {
        $comp = Competition::findOrFail($competition);
        $miner = Miner::where('competition_id', $comp->id)->where('hotkey', $hotkey)->firstOrFail();

        return Inertia::render('soma/miner', $presenter->miner($comp, $miner));
    }
}
