<?php

namespace App\Console\Commands;

use App\Jobs\Soma\FetchEarnings;
use App\Models\OwnerRegistration;
use App\Services\Soma\Earnings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('soma:earnings {--since=2026-01-01 : first UTC day to fill} {--refresh : read days already stored again} {--others : also queue the other-subnet hotkeys of SN114 owners}')]
#[Description('Queue the Taostats reads behind the family earnings: every SN114 day (newest first) and, with --others, the other-subnet hotkeys of SN114 owners')]
class SomaEarnings extends Command
{
    public function handle(): int
    {
        $netuid = (int) config('soma.netuid');
        $day = CarbonImmutable::now('UTC')->subDay()->startOfDay();
        $since = CarbonImmutable::parse($this->option('since'), 'UTC');
        $n = 0;
        for (; $day->gte($since); $day = $day->subDay()) {
            if ($this->option('refresh') || ! Earnings::fetched("$netuid:".$day->toDateString())) {
                FetchEarnings::dispatch($netuid, $day->toDateString())->delay(now()->addSeconds(15 * $n));
                $n++;
            }
        }
        $m = 0;
        if ($this->option('others')) {
            OwnerRegistration::where('netuid', '!=', $netuid)->get(['netuid', 'hotkey'])->unique(fn ($r) => $r->netuid.':'.$r->hotkey)
                ->each(function ($r) use (&$m, $n) {
                    if (! Earnings::fetched($r->netuid.':'.$r->hotkey)) {
                        FetchEarnings::dispatch($r->netuid, null, $r->hotkey)->delay(now()->addSeconds(15 * ($n + $m)));
                        $m++;
                    }
                });
        }
        $this->info("queued $n SN$netuid days and $m other-subnet hotkeys");

        return self::SUCCESS;
    }
}
