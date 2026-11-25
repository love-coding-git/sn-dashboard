<?php

namespace App\Http\Controllers\Soma;

use App\Http\Controllers\Controller;
use App\Services\Soma\Hotkeys;
use Inertia\Inertia;
use Inertia\Response;

class HotkeysController extends Controller
{
    /**
     * Our SN114 hotkeys: registration, emission and the place in the deregistration queue.
     */
    public function __invoke(Hotkeys $hotkeys): Response
    {
        return Inertia::render('soma/hotkeys', $hotkeys->page());
    }
}
