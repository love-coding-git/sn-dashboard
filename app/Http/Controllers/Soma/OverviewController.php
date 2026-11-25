<?php

namespace App\Http\Controllers\Soma;

use App\Http\Controllers\Controller;
use App\Models\Competition;
use App\Services\Soma\Presenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OverviewController extends Controller
{
    /**
     * One competition on one screen.
     */
    public function __invoke(Request $request, Presenter $presenter): Response
    {
        $id = $request->integer('comp', (int) config('soma.competition'));
        $competition = Competition::find($id) ?? Competition::ensure((int) config('soma.competition'));

        return Inertia::render('soma/overview', $presenter->overview($competition));
    }
}
