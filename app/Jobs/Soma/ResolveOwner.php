<?php

namespace App\Jobs\Soma;

use App\Models\Miner;
use App\Models\Owner;
use App\Models\OwnerRegistration;
use App\Services\Soma\Families;
use App\Services\Soma\Ingest;
use App\Services\Soma\Taostats;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Find the owner of an entry whose hotkey never showed up in a metagraph poll (deregistered before the watcher saw it,
 * 30 of 179 round-13 entries on 2026-11-10): the hotkey's SN114 registration on Taostats names its coldkey.
 */
class ResolveOwner implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 2;

    public function __construct(public string $hotkey)
    {
        // first in line on the Taostats worker (--queue=resolve,owners,profile): on 2026-11-11 a lookup waited over an hour
        // behind 268 earnings reads on the shared 'profile' queue while the stage-1 leader showed 'owner unknown'
        $this->onQueue('resolve');
    }

    /**
     * Queue a lookup for entries of the current competition that have no owner yet, each hotkey at most once per 15
     * minutes (a registration Taostats has not indexed yet is asked again soon, not on every poll).
     *
     * @return Collection<int, string> the hotkeys queued
     */
    public static function queueMissing(?int $limit = 10, int $competition = 0): Collection
    {
        $hotkeys = Miner::where('competition_id', $competition ?: config('soma.competition'))->whereNull('coldkey')
            ->pluck('hotkey')->unique()->reject(fn (string $h) => Cache::has("soma:resolve:$h"))->values();
        $hotkeys = $limit === null ? $hotkeys : $hotkeys->take($limit);
        foreach ($hotkeys as $i => $h) {
            Cache::put("soma:resolve:$h", true, now()->addMinutes(15));
            self::dispatch($h)->delay(now()->addSeconds(20 * $i));
        }

        return $hotkeys;
    }

    public function uniqueId(): string
    {
        return $this->hotkey;
    }

    public function handle(Taostats $taostats, Families $families, Ingest $ingest): void
    {
        $netuid = (int) config('soma.netuid');
        $rows = $taostats->get('/v1/subnets/neuron-registration-events', ['hotkey' => $this->hotkey, 'netuid' => $netuid, 'limit' => 20])['data'] ?? [];
        // the latest registration of this hotkey on SN114 (a hotkey belongs to one coldkey, so they all agree in practice)
        $latest = collect($rows)->filter(fn ($r) => Taostats::ss58($r['coldkey'] ?? null) !== '')->sortByDesc(fn ($r) => $r['block_number'] ?? 0)->first();
        if (! $latest) {
            return;
        }
        $coldkey = Taostats::ss58($latest['coldkey']);
        OwnerRegistration::updateOrCreate([
            'coldkey' => $coldkey,
            'netuid' => $netuid,
            'hotkey' => $this->hotkey,
            'registered_at' => isset($latest['timestamp']) ? Carbon::parse($latest['timestamp']) : null,
        ], ['uid' => $latest['uid'] ?? null, 'cost_tao' => isset($latest['registration_cost']) ? ((float) $latest['registration_cost']) / 1e9 : null]);
        // the uid is left alone: a deregistered hotkey's uid belongs to someone else now
        Miner::where('hotkey', $this->hotkey)->whereNull('coldkey')->update(['coldkey' => $coldkey]);
        $owner = Owner::ensure($coldkey);
        $families->refresh();
        $ingest->linkMiners();     // our entries are marked by coldkey
        if (! $owner->profiled_at) {
            ProfileOwner::dispatch($coldkey);
        }
    }
}
