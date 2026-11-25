<?php

namespace App\Console\Commands;

use App\Models\Miner;
use App\Services\Soma\Ingest;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('soma:fingerprints {--comp=* : competitions to recompute (default: all with stage-1 tasks)}')]
#[Description('Recompute the stage-1 fingerprints (vs the reference miner and vs untouched) from the stored tasks and runs')]
class SomaFingerprints extends Command
{
    public function handle(Ingest $ingest): int
    {
        $comps = array_map('intval', $this->option('comp')) ?: Miner::query()->distinct()->pluck('competition_id')->all();
        foreach ($comps as $comp) {
            $reference = $ingest->referenceShape($comp);
            $done = $withUntouched = 0;
            Miner::where('competition_id', $comp)->whereNotNull('tasks_synced_at')->each(function (Miner $miner) use ($ingest, $reference, &$done, &$withUntouched) {
                $fp = $ingest->fingerprint($miner, $reference);
                $miner->forceFill(['fingerprint' => $fp])->save();
                $done++;
                $withUntouched += isset($fp['u_tokens_x']) ? 1 : 0;
            });
            $this->line("competition {$comp}: {$done} miners recomputed, {$withUntouched} with the untouched part".($reference ? '' : ' (no reference miner)'));
        }

        return self::SUCCESS;
    }
}
