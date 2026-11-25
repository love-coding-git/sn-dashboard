<?php

namespace App\Services\Soma;

use App\Models\Earning;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Mining rewards from Taostats daily metagraph snapshots. `daily_mining_alpha_as_tao` is the day's mining reward of a
 * hotkey valued in TAO; the 23:59 UTC snapshot closes the day. One request reads a whole subnet day (256 uids), so SN114
 * is filled day by day; other subnets are read per hotkey. Steady emission (SN114) is captured well; a subnet that pays
 * in short bursts between snapshots is undercounted.
 */
class Earnings
{
    /** Pages read for one subnet day (200 rows a page; SN114 needs three). */
    public const MAX_DAY_PAGES = 10;

    public function __construct(private Taostats $taostats) {}

    /**
     * Store every paid hotkey of one subnet for one UTC day; returns the number of rows kept.
     */
    public function subnetDay(int $netuid, CarbonInterface $day): int
    {
        $start = Carbon::parse($day->toDateString().' 22:30:00', 'UTC');
        $latest = [];
        // pages of at most 200 rows (the /v1 API's cap); one 150-minute window of SN114 held 512 rows on 2026-11-15
        for ($page = 1; $page <= self::MAX_DAY_PAGES; $page++) {
            $res = $this->taostats->get('/v1/subnets/metagraph/history', [
                'netuid' => $netuid, 'timestamp_start' => $start->timestamp, 'timestamp_end' => $start->copy()->addMinutes(150)->timestamp,
                'limit' => 200, 'page' => $page,
            ]);
            if (isset($res['_error'])) {
                throw new \RuntimeException('taostats: '.$res['_error']);
            }
            foreach ($res['data'] ?? [] as $r) {
                $hotkey = Taostats::ss58($r['hotkey'] ?? null);
                if ($hotkey !== '' && (! isset($latest[$hotkey]) || ($r['timestamp'] ?? '') > ($latest[$hotkey]['timestamp'] ?? ''))) {
                    $latest[$hotkey] = $r;
                }
            }
            if (! ($res['pagination']['next_page'] ?? null)) {
                break;
            }
        }
        $kept = $this->store($netuid, $day->toDateString(), $latest);
        DB::table('earning_fetches')->updateOrInsert(['scope' => "$netuid:".$day->toDateString()], ['rows' => $kept, 'fetched_at' => now()]);

        return $kept;
    }

    /**
     * Store one hotkey's whole history on a subnet (used for the other subnets of SN114 owners).
     */
    public function hotkeyHistory(int $netuid, string $hotkey): int
    {
        $kept = 0;
        for ($page = 1; $page <= 5; $page++) {
            $res = $this->taostats->get('/v1/subnets/metagraph/history', ['netuid' => $netuid, 'hotkey' => $hotkey, 'limit' => 200, 'page' => $page]);
            if (isset($res['_error'])) {
                throw new \RuntimeException('taostats: '.$res['_error']);
            }
            $byDay = [];
            foreach ($res['data'] ?? [] as $r) {
                $day = substr((string) ($r['timestamp'] ?? ''), 0, 10);
                if ($day !== '' && (! isset($byDay[$day]) || ($r['timestamp'] ?? '') > ($byDay[$day]['timestamp'] ?? ''))) {
                    $byDay[$day] = $r;
                }
            }
            foreach ($byDay as $day => $r) {
                $kept += $this->store($netuid, $day, [$hotkey => $r]);
            }
            if (! ($res['pagination']['next_page'] ?? null)) {
                break;
            }
        }
        DB::table('earning_fetches')->updateOrInsert(['scope' => "$netuid:$hotkey"], ['rows' => $kept, 'fetched_at' => now()]);

        return $kept;
    }

    public static function fetched(string $scope): bool
    {
        return DB::table('earning_fetches')->where('scope', $scope)->exists();
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows  hotkey => metagraph row
     */
    private function store(int $netuid, string $day, array $rows): int
    {
        $kept = 0;
        foreach ($rows as $hotkey => $r) {
            $tao = ((float) ($r['daily_mining_alpha_as_tao'] ?? 0)) / 1e9;
            if ($tao <= 0) {
                continue;
            }
            $coldkey = Taostats::ss58($r['coldkey'] ?? null);
            if ($coldkey !== '') {
                \App\Models\Owner::ensure($coldkey);
            }
            Earning::updateOrCreate(['netuid' => $netuid, 'day' => $day, 'hotkey' => $hotkey], [
                'coldkey' => $coldkey,
                'tao' => $tao,
                'alpha' => ((float) ($r['daily_mining_alpha'] ?? 0)) / 1e9,
                'incentive' => isset($r['incentive']) ? (float) $r['incentive'] : null,
            ]);
            $kept++;
        }

        return $kept;
    }
}
