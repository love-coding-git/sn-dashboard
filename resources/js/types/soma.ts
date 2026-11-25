export type Fingerprint = {
    steps_x: number;
    short_x: number;
    out_step_x: number;
    ctx_x: number;
    rebill_slope: number | null;
    class: 'deep-all' | 'short-only' | 'notice-like' | 'light' | string;
    // vs the untouched agent: tokens per run from the platform's published baseline (exact); steps estimated from the
    // baseline's output tokens per run / the untouched output per step (approximate)
    u_tokens_x?: number | null;
    u_output_x?: number | null;
    u_uncached_x?: number | null;
    u_cached_x?: number | null;
    u_steps_x?: number | null;
    u_short_x?: number | null;
};

export type Categories = { short: number | null; medium: number | null; long: number | null };

export type TaskCell = {
    name: string;
    is_screener: boolean;
    complexity: string | null;
    passed: number | null;
    finished: number;
    total: number | null;
    baseline_passed: number | null;
    baseline_total: number | null;
    score: number | null;
    ratio: number | null;
    steps: number | null;
    run?: number | null;
};

export type HistoryRow = {
    competition: number;
    round: number | null;
    hotkey?: string;
    same_hotkey?: boolean;
    status: string | null;
    place: number | null;
    of: number | null;
    overall: number | null;
    stage1: number | null;
    categories: Categories;
    fingerprint?: Fingerprint | null;
};

export type MinerRow = {
    id: number;
    hotkey: string;
    place: number | null;
    rank?: number | null;
    status: string | null;
    stage1_score: number | null;
    stage1_verdict: string | null;
    stage2_score: number | null;
    stage2_verdict: string | null;
    overall: number | null;
    categories: Categories;
    last_submit_at: string | null;
    first_seen_at: string | null;
    uid: number | null;
    coldkey: string | null;
    is_ours: boolean;
    our_label: string | null;
    family: string | null;
    family_name: string | null;
    owner_label: string | null;
    owner_created_on: string | null;
    fingerprint: Fingerprint | null;
    stage1?: Record<string, TaskCell>;
    stage2_rank?: number | null;
    advanced?: boolean;
    stage2?: Record<string, TaskCell>;
    full?: Record<string, TaskCell>;
    full_progress?: FullProgress | null;
    live?: LiveScore | null;
    history?: HistoryRow[];
};

/** The score an entry stands at now (platform formula over the SOMA tasks with at least 3 graded runs). */
export type LiveScore = {
    overall: number | null;
    short: number | null;
    medium: number | null;
    long: number | null;
    tasks: number;
    done: number;
    short_tasks: number;
    medium_tasks: number;
    long_tasks: number;
};

/** Full-evaluation runs of one entry: planned, run (tokens recorded), graded, passed. */
export type FullProgress = { planned: number; run: number; graded: number; passed: number };

export type TaskRef = { name: string; band: string | null };

/** The stage-2 advance line: max(60, 20%) best stage-2 scores among the stage-1 passers. */
export type CutInfo = { size: number; line: number | null; cohort: number; advanced: number };

/** stage1 = uploads and stage 1, stage2 = stage 2 running, full = full evaluation running, scored = final. */
export type Phase = 'stage1' | 'stage2' | 'full' | 'scored';

export type DirectLink = { a: string; b: string; n: number; tao: number; first: string | null };

export type SharedWallet = { address: string; kind: string | null; transfers: number | null; owners: string[]; others: string[] };

export type FamilyBest = { score: number; hotkey: string; place: number | null; estimate: boolean };

export type FamilySummary = {
    key: string;
    name: string;
    label: string | null;
    is_ours: boolean;
    owners: { coldkey: string; created_on: string | null; profiled: boolean }[];
    entries: number;
    qualified: number;
    screening: number;
    /** entries in this competition by status (scored, failed review, evaluating, qualified, not qualified, ...) */
    statuses: Record<string, number>;
    best_stage1: number | null;
    best_hotkey: string | null;
    best_stage2: { score: number; hotkey: string } | null;
    /** the family's best full-evaluation value: the platform's once scored, else the running estimate */
    best_overall: FamilyBest | null;
    best_bands: Record<'short' | 'medium' | 'long', FamilyBest | null>;
    /** the best single entry for each two-band element: the mean of its two band values */
    best_pairs: Record<'short+medium' | 'short+long' | 'medium+long', FamilyBest | null>;
    hotkeys: string[];
    earlier: {
        competition: number;
        round: number | null;
        entries: number;
        scored: number;
        failed_review: number;
        best_place: number | null;
        of: number | null;
        best_overall: number | null;
    }[];
    subnets: Record<string, number>;
    direct_links: DirectLink[];
    shared_wallets: SharedWallet[];
};

export type WatchEvent = {
    id: number;
    at: string;
    type: 'upload' | 'stage1_done' | 'status' | 'registration' | string;
    hotkey: string;
    coldkey: string | null;
    uid: number | null;
    is_ours: boolean;
    source: string;
    data: Record<string, string | number | null> | null;
};

export type CompetitionInfo = {
    id: number;
    round: number | null;
    upload_deadline_at: string | null;
    evaluation_end_at: string | null;
    leaderboard_synced_at: string | null;
    is_current: boolean;
};

export type WatcherInfo = {
    leaderboard_synced_at: string | null;
    metagraph_synced_at: string | null;
    pending: Record<string, number>;
    failed: number;
    owners_traced: number;
    owners: number;
    /** entries of this competition whose owner (coldkey) is still unknown */
    unowned: number;
    poll_minutes: { leaderboard: number; metagraph: number };
};

export type EarningRow = {
    key: string;
    name: string;
    label: string | null;
    coldkeys: string[];
    entries: number;
    sn114: number;
    last30: number;
    now: number;
    months: Record<string, number>;
    others: Record<string, number>;
    others_total: number;
};

export type EarningsCoverage = { days: number; from: string | null; to: string | null; other_hotkeys: number; queued: number };
