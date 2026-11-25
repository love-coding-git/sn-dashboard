<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SN34 (Yugos) dashboard page
    |--------------------------------------------------------------------------
    |
    | The /sn34 page (user 2026-11-25 13:46 UTC): the Yugos competition on one
    | screen - seats, runs in progress, decisions, operator families, seat
    | history, per-dataset results, our route choices on the world card and the
    | watch records of the NPA/34 sub_analysis session. Its queue jobs read the
    | public Yugos API and Taostats themselves (user 2026-11-25 13:55 UTC).
    |
    */

    'netuid' => 34,

    // Public Yugos API (no key). Endpoints: /health, /competition/submissions, /competition/board, /competition/results,
    // /world/cards/active.
    'api_url' => env('SN34_API_URL', 'https://upload.yugos.ai'),

    // Minutes between reads.
    'poll' => [
        'board' => (int) env('SN34_POLL_BOARD', 2),
        'runs' => (int) env('SN34_POLL_RUNS', 1),
        'card' => (int) env('SN34_POLL_CARD', 10),
        'metagraph' => (int) env('SN34_POLL_METAGRAPH', 10),
    ],

    // /competition/results takes at most this many run ids per call.
    'results_batch' => 20,

    // Owner tracing (Taostats, shared key and throttle with the SOMA jobs): only the coldkeys that submitted in the
    // current pack, re-traced at most this often, on the lowest-priority queue.
    'trace_queue' => env('SN34_TRACE_QUEUE', 'profile'),
    'reprofile_days' => (float) env('SN34_REPROFILE_DAYS', 1),
    'trace_pages' => 3,
    'wallet_lookups' => 5,

    // A wallet with at most this many transfers is personal (a shared one is evidence); at least EXCHANGE is exchange-like.
    'wallet_personal' => 300,
    'wallet_exchange' => 1500,

    // Our hotkeys on SN34 (prefixes), with the wallet/hotkey name the NPA/34 records use (names only, never key files).
    // Every other hotkey of the same coldkey that is registered on netuid 34 is listed as ours too.
    'ours_hotkeys' => ['5ECfwqUX'],
    'our_names' => ['5ECfwqUX' => 'npa34/default'],

    // netuid-34 immunity period in blocks (chain read 2026-11-04 18:13 UTC, data/chain/20261104T1813Z_sn34_family_5H3pFE46)
    // and the block time; the immunity flag itself comes from each metagraph read.
    'immunity_period' => (int) env('SN34_IMMUNITY_PERIOD', 5000),
    'block_seconds' => 12,

    // Our hosted route choices, flagged on every world card (provider/model:effort). From the NPA/34 bundle
    // data/local/20261116T1228Z_rzh9h/zip_models.json (13 entries).
    'our_choices' => [
        ['provider_id' => 'gm', 'model_id' => 'gpt-6-luna', 'effort' => 'medium'],
        ['provider_id' => 'gm', 'model_id' => 'gpt-6.1-sol', 'effort' => 'medium'],
        ['provider_id' => 'gm', 'model_id' => 'gpt-6.1-terra', 'effort' => 'medium'],
        ['provider_id' => 'gm', 'model_id' => 'claude-sonnet-5-5', 'effort' => 'medium'],
        ['provider_id' => 'gm', 'model_id' => 'claude-opus-5-5', 'effort' => 'medium'],
        ['provider_id' => 'gm', 'model_id' => 'gpt-6.1-sol', 'effort' => 'high'],
        ['provider_id' => 'gm', 'model_id' => 'gpt-6.1-terra', 'effort' => 'low'],
        ['provider_id' => 'gm', 'model_id' => 'gpt-6-luna', 'effort' => 'low'],
        ['provider_id' => 'gm', 'model_id' => 'claude-haiku-5-5', 'effort' => 'medium'],
        ['provider_id' => 'gm', 'model_id' => 'glm-5.3-flash', 'effort' => 'high'],
        ['provider_id' => 'gm', 'model_id' => 'gpt-6.1-sol', 'effort' => 'low'],
        ['provider_id' => 'engy', 'model_id' => 'glm-5.3', 'effort' => null],
        ['provider_id' => 'engy', 'model_id' => 'glm-5.3', 'effort' => null],
    ],

    // One-off history import written by NPA/34 tools/sn34_dashboard_feed.py --import-bundle.
    'import_file' => env('SN34_IMPORT_FILE'),

    // The NPA/34 notes feed, only on the machine the watch runs on: `sn34:notes` loads it into the database every minute.
    'notes_file' => env('SN34_NOTES_FILE'),

    // Owners merged into one family by hand: [[coldkey, coldkey, ...], ...]. Empty: families come from direct transfers.
    'family_merges' => [],
];
