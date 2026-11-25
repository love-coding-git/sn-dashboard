<?php

namespace App\Services\Sn34;

use App\Models\Sn34\Board;
use App\Models\Sn34\Card;
use App\Models\Sn34\Event;
use App\Models\Sn34\Neuron;
use App\Models\Sn34\Owner;
use App\Models\Sn34\Run;
use App\Models\Sn34\SeatChange;
use App\Models\Sn34\Verdict;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

/**
 * Stores what the SN34 jobs read and records an event for every change worth seeing on the page.
 * Rules learned on the live host (NPA/34 watch records, 2026-11-22 - 11-25):
 * - a started_at that changes after results are "available" is a second attempt at items left ungraded, not a restart;
 * - "failed" and a queue "error" are a state now, never the end: a run can turn "completed" hours later and be judged.
 */
class Ingest
{
    public const CLASSES = ['agentic', 'chat_qa', 'math_code'];

    /** @var array<string, string>|null */
    private ?array $map = null;

    /**
     * @param  array<int, array<string, mixed>>  $rows  history submissions
     */
    public function history(array $rows, bool $detect = true): Collection
    {
        $events = collect();
        $coldkeys = $this->coldkeys();
        foreach ($rows as $r) {
            $id = (string) ($r['run_id'] ?? '');
            if ($id === '' || empty($r['hotkey'])) {
                continue;
            }
            $run = Run::find($id);
            $pack = is_array($r['pack'] ?? null) ? ($r['pack']['id'] ?? null) : ($r['pack'] ?? null);
            $attrs = [
                'hotkey' => $r['hotkey'],
                'coldkey' => $coldkeys[$r['hotkey']] ?? $run?->coldkey,
                'state' => (string) ($r['state'] ?? 'unknown'),
                'enqueued_at' => self::time($r['enqueued_at'] ?? null),
                'started_at' => self::time($r['started_at'] ?? null),
                'finished_at' => self::time($r['finished_at'] ?? null),
                'submission_sha256' => $r['submission_sha256'] ?? null,
            ];
            if ($pack) {
                $attrs['pack'] = $pack;
            }
            if ($run === null) {
                Run::create($attrs + ['run_id' => $id, 'first_seen_at' => now()]);
                if ($detect) {
                    $events->push($this->event('new_run', $id, $attrs['hotkey'], $attrs['coldkey'], ['state' => $attrs['state'], 'pack' => $pack]));
                }

                continue;
            }
            $before = ['state' => $run->state, 'started_at' => $run->started_at?->toIso8601String()];
            // compared in whole seconds: the API gives microseconds, the column keeps seconds (else every read is a 'change')
            if ($run->started_at && $attrs['started_at'] && $run->started_at->getTimestamp() !== $attrs['started_at']->getTimestamp()) {
                $attrs['started_at_changes'] = $run->started_at_changes + 1;
                $detect && $events->push($this->event('second_attempt', $id, $run->hotkey, $run->coldkey, [
                    'old_started_at' => $before['started_at'], 'new_started_at' => $attrs['started_at']->toIso8601String(),
                    'ungraded' => $run->progress['ungraded'] ?? null, 'results' => $run->progress['_status'] ?? null,
                ]));
            }
            if ($run->state !== $attrs['state'] && $detect) {
                $events->push($this->event('state', $id, $run->hotkey, $run->coldkey, ['from' => $run->state, 'to' => $attrs['state']]));
            }
            $run->update($attrs);
        }

        return $events;
    }

