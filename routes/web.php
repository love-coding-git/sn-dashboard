<?php

use App\Http\Controllers\HubController;
use App\Http\Controllers\Soma\HomeController;
use App\Http\Controllers\Soma\HotkeysController;
use App\Http\Controllers\Soma\MinerController;
use App\Http\Controllers\Soma\OverviewController;
use App\Http\Controllers\Sn34\OverviewController as Sn34Overview;
use Illuminate\Support\Facades\Route;

// The SOMA mirror is local-only (served on 127.0.0.1), so its pages need no login.
// The cross-subnet overview (SOMA and Yugos side by side, 2026-11-25)
Route::get('/', HubController::class)->name('home');
// The first page (summary of the current round, 2026-11-25 redesign) and the full leaderboard with its tabs
Route::get('soma', HomeController::class)->name('soma.home');
Route::get('soma/board', OverviewController::class)->name('soma.overview');
Route::get('soma/hotkeys', HotkeysController::class)->name('soma.hotkeys');
// SN34 (Yugos) on one screen (user 2026-11-25; SN34 files by the NPA/34 session sub_analysis)
Route::get('sn34', Sn34Overview::class)->name('sn34.overview');
Route::get('soma/{competition}/{hotkey}', MinerController::class)->whereNumber('competition')->name('soma.miner');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', fn () => redirect()->route('home'))->name('dashboard');
});

require __DIR__.'/settings.php';
