<?php

// Rounds 13 and 14 screen on the same five round-12 SOMA tasks (round 14: Simeone, SOMA Discord 2026-11-18 10:33 UTC - only
// the stage-1 tasks stay; screener 2 and the full evaluation use new ones). Short / longer follow their round-12 band labels.
$somaStage1 = [
    'problems' => ['LMCache__LMCache-4380', 'algorithmicsuperintelligence__openevolve-260', 'bentoml__BentoML-5597', 'kernc__backtesting.py-1369', 'lastmile-ai__mcp-agent-303'],
    'short' => ['kernc__backtesting.py-1369', 'lastmile-ai__mcp-agent-303'],
    'longer' => ['LMCache__LMCache-4380', 'algorithmicsuperintelligence__openevolve-260', 'bentoml__BentoML-5597'],
];

return [

    /*
    |--------------------------------------------------------------------------
    | SOMA (Bittensor SN114) dashboard mirror
    |--------------------------------------------------------------------------
    |
    | The public SOMA dashboard spreads one competition over a leaderboard page
    | and one page per miner. This app mirrors all of them into MySQL so they
    | can be read on one screen, and watches the competition through the queue.
    |
    */

    'dashboard_url' => env('SOMA_DASHBOARD_URL', 'https://thesoma.ai/dashboard'),

    // The competition the watcher follows (platform id 151 = round 14, Claude Code + anthropic/claude-haiku-5.5 again, since
    // 2026-11-18; 150 = round 13, 2026-11-06 to 2026-11-18; 149 = round 12, openai/gpt-6-luna, 2026-10-21 to 2026-11-06;
    // 148 = round 11 until 2026-10-21).
    'competition' => (int) env('SOMA_COMPETITION', 151),

    // Platform id => round number and the dates shown on the overview (UTC).
    'competitions' => [
        // round 14: uploads 2026-11-17 14:30 -> 11-24 14:30, evaluation -> 12-01 14:30 (oli, SOMA Discord 2026-11-13 11:24 UTC)
        151 => ['round' => 14, 'upload_deadline' => '2026-11-24 14:30:00', 'evaluation_end' => '2026-12-01 14:30:00'],
        // round 13: the board's timeframe (2026-11-06 00:56 UTC) - uploads reverted and reopened on 2026-11-05, window extended by a day
        150 => ['round' => 13, 'upload_deadline' => '2026-11-11 14:30:00', 'evaluation_end' => '2026-11-17 14:30:00'],
        149 => ['round' => 12, 'upload_deadline' => '2026-10-27 14:30:00', 'evaluation_end' => '2026-11-03 14:30:00'],
        148 => ['round' => 11, 'upload_deadline' => '2026-10-13 14:30:00', 'evaluation_end' => '2026-10-20 14:30:00'],
        147 => ['round' => 10],
        146 => ['round' => 9],
        145 => ['round' => 8],
        112 => ['round' => 7],
    ],

    // Stage-1 problems: the same five public SWE-bench problems in rounds 7-12 (below); a competition listed in
    // stage1_by_competition screens on its own five (App\Services\Soma\Stage1).
    'stage1_problems' => [
        'django__django-13821',
        'django__django-14373',
        'sympy__sympy-19637',
        'sympy__sympy-15875',
        'django__django-12419',
    ],
    'short_problems' => ['django__django-14373', 'sympy__sympy-19637'],
    'longer_problems' => ['django__django-12419', 'django__django-13821', 'sympy__sympy-15875'],
    'stage1_by_competition' => [
        150 => $somaStage1,
        151 => $somaStage1,
    ],

    // Untouched output tokens per step on the evaluation model, per stage-1 problem (soma-miner rounds/round11/runs/cal-base, local
    // untouched runs, 3 per problem, 2026-10-03). The platform publishes its untouched baseline's tokens for every task but
    // not its steps, so the "vs untouched" fingerprint estimates the baseline's steps as its published output tokens per
    // run divided by these (user 2026-10-13: use the platform estimate). Approximate: the local untouched runs themselves
    // took 0.87-1.15x those step counts, except django-14373 (1.67x).
    'untouched_output_per_step' => [
        'django__django-12419' => 712.7,
        'django__django-13821' => 715.7,
        'django__django-14373' => 303.2,
        'sympy__sympy-15875' => 884.9,
        'sympy__sympy-19637' => 212.4,
        // round 13 (Claude Code + Haiku 5.5 on the native path): soma-miner rounds/round13/runs/r13n_base, 6 untouched runs per task,
        // 2026-11-05 (ROUND13.md section 4 P1n - those runs match the platform's baseline at w1p x1.037)
        'LMCache__LMCache-4380' => 392.0,
        'algorithmicsuperintelligence__openevolve-260' => 379.0,
        'bentoml__BentoML-5597' => 388.0,
        'kernc__backtesting.py-1369' => 359.0,
        'lastmile-ai__mcp-agent-303' => 413.0,
    ],
    // Rounds on the same evaluation model as those local runs; other rounds get no step part. Round 14 (151) keeps round 13's
    // agent, model and stage-1 tasks, so the round-13 untouched runs stand for it too.
    'untouched_steps_competitions' => [147, 148, 150, 151],

    // Fingerprints compare a miner's stage-1 runs with this miner of the same competition.
    'reference_hotkey_prefix' => env('SOMA_REFERENCE_PREFIX', '5GTwFUTB'),

    // Our first three entries (prefixes are public in the docs); later entries are found through their coldkey.
    // 2026-10-27: + round 12's submissions 4 and 5 (miner30/entry1, miner30/winner) - the new coldkey miner30 holds them.
    // 2026-11-24: + npa34/default (round 14 submission 9) - the new coldkey npa34 also holds npa34/win and npa34/champion.
    'ours_hotkey_prefixes' => ['5HbfSFdn', '5CAvx57k', '5Gc9RZ62', '5DqL8KFA', '5G3iyRz1', '5ECfwqUX'],

    // Chain data (Taostats). The API key is read from the workspace's .env at run time and never stored here.
    'netuid' => 114,
    'taostats_url' => env('TAOSTATS_URL', 'https://api.taostats.io'),
    'keys_env' => env('SOMA_KEYS_ENV', base_path('../.env')),

    // The research workspace whose JSON files `soma:import` reads.
    'miner_dir' => env('SOMA_MINER_DIR', base_path('../soma-miner')),

    // Owners linked by direct transfers count as one family (soma-miner/rounds/round11/docs/RIVALS11.md sections 4 and 10).
    'family_merges' => [
        '5GZXepcj' => '5E4Yg9MU',
        '5EFhhzBb' => '5GbhNSZL',
        '5FjvkCDc' => '5GbhNSZL',
        '5G9RWw2M' => '5DXFftAh',
        '5Ejg9g9W' => '5FZpBy7W',
        '5GgyL5qS' => '5FZpBy7W',
        // B operator (round-11 winner, round-12 1st): five coldkeys (soma-miner rounds/round12/docs/RIVALS12.md section 8.3 and the
        // round table). Kestrel 2 5Hg4XATK, Egret 5FP9LkeB and Dune 2 5H6Bck7G are joined by money (master wallet 5HeTQ7im); Raven 2
        // and Bittern 2 by behaviour only - round-11 uploads seconds apart and runs correlated 0.96-0.99 - and created 2026-10-08/09
        // like the other three. Merged by hand 2026-11-11 01:3x UTC (online_analysis); remove these two lines to split them again.
        '5FvjRnmh' => '5H6Bck7G',
        '5HQK1ZZv' => '5H6Bck7G',
    ],

    // Funding links (user 2026-10-27: families were read from direct transfers only): a wallet of these kinds (Wallet::kindFor -
    // personal = at most 300 transfers) that traded with two or more owners and sent TAO to at least one of them (a shared funder,
    // or money passing owner -> wallet -> owner) merges them into one family. A wallet they only paid into (a seller, a service -
    // sub_analysis's "weak" grade, RIVALS12 section 23.1) and exchange-like or medium wallets stay evidence only.
    'family_link_wallet_kinds' => ['personal'],

    // Family names by family key (root coldkey prefix, or "ours"). Families without one take their team name from the
    // SN114 team dossier (dossier_dir), else a stable generated name.
    'family_names' => [
        'ours' => 'Ours',
        '5HankNTn' => 'SOMA-09',
        '5FyNuuEE' => 'R10 long winner',
        '5EJCkaSV' => 'R10 M+L winner',
        '5GjJdTzg' => 'R10 winner (5GTwFUTB)',
        '5DoE1bDF' => 'Gittensor cluster',
        '5FZaemUc' => 'Approved pair',
        '5CDfCb2W' => 'Sympy-15875 high',
        '5FZpBy7W' => 'Claims miners (SN111)',
        '5HRBHUiW' => 'HiveMEo-funded',
        '5FNcAaEL' => 'Multi-subnet newcomer',
        '5GbhNSZL' => 'Same-minute trio',
        '5GnPv7vL' => 'SN62 Ridges miner',
        '5FHrQMjz' => 'SN114 owner (uid 0)',
        '5H6Bck7G' => 'B operator (R11 winner, R12 1st)',
    ],
    'dossier_dir' => env('SOMA_DOSSIER_DIR', base_path('../SN114-SOMA')),

    // Short notes shown next to known owners (first eight characters of the coldkey).
    'owner_labels' => [
        '5HankNTn' => 'SOMA-09 (round 9: 25%)',
        '5FyNuuEE' => 'round 10 long winner',
        '5EJCkaSV' => 'round 10 {medium,long} winner (15%)',
        '5DoE1bDF' => 'deep cut, rejected-trio shape (stage-1 leaders 10-12)',
        '5HjKxgyS' => 'deep cut, new owner (coldkey made 10-12)',
        '5FZaemUc' => 'approved-side team',
        '5CDfCb2W' => 'high on sympy-15875',
        '5FZpBy7W' => 'SN111 Claims miners, 11 hotkeys',
        '5E4Yg9MU' => 'largest family (21 entries in round 11)',
        '5GjJdTzg' => 'round 10 winner family',
        '5HRBHUiW' => '5HiveMEo-funded family',
        '5FNcAaEL' => 'new multi-subnet owner',
        '5GnPv7vL' => 'SN62 Ridges miner, round 10: 29/60',
        '5FHrQMjz' => 'subnet owner hotkey uid 0 - took the unallocated emission (2026-01-13 -> 06-22), not a miner',
    ],

    // Owners of the current competition are traced again when their last trace is older than this (days): transfers and
    // registrations made after the first trace can link families (36 of 59 round-13 owners were last traced 2026-10-27).
    'reprofile_days' => (float) env('SOMA_REPROFILE_DAYS', 3),

    // Watcher cadence (minutes).
    'poll' => [
        'leaderboard' => (int) env('SOMA_POLL_LEADERBOARD', 5),
        'metagraph' => (int) env('SOMA_POLL_METAGRAPH', 10),
        'hotkeys' => (int) env('SOMA_POLL_HOTKEYS', 10),
    ],

    // The Hotkeys page reads the chain itself (scripts/chain_snapshot.py, bittensor from the research workspace's venv); our
    // hotkeys are the public *pub.txt files of soma-miner tools/prune_order.py, so the page and the submissions watch agree.
    'chain' => [
        'python' => env('SOMA_CHAIN_PYTHON', base_path('../soma-miner/.venv/bin/python')),
        'script' => base_path('scripts/chain_snapshot.py'),
    ],
];
