<?php

namespace App\Console\Commands;

use App\Models\WatchEvent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('soma:wait-event {--after= : last event id already seen (default: the newest now)} {--hours=30 : give up after this long} {--every=20 : seconds between checks}')]
#[Description('Block until the queue records a new watch event, print it and exit (lets a terminal session be woken by the watcher)')]
class SomaWaitEvent extends Command
{
    public function handle(): int
    {
        $after = $this->option('after') !== null ? (int) $this->option('after') : (int) WatchEvent::max('id');
        $until = now()->addMinutes((int) round(60 * (float) $this->option('hours')));
        while (now()->lt($until)) {
            $events = WatchEvent::where('id', '>', $after)->orderBy('id')->get();
            if ($events->isNotEmpty()) {
                foreach ($events as $e) {
                    $this->line(sprintf('#%d %s UTC  %-12s %s%s  %s', $e->id, $e->occurred_at->format('Y-m-d H:i:s'), $e->type, $e->hotkey,
                        $e->is_ours ? ' (ours)' : '', json_encode($e->data)));
                }

                return self::SUCCESS;
            }
            sleep(max(5, (int) $this->option('every')));
        }
        $this->line('no new event');

        return self::SUCCESS;
    }
}
