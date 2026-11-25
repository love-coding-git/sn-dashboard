<?php

namespace App\Console\Commands;

use App\Jobs\Soma\ProfileOwner;
use App\Jobs\Soma\ResolveOwner;
use App\Models\Miner;
use App\Models\Owner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('soma:owners {--comp= : competition id (default config soma.competition)} {--stale= : re-trace owners last traced more than this many days ago (default config soma.reprofile_days)}')]
#[Description('Refresh the owners of one competition now: look up the owner of every entry without one, re-trace every stale owner (profile queue)')]
class SomaOwners extends Command
{
    public function handle(): int
    {
        $comp = (int) ($this->option('comp') ?: config('soma.competition'));
        $days = (float) ($this->option('stale') ?? config('soma.reprofile_days', 3));
        $resolved = ResolveOwner::queueMissing(null, $comp);
        $coldkeys = Miner::where('competition_id', $comp)->whereNotNull('coldkey')->distinct()->pluck('coldkey');
        $stale = Owner::whereIn('coldkey', $coldkeys)
            ->where(fn ($q) => $q->whereNull('profiled_at')->orWhere('profiled_at', '<', now()->subDays($days)))
            ->orderBy('profiled_at')->pluck('coldkey');
        // after the lookups (20 s apart), one owner a minute - the profile queue shares Taostats with the watcher
        $start = now()->addSeconds(20 * $resolved->count());
        foreach ($stale->values() as $i => $coldkey) {
            ProfileOwner::dispatch($coldkey)->delay($start->copy()->addMinutes($i));
        }
        $this->info("competition $comp: {$resolved->count()} owner lookups and {$stale->count()} owner traces queued on the profile queue");

        return self::SUCCESS;
    }
}
