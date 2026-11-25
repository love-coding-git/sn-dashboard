<?php

namespace App\Console\Commands;

use App\Models\Competition;
use App\Models\Miner;
use App\Models\Neuron;
use App\Models\Owner;
use App\Models\WatchEvent;
use App\Models\OwnerRegistration;
use App\Models\Wallet;
use App\Services\Soma\Families;
use App\Services\Soma\Ingest;
use App\Services\Soma\OwnerProfiler;
use App\Services\Soma\Taostats;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

#[Signature('soma:import {--only= : comma-separated competition ids (default: all known)}')]
#[Description('Import the SOMA data already collected in soma-miner/intel and soma-miner/rounds/*/intel (rounds 7-11, chain, watcher events) into MySQL')]
class SomaImport extends Command
{
    /** Leaderboard + miner task files of the earlier rounds. */
    private const ROUND_FILES = [
        112 => 'rounds/earlier/intel/comp112_all_20261008.json',
        145 => 'rounds/earlier/intel/comp145_all_20261008.json',
        146 => 'rounds/earlier/intel/comp146_all_20261008.json',
        147 => 'rounds/round10/intel/comp147_all.json',
    ];

    public function handle(Ingest $ingest): int
    {
        $dir = rtrim(config('soma.miner_dir'), '/');
        if (! is_dir("$dir/intel")) {
            $this->error("no intel folder under $dir (config soma.miner_dir)");

            return self::FAILURE;
        }
        $only = $this->option('only') ? array_map('intval', explode(',', $this->option('only'))) : null;
        $want = fn (int $id) => $only === null || in_array($id, $only, true);

        $this->importChain($dir, $ingest);
        foreach (self::ROUND_FILES as $id => $file) {
            if ($want($id) && is_file("$dir/$file")) {
                $this->importRound($id, "$dir/$file", $ingest);
            }
        }
        if ($want(148)) {
            $this->importCurrent($dir, $ingest);
        }
        $this->importEvents($dir);
        $this->importTransfers($dir);
        $this->importRegistrations($dir);
        $ingest->linkMiners();
        $families = app(Families::class)->refresh();
        $this->line('families: '.count(array_unique($families)).' over '.count($families).' owners');
        $this->info('done: '.Miner::count().' miners, '.Neuron::count().' neurons, '.WatchEvent::count().' events');

        return self::SUCCESS;
    }

    private function importChain(string $dir, Ingest $ingest): void
    {
        $state = $this->json("$dir/rounds/round11/intel/watch11/state.json");
        $rows = [];
        foreach (($state['chain'] ?? []) as $uid => $v) {
            $rows[] = ['uid' => (int) $uid, 'hotkey' => $v['hotkey'] ?? '', 'coldkey' => $v['coldkey'] ?? '', 'block' => $v['block'] ?? null];
        }
        $ingest->metagraph((int) config('soma.netuid'), $rows, false);
        foreach (($this->json("$dir/rounds/round11/intel/rivals_20261012/chain/comp11_cold_of.json") ?? []) as $hotkey => $coldkey) {
            if ($coldkey) {
                Owner::ensure($coldkey);
                Neuron::firstOrCreate(['netuid' => config('soma.netuid'), 'hotkey' => $hotkey],
                    ['coldkey' => $coldkey, 'registered' => false, 'first_seen_at' => now()]);
            }
        }
        $this->line('chain: '.count($rows).' uids from the watcher state, owners: '.Owner::count());
    }

    private function importRound(int $id, string $file, Ingest $ingest): void
    {
        $this->line("competition $id: reading ".basename($file).' …');
        $data = $this->json($file);
        $competition = Competition::ensure($id);
        $ingest->leaderboard($competition, $data['leaderboard'] ?? [], false);
        $miners = $data['miners'] ?? [];
        if ($id === 147 && is_file(dirname($file).'/rejected_trio_147_20261006.json')) {
            foreach ($this->json(dirname($file).'/rejected_trio_147_20261006.json') as $hk => $v) {
                $miners[$hk] = $v;
                Miner::where('competition_id', $id)->where('hotkey', $hk)->update(['status' => 'failed review']);
            }
        }
        $this->storeTasks($competition, $miners, $ingest, Carbon::parse($data['collected_utc'] ?? 'now'));
        unset($data);
    }