    /**
     * @param  array<int, array<string, mixed>>  $runs  /competition/results runs[]
     */
    public function results(array $runs): Collection
    {
        $events = collect();
        foreach ($runs as $r) {
            $run = Run::find($r['run_id'] ?? '');
            $p = $r['summary'] ?? $r['progress'] ?? null;
            if ($run === null || ! is_array($p)) {
                continue;
            }
            $p['_status'] = $r['status'] ?? null;
            $was = $run->progress ?? [];
            $complete = (bool) ($p['complete'] ?? false);
            $endedUngraded = ($p['executed'] ?? 0) > 0 && ($p['executed'] ?? 0) === ($p['assigned'] ?? -1) && ($p['ungraded'] ?? 0) > 0;
            $wasEnded = ($was['executed'] ?? 0) > 0 && ($was['executed'] ?? 0) === ($was['assigned'] ?? -1) && ($was['ungraded'] ?? 0) > 0;
            if ($endedUngraded && ! $wasEnded) {
                $events->push($this->event('ungraded_at_end', $run->run_id, $run->hotkey, $run->coldkey, ['ungraded' => $p['ungraded'], 'executed' => $p['executed']]));
            }
            if ($complete && ! $run->complete) {
                $events->push($this->event('results_complete', $run->run_id, $run->hotkey, $run->coldkey, ['graded' => $p['graded'] ?? null]));
            }
            $run->update(['progress' => $p, 'complete' => $complete, 'progress_at' => now()]);
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $b  /competition/board
     */
    public function board(array $b, bool $detect = true, ?CarbonInterface $readAt = null): Collection
    {
        $events = collect();
        $confirmed = self::time($b['confirmed_at'] ?? null);
        if ($confirmed === null) {
            return $events;
        }
        $policy = $b['policy_id'] ?? null;
        Board::firstOrCreate(['policy_id' => $policy, 'confirmed_at' => $confirmed], [
            'phase' => $b['phase'] ?? null, 'paused' => $b['paused'] ?? null, 'kings' => $b['kings'] ?? [],
            'published_weights' => $b['published_weights'] ?? [], 'high_water_marks' => $b['high_water_marks'] ?? [],
            'queue' => $b['queue'] ?? [], 'challenges' => count($b['challenges'] ?? []), 'read_at' => $readAt ?? now(),
        ]);

        foreach (array_merge($b['kings'] ?? [], $b['challenges'] ?? []) as $e) {
            if (empty($e['run_id']) || empty($e['measurements'])) {
                continue;
            }
            $v = Verdict::firstWhere('run_id', $e['run_id']);
            $attrs = [
                'hotkey' => $e['hotkey'] ?? '', 'policy_id' => $policy, 'eligible' => $e['eligible'] ?? null,
                'promoted' => $e['promoted'] ?? null, 'net_value' => $e['net_value'] ?? null, 'lower_bound' => $e['lower_bound'] ?? null,
                'reasons' => $e['reasons'] ?? [], 'classes' => self::classes($e), 'confirmed_at' => $confirmed,
            ];
            if ($v === null) {
                Verdict::create($attrs + ['run_id' => $e['run_id'], 'first_confirmed_at' => $confirmed]);
                if ($detect) {
                    $reasons = $e['reasons'] ?? [];
                    $events->push($this->event('verdict', $e['run_id'], $attrs['hotkey'], $this->coldkeys()[$attrs['hotkey']] ?? null, [
                        'eligible' => $attrs['eligible'], 'promoted' => $attrs['promoted'], 'net_value' => $attrs['net_value'],
                        'lower_bound' => $attrs['lower_bound'], 'reasons' => $reasons,
                        'near_miss' => ! ($attrs['eligible'] ?? false) && count($reasons) === 1,
                    ]));
                }
            } elseif (abs((float) $v->net_value - (float) $attrs['net_value']) > 1e-9 || (bool) $v->promoted !== (bool) $attrs['promoted']) {
                $v->update($attrs);
            } else {
                $v->update(['confirmed_at' => $confirmed]);
            }
        }

        $seats = collect($b['kings'] ?? [])->map(fn ($k) => [$k['seat'] ?? null, $k['hotkey'] ?? null, $k['run_id'] ?? null, $k['seat_share'] ?? null])->values()->all();
        $last = SeatChange::orderByDesc('from_at')->first();
        if ($last === null || $last->seats !== $seats) {
            SeatChange::create(['from_at' => $confirmed, 'policy_id' => $policy, 'seats' => $seats, 'weights' => $b['published_weights'] ?? []]);
            if ($detect && $last !== null) {
                $events->push($this->event('seats', null, $seats[0][1] ?? null, null, ['from' => $last->seats, 'to' => $seats, 'weights' => $b['published_weights'] ?? []]));
            }
        }
        foreach ($b['queue'] ?? [] as $q) {
            if (($q['state'] ?? null) === 'error' && $detect) {
                $known = Event::where('type', 'queue_error')->where('run_id', $q['run_id'] ?? '')->exists();
                if (! $known) {
                    $events->push($this->event('queue_error', $q['run_id'] ?? null, $q['hotkey'] ?? null, null, ['reason' => $q['reason'] ?? null]));
                }
            }
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $c  /world/cards/active
     * @param  array<int, array<string, mixed>>  $choices  our route choices (provider_id, model_id, effort)
     */
    public function card(array $c, array $choices, bool $detect = true, bool $keepFull = true): Collection
    {
        $events = collect();
        $sha = (string) ($c['sha256'] ?? '');
        if ($sha === '' || Card::where('sha256', $sha)->exists()) {
            return $events;
        }
        $view = self::choiceView($c['card'] ?? [], $choices);
        $prev = Card::orderByDesc('synced_at')->first();
        Card::create(['sha256' => $sha, 'synced_at' => self::time($c['synced_at'] ?? null), 'source' => $c['source'] ?? null,
            'our_choices' => $view, 'card' => $keepFull ? json_encode($c['card'] ?? []) : null, 'read_at' => now()]);
        if ($detect && $prev) {
            $moved = [];
            foreach ($view as $i => $row) {
                $old = $prev->our_choices[$i] ?? null;
                if ($old && ($old['present'] !== $row['present'] || $old['format'] !== $row['format'])) {
                    $moved[] = ['choice' => $row['choice'], 'from' => [$old['present'], $old['format']], 'to' => [$row['present'], $row['format']]];
                }
            }
            $events->push($this->event($moved ? 'card_choice' : 'card', null, null, null, ['sha' => substr($sha, 0, 8), 'moved' => $moved]));
        }

        return $events;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows  Taostats /v1/subnets/metagraph rows
     */
    public function metagraph(array $rows): int
    {
        $before = Neuron::count();
        if (! $rows || count($rows) < $before / 2) {
            // an empty or short read never replaces a full table (review 2026-11-25)
            return 0;
        }
        $now = now();
        DB::transaction(function () use ($rows, $now) {
            Neuron::query()->delete();
            foreach ($rows as $n) {
                $hotkey = self::ss58($n['hotkey'] ?? null);
                if ($hotkey === '') {
                    continue;
                }
                Neuron::create([
                'uid' => (int) ($n['uid'] ?? 0), 'hotkey' => $hotkey, 'coldkey' => self::ss58($n['coldkey'] ?? null),
                // Taostats /v1/subnets/metagraph: incentive (0..1), emission (rao per tempo, the pruning measure),
                // daily_mining_alpha (_as_tao) in rao, registered_at_block, block_number, is_immune, in_danger
                'registered_at' => self::blockTime($n),
                'registered_at_block' => isset($n['registered_at_block']) ? (int) $n['registered_at_block'] : null,
                'block_number' => isset($n['block_number']) ? (int) $n['block_number'] : null,
                'immune' => isset($n['is_immune']) ? (bool) $n['is_immune'] : null,
                'in_danger' => isset($n['in_danger']) ? (bool) $n['in_danger'] : null,
                'incentive' => isset($n['incentive']) ? (float) $n['incentive'] : null,
                'emission' => isset($n['emission']) ? (float) $n['emission'] : null,
                'daily_alpha' => isset($n['daily_mining_alpha']) ? ((float) $n['daily_mining_alpha']) / 1e9 : null,
                'daily_tao' => isset($n['daily_mining_alpha_as_tao']) ? ((float) $n['daily_mining_alpha_as_tao']) / 1e9 : null,
                'read_at' => $now,
                ]);
            }
        });
        foreach (Neuron::pluck('coldkey', 'hotkey') as $hotkey => $coldkey) {
            Run::where('hotkey', $hotkey)->whereNull('coldkey')->update(['coldkey' => $coldkey]);
        }

        return count($rows);
    }

    /**
     * Per class: quality, cost, provider-call seconds, hard quality, floors, failed gates and the net-value parts.
     *
     * @param  array<string, mixed>  $e  one board entry
     */
    public static function classes(array $e): array
    {
        $meas = collect($e['measurements'] ?? [])->keyBy('category');
        $out = [];
        foreach (self::CLASSES as $c) {
            $m = $meas[$c] ?? [];
            $g = $e['categories'][$c]['gates'] ?? [];
            $nv = $e['categories'][$c]['net_value'] ?? [];
            if (! $m && ! $g) {
                continue;
            }
            $out[$c] = [
                'q' => $m['quality'] ?? null, 'usd' => $m['cost_usd'] ?? null, 's' => $m['execution_time_s'] ?? null,
                'hard' => $g['hard_quality'] ?? null, 'q_floor' => $g['quality_floor'] ?? null,
                'hard_floor' => $g['hard_quality_floor'] ?? null, 'private_floor' => $g['private_quality_floor'] ?? null,
                'fails' => $g['failures'] ?? [], 'nv' => $nv['net_value'] ?? null, 'd_usd' => $nv['spend_delta_usd'] ?? null,
                'd_regular' => $nv['regular_answer_delta'] ?? null, 'd_hard' => $nv['hard_answer_delta'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Our route choices on one card: present with the effort, the api format the card gives, input / output price.
     *
     * @param  array<string, mixed>  $card
     * @param  array<int, array<string, mixed>>  $choices
     */
    public static function choiceView(array $card, array $choices): array
    {
        $providers = collect($card['providers'] ?? [])->keyBy('provider_id');
        $rows = [];
        foreach ($choices as $ch) {
            $p = $providers[$ch['provider_id']] ?? [];
            $model = collect($p['models'] ?? [])->firstWhere('model_id', $ch['model_id']);
            $price = $p['token_prices'][$ch['model_id']] ?? [];
            $rows[] = [
                'choice' => $ch['provider_id'].'/'.$ch['model_id'].':'.($ch['effort'] ?? ''),
                'present' => $model !== null && in_array($ch['effort'] ?? null, $model['effort_levels'] ?? [], true),
                'format' => $model === null ? 'absent' : ($p['api_formats'][$ch['model_id']] ?? $p['api_format'] ?? 'default'),
                'in' => $price['input_usd_per_million'] ?? null, 'out' => $price['output_usd_per_million'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * Our hosted route choices (required models plus the fallback), config sn34.our_choices.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function ourChoices(): array
    {
        return array_values((array) config('sn34.our_choices', []));
    }

    /** hotkey => coldkey from the newest metagraph read and the traced owners (read once per job). */
    public function coldkeys(): array
    {
        if ($this->map === null) {
            $this->map = Neuron::pluck('coldkey', 'hotkey')->all();
            foreach (Owner::all(['coldkey', 'hotkeys']) as $o) {
                foreach ($o->hotkeys ?? [] as $h) {
                    $this->map[$h] ??= $o->coldkey;
                }
            }
        }

        return $this->map;
    }

    /** Registration time estimated from blocks: the read's timestamp minus (block_number - registered_at_block) x block time. */
    public static function blockTime(array $n): ?Carbon
    {
        if (! isset($n['registered_at_block'], $n['block_number'], $n['timestamp'])) {
            return null;
        }

        return Carbon::parse($n['timestamp'])->utc()->subSeconds(((int) $n['block_number'] - (int) $n['registered_at_block']) * (int) config('sn34.block_seconds', 12));
    }

    public static function time(mixed $v): ?Carbon
    {
        return is_string($v) && $v !== '' ? Carbon::parse($v)->utc() : null;
    }

    public static function ss58(mixed $x): string
    {
        return is_array($x) ? (string) ($x['ss58'] ?? '') : (string) ($x ?? '');
    }

    private function event(string $type, ?string $runId, ?string $hotkey, ?string $coldkey, array $data): Event
    {
        return Event::create(['occurred_at' => now(), 'type' => $type, 'run_id' => $runId, 'hotkey' => $hotkey, 'coldkey' => $coldkey, 'data' => $data]);
    }
}
