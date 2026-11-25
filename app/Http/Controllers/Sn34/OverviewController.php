<?php

namespace App\Http\Controllers\Sn34;

use App\Http\Controllers\Controller;
use App\Services\Sn34\Presenter;
use Inertia\Inertia;
use Inertia\Response;

class OverviewController extends Controller
{
    /**
     * The Yugos (SN34) competition on one screen.
     */
    public function __invoke(Presenter $presenter): Response
    {
        return Inertia::render('sn34/overview', $presenter->overview());
    }
}