    private function importCurrent(string $dir, Ingest $ingest): void
    {
        $competition = Competition::ensure(148);
        $board = $this->json("$dir/rounds/round11/intel/rivals_20261012/dashboard/lb148.json") ?? [];
        $ingest->leaderboard($competition, $board, false);
        // the watcher's last poll is newer than the saved leaderboard: take its status, stage-1 score and lastSubmit
        $state = $this->json("$dir/rounds/round11/intel/watch11/state.json")['board'] ?? [];
        $rows = [];
        foreach ($state as $hotkey => $v) {
            $rows[] = ['hotkey' => $hotkey, 'place' => $v['place'] ?? null, 'status' => $v['status'] ?? null,
                'stage1' => ['score' => $v['stage1'] ?? null, 'verdict' => $v['verdict'] ?? null],
                'overall' => $v['overall'] ?? null, 'lastSubmit' => $v['lastSubmit'] ?? null];
        }
        $ingest->leaderboard($competition, $rows, false);
        $miners = [];
        foreach (["$dir/rounds/round11/intel/rivals_20261012/dashboard", "$dir/rounds/round11/intel/watch11/dash"] as $folder) {
            foreach (glob("$folder/tasks148_*.json") ?: [] as $f) {
                $hk8 = substr(basename($f, '.json'), strlen('tasks148_'));
                $hotkey = Miner::where('competition_id', 148)->where('hotkey', 'like', $hk8.'%')->value('hotkey');
                if ($hotkey) {
                    $miners[$hotkey] = ['tasks' => $this->json($f) ?? []];
                }
            }
        }
        $this->storeTasks($competition, $miners, $ingest, now());
    }

    /**
     * @param  array<string, mixed>  $miners  hotkey => ['tasks' => [...]] or a list of tasks
     */
    private function storeTasks(Competition $competition, array $miners, Ingest $ingest, CarbonInterface $at): void
    {
        $bar = $this->output->createProgressBar(count($miners));
        $stored = [];
        foreach ($miners as $hotkey => $v) {
            $miner = Miner::where('competition_id', $competition->id)->where('hotkey', $hotkey)->first()
                ?? Miner::create(['competition_id' => $competition->id, 'hotkey' => $hotkey, 'first_seen_at' => $at]);
            $tasks = is_array($v['tasks'] ?? null) ? $v['tasks'] : (array_is_list($v) ? $v : []);
            $ingest->tasks($miner, $tasks, []);
            $stored[] = $miner;
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
        $reference = $ingest->referenceShape($competition->id);
        foreach ($stored as $miner) {
            $miner->forceFill(['fingerprint' => $reference ? $ingest->fingerprint($miner, $reference) : null, 'tasks_synced_at' => $at])->save();
        }
        $this->line("competition {$competition->id}: ".count($stored).' miners with tasks'.($reference ? '' : ' (no reference miner - no fingerprints)'));
    }

    private function importEvents(string $dir): void
    {
        $file = "$dir/rounds/round11/intel/watch11/events.jsonl";
        if (! is_file($file)) {
            return;
        }
        $n = 0;
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $e = json_decode($line, true);
            if (! is_array($e) || empty($e['hotkey'])) {
                continue;
            }
            $at = Carbon::parse(str_replace(' UTC', '', $e['at']), 'UTC');
            $exists = WatchEvent::where('source', 'python')->where('hotkey', $e['hotkey'])->where('type', $e['type'])->where('occurred_at', $at)->exists();
            if ($exists) {
                continue;
            }
            $neuron = Neuron::where('hotkey', $e['hotkey'])->first();
            WatchEvent::create([
                'occurred_at' => $at, 'type' => $e['type'], 'competition_id' => 148, 'hotkey' => $e['hotkey'],
                'coldkey' => $e['coldkey'] ?? $neuron?->coldkey, 'uid' => $e['uid'] ?? $neuron?->uid,
                'is_ours' => (bool) ($e['ours'] ?? false), 'source' => 'python',
                'data' => array_filter(['status' => $e['status'] ?? null, 'from' => $e['from'] ?? null, 'stage1' => $e['stage1'] ?? null,
                    'lastSubmit' => $e['lastSubmit'] ?? null], fn ($x) => $x !== null),
            ]);
            $n++;
        }
        $this->line("events: $n imported from the Python watcher");
    }

