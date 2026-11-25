<?php

use App\Jobs\Soma\FetchEarnings;
use App\Jobs\Soma\SyncHotkeys;
use App\Jobs\Soma\SyncLeaderboard;
use App\Jobs\Soma\SyncMetagraph;
use App\Jobs\Sn34\SyncBoard as Sn34Board;
use App\Jobs\Sn34\SyncCard as Sn34Card;
use App\Jobs\Sn34\SyncMetagraph as Sn34Metagraph;
use App\Jobs\Sn34\SyncRuns as Sn34Runs;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The SOMA watcher: the scheduler puts these jobs on the queue, `php artisan queue:work` runs them.
Schedule::job(new SyncLeaderboard((int) config('soma.competition')))
    ->cron('*/'.max(1, (int) config('soma.poll.leaderboard')).' * * * *')
    ->name('soma-leaderboard')
    ->withoutOverlapping();

Schedule::job(new SyncMetagraph)
    ->cron('*/'.max(1, (int) config('soma.poll.metagraph')).' * * * *')
    ->name('soma-metagraph')
    ->withoutOverlapping();

// Our hotkeys straight from the chain (registration, emission, deregistration place) for the Hotkeys page.
Schedule::job(new SyncHotkeys)
    ->cron('*/'.max(1, (int) config('soma.poll.hotkeys')).' * * * *')
    ->name('soma-hotkeys')
    ->withoutOverlapping();

// Family earnings: yesterday's SN114 rewards, read once the day has closed.
Schedule::call(fn () => FetchEarnings::dispatch((int) config('soma.netuid'), now('UTC')->subDay()->toDateString()))
    ->dailyAt('00:30')
    ->name('soma-earnings');

// SN34 (Yugos) page: public Yugos API and the netuid-34 metagraph, on their own queue and worker ('sn34', bin/soma-watch
// queue-sn34) so a slow or failing SN34 read never stops the SOMA watcher. Owner traces go to 'profile', behind the SOMA jobs.
Schedule::job(new Sn34Board, 'sn34')->cron('*/'.max(1, (int) config('sn34.poll.board')).' * * * *')->name('sn34-board')->withoutOverlapping();
Schedule::job(new Sn34Runs, 'sn34')->cron('*/'.max(1, (int) config('sn34.poll.runs')).' * * * *')->name('sn34-runs')->withoutOverlapping();
Schedule::job(new Sn34Card, 'sn34')->cron('*/'.max(1, (int) config('sn34.poll.card')).' * * * *')->name('sn34-card')->withoutOverlapping();
Schedule::job(new Sn34Metagraph, 'sn34')->cron('*/'.max(1, (int) config('sn34.poll.metagraph')).' * * * *')->name('sn34-metagraph')->withoutOverlapping();