    private function importTransfers(string $dir): void
    {
        $files = array_merge(glob("$dir/rounds/round11/intel/rivals_20261012/chain/tr_*.json") ?: [], glob("$dir/rounds/round11/intel/watch11/family_*/raw/api_transfer_v1_*.json") ?: []);
        $n = 0;
        foreach ($files as $f) {
            $d = $this->json($f);
            $rows = is_array($d['data'] ?? null) ? $d['data'] : [];
            foreach ($rows as $t) {
                OwnerProfiler::storeTransfer($t);
                $n++;
            }
            // the list belongs to the address present in every row; its total is that wallet's transfer count
            $common = null;
            foreach ($rows as $t) {
                $pair = [Taostats::ss58($t['from'] ?? null), Taostats::ss58($t['to'] ?? null)];
                $common = $common === null ? $pair : array_values(array_intersect($common, $pair));
            }
            if ($common && count(array_unique($common)) === 1 && isset($d['pagination']['total_items'])) {
                Wallet::record($common[0], (int) $d['pagination']['total_items']);
            }
        }
        $this->line("transfers: $n rows from ".count($files).' saved lists, wallets classified: '.Wallet::whereNotNull('kind')->count());
    }

    private function importRegistrations(string $dir): void
    {
        $n = 0;
        $store = function (string $coldkey, array $r) use (&$n) {
            $hotkey = Taostats::ss58($r['hotkey'] ?? null);
            if ($coldkey === '' || $hotkey === '') {
                return;
            }
            $at = $r['timestamp'] ?? $r['at'] ?? null;
            OwnerRegistration::updateOrCreate([
                'coldkey' => $coldkey, 'netuid' => (int) ($r['netuid'] ?? 0), 'hotkey' => $hotkey,
                'registered_at' => $at ? Carbon::parse($at) : null,
            ], [
                'uid' => $r['uid'] ?? null,
                'cost_tao' => isset($r['registration_cost']) ? ((float) $r['registration_cost']) / 1e9 : ($r['cost'] ?? null),
            ]);
            $n++;
        };
        foreach (glob("$dir/rounds/round11/intel/watch11/profiles/*.json") ?: [] as $f) {
            $d = $this->json($f);
            foreach (($d['registrations'] ?? []) as $r) {
                $store((string) ($d['coldkey'] ?? ''), $r);
            }
        }
        $raw = array_merge(glob("$dir/rounds/round11/intel/rivals_20261012/chain/reg_*.json") ?: [], glob("$dir/rounds/round11/intel/rivals_20261012/chain/cold_reg_*.json") ?: [],
            glob("$dir/rounds/round11/intel/watch11/family_*/raw/api_subnet_neuron_registration_v1_*.json") ?: []);
        foreach ($raw as $f) {
            foreach (($this->json($f)['data'] ?? []) as $r) {
                $store(Taostats::ss58($r['coldkey'] ?? null), $r);
            }
        }
        // SN114 hotkeys registered by an owner give earlier-round entries their owner
        OwnerRegistration::where('netuid', config('soma.netuid'))->get(['coldkey', 'hotkey'])
            ->each(fn ($r) => Miner::where('hotkey', $r->hotkey)->whereNull('coldkey')->update(['coldkey' => $r->coldkey]));
        $this->line("registrations: $n rows");
    }

    private function json(string $file): mixed
    {
        return is_file($file) ? json_decode(file_get_contents($file), true) : null;
    }
}
