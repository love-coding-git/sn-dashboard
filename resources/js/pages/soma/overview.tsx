import { Head, Link, router, usePoll } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { Activity, Bell, ChartColumn, Clock, Coins, Flag, History, ListChecks, RefreshCw, Search, Star, Trophy, UserRound, Users, X } from 'lucide-react';
import type { MouseEvent, ReactNode } from 'react';
import { Fragment, useMemo, useState } from 'react';
import { EventsList } from '@/components/soma/events-list';
import { HeroChip, Kpi, PageHero, PAGE, TABLE_FRAME, TABS_LIST, TABS_TRIGGER } from '@/components/soma/kit';
import type { KpiTone } from '@/components/soma/kit';
import { FingerprintBadge } from '@/components/soma/fingerprint-badge';
import { KeyLabel } from '@/components/soma/key-label';
import { MoreList } from '@/components/soma/more-list';
import { ProblemCell } from '@/components/soma/problem-cell';
import { SortButton, SortHead } from '@/components/soma/sort-head';
import { StatusBadge } from '@/components/soma/status-badge';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useQueryParam } from '@/hooks/use-query-param';
import type { SortColumn, SortState } from '@/hooks/use-sort';
import { useSort } from '@/hooks/use-sort';
import { useTab } from '@/hooks/use-tab';
import { ago, BAND_TONE, left, num, PROBLEM_SHORT, shortKey, shortTask, STATUS_DOT, utc } from '@/lib/soma';
import { entryState, somaUrl, stage2Progress } from '@/lib/soma-state';
import { cn } from '@/lib/utils';
import { home as homeRoute, miner as minerRoute, overview as overviewRoute } from '@/routes/soma';
import type { CompetitionInfo, CutInfo, EarningRow, EarningsCoverage, FamilySummary, FullProgress, LiveScore, MinerRow, Phase, TaskRef, WatcherInfo, WatchEvent } from '@/types';

type Props = {
    competition: CompetitionInfo;
    competitions: { id: number; round: number | null; miners: number }[];
    problems: string[];
    stage2_problems: TaskRef[];
    full_problems: TaskRef[];
    phase: Phase;
    cut: CutInfo;
    miners: MinerRow[];
    families: FamilySummary[];
    earnings: EarningRow[];
    earnings_coverage: EarningsCoverage;
    events: WatchEvent[];
    watcher: WatcherInfo;
};

const TABS = ['leaderboard', 'stage2', 'full', 'problems', 'ours', 'family', 'families', 'earnings', 'history', 'events'];
const ROUND_IDS = [147, 146, 145, 112];
const NO_CUT_FROM = 151;

/** Stage-2 ranks among the fully scored entries; entries still running or being scored get none. */
function withScoredRanks(rows: MinerRow[], phase: Phase): MinerRow[] {
    if (phase !== 'stage2' && phase !== 'full') {
        return rows;
    }
    const done = rows.filter((m) => entryState(m) === 'done').sort((a, b) => (b.stage2_score ?? -9) - (a.stage2_score ?? -9));
    const rank = new Map(done.map((m, i) => [m.hotkey, i + 1]));

    return rows.map((m) => ({ ...m, stage2_rank: rank.get(m.hotkey) ?? null }));
}

/** S1 < S2 < ... < S10 (not the string order S1 < S10 < S2). */
const labelNo = (m: MinerRow) => Number((m.our_label ?? '').replace(/\D/g, '')) || 999;
const PARTIAL_TIP = 'current platform score - partial: runs without a result still count against it, so it moves until every run is scored';
const tao = (v: number | null | undefined, d = 2) => (v === null || v === undefined || v === 0 ? '—' : `τ${v.toFixed(d)}`);

export default function Overview(props: Props) {
    const { competition, competitions, problems, families, earnings, earnings_coverage: coverage, events, watcher } = props;
    const { stage2_problems: stage2Tasks, full_problems: fullTasks } = props;
    // While stage 2 and the full evaluation run, a rank is a place among the entries whose stage-2 runs are all scored
    // (lib/soma-state.ts): a partial score - runs without a result still count against it - is shown, never ranked.
    const miners = useMemo(() => withScoredRanks(props.miners, props.phase), [props.miners, props.phase]);
    // From competition 151 every stage-1 passer gets the full evaluation (team, 2026-11-25): no advance line to draw.
    const noCut = competition.id >= NO_CUT_FROM;
    const cut = noCut ? { ...props.cut, size: 0, line: null } : props.cut;
    usePoll(60_000, { only: ['miners', 'families', 'earnings', 'earnings_coverage', 'events', 'watcher', 'competition', 'stage2_problems', 'full_problems', 'phase', 'cut'] });

    const [tab, setTab] = useTab('leaderboard', TABS);
    // the family opened by clicking a row (?fam=<family key>): its entries in the same table as ours
    const [fam, setFam] = useQueryParam('fam');
    const openFamily = (key: string | null | undefined) => {
        if (key) {
            setFam(key);
            setTab('family');
        }
    };
    /** Row click opens the row's family; clicks on links, buttons (copy, "+N more") and text selections are left alone. */
    const rowClick = (key: string | null | undefined) => (e: MouseEvent) => {
        if (!key || (e.target as HTMLElement).closest('a,button,input,[role=button]') || window.getSelection()?.toString()) {
            return;
        }

        openFamily(key);
    };
    const [query, setQuery] = useState('');
    const [status, setStatus] = useState('all');
    const now = Date.now();
    // final results are out (while stage 2 runs the platform sends placeholder scores, so the overall is not a signal)
    const scored = props.phase === 'scored';
    // stage 2 or the full evaluation runs: the stage-2 score and rank are what decide and what to look at
    const evalPhase = props.phase === 'stage2' || props.phase === 'full';
    const ours = miners.filter((m) => m.is_ours).sort((a, b) => labelNo(a) - labelNo(b));
    const deadline = competition.upload_deadline_at ? new Date(competition.upload_deadline_at).getTime() : null;
    const pending = Object.values(watcher.pending ?? {}).reduce((a, b) => a + b, 0);
    const counts = useMemo(() => {
        const c: Record<string, number> = {};
        miners.forEach((m) => (c[m.status ?? 'unknown'] = (c[m.status ?? 'unknown'] ?? 0) + 1));

        return c;
    }, [miners]);
    const statuses = ['all', ...Object.keys(counts).sort((a, b) => counts[b] - counts[a])];
    const familySize = useMemo(() => {
        const size: Record<string, number> = {};
        miners.forEach((m) => m.family && (size[m.family] = (size[m.family] ?? 0) + 1));

        return size;
    }, [miners]);
    const familyQualified = useMemo(() => {
        const n: Record<string, number> = {};
        miners.forEach((m) => m.family && PASSED_STAGE1.includes(m.status ?? '') && (n[m.family] = (n[m.family] ?? 0) + 1));

        return n;
    }, [miners]);
    const rows = useMemo(() => {
        const q = query.trim().toLowerCase();

        return miners.filter(
            (m) => (status === 'all' || m.status === status) && (!q || [m.hotkey, m.coldkey, m.family_name, m.owner_label, m.our_label].some((v) => v?.toLowerCase().includes(q))),
        );
    }, [miners, query, status]);
    const known = useMemo(() => new Set(miners.map((m) => m.hotkey)), [miners]);

    // Sortable columns (click a header): the same columns serve the leaderboard, the stage-1 problems and our entries.
    const minerColumns = useMemo(() => {
        // best earlier result of the hotkey: its lowest rank share among the rounds where it was scored
        const earlier = (m: MinerRow) => {
            const shares = (m.history ?? []).filter((h) => h.status === 'scored' && h.place && h.of).map((h) => (h.place ?? 0) / (h.of ?? 1));

            return shares.length ? Math.min(...shares) : null;
        };
        const columns: Record<string, SortColumn<MinerRow>> = {
            rank: { value: (m) => (evalPhase ? (m.stage2_rank ?? null) : (m.place ?? m.rank)) },
            stage2rank: { value: (m) => m.stage2_rank ?? null },
            progress: { value: (m) => progressShare(m.full_progress), first: 'desc' },
            entry: { value: (m) => labelNo(m) },
            hotkey: { value: (m) => m.hotkey },
            family: { value: (m) => m.family_name ?? m.coldkey },
            size: { value: (m) => (m.family ? familySize[m.family] : null), first: 'desc' },
            status: { value: (m) => m.status },
            stage1: { value: (m) => m.stage1_score, first: 'desc' },
            overall: { value: (m) => m.overall ?? m.live?.overall ?? null, first: 'desc' },
            stage2: { value: (m) => m.stage2_score ?? m.stage2_verdict, first: 'desc' },
            short: { value: (m) => m.categories.short ?? m.live?.short ?? null, first: 'desc' },
            medium: { value: (m) => m.categories.medium ?? m.live?.medium ?? null, first: 'desc' },
            long: { value: (m) => m.categories.long ?? m.live?.long ?? null, first: 'desc' },
            stage1rank: { value: (m) => (scored ? m.place : m.rank) },
            s1rank: { value: (m) => m.rank ?? null },
            // rank in the current phase: final place once scored, stage-2 rank while it decides, stage-1 rank before
            now: { value: (m) => (scored ? m.place : evalPhase ? m.stage2_rank : m.rank) ?? null },
            steps: { value: (m) => m.fingerprint?.u_steps_x ?? m.fingerprint?.steps_x },
            earlier: { value: earlier },
            uploaded: { value: (m) => m.last_submit_at, first: 'desc' },
        };
        problems.forEach((p) => (columns[`p:${p}`] = { value: (m) => m.stage1?.[p]?.score, first: 'desc' }));
        stage2Tasks.forEach((t) => (columns[`s2:${t.name}`] = { value: (m) => m.stage2?.[t.name]?.score, first: 'desc' }));
        fullTasks.forEach((t) => (columns[`fe:${t.name}`] = { value: (m) => m.full?.[t.name]?.score, first: 'desc' }));

        return columns;
    }, [scored, evalPhase, familySize, problems, stage2Tasks, fullTasks]);
    // what the family tables rank by: the full-evaluation value once it runs, the stage-2 score while stage 2 runs, else stage 1
    const famStage: 'full' | 'stage2' | 'stage1' = scored || props.phase === 'full' ? 'full' : props.phase === 'stage2' ? 'stage2' : 'stage1';
    const familyColumns = useMemo(() => {
        const columns: Record<string, SortColumn<FamilySummary>> = {
            family: { value: (f) => f.name },
            entries: { value: (f) => f.entries, first: 'desc' },
            best: { value: (f) => (famStage === 'full' ? (f.best_overall?.score ?? null) : famStage === 'stage2' ? (f.best_stage2?.score ?? null) : f.best_stage1), first: 'desc' },
            short: { value: (f) => f.best_bands.short?.score ?? null, first: 'desc' },
            medium: { value: (f) => f.best_bands.medium?.score ?? null, first: 'desc' },
            long: { value: (f) => f.best_bands.long?.score ?? null, first: 'desc' },
            ...Object.fromEntries(PAIRS.map((p) => [p.id, { value: (f: FamilySummary) => f.best_pairs[p.key]?.score ?? null, first: 'desc' as const }])),
            subnets: { value: (f) => Object.keys(f.subnets).length, first: 'desc' },
            links: { value: (f) => f.direct_links.length, first: 'desc' },
            wallets: { value: (f) => f.shared_wallets.length, first: 'desc' },
        };
        // an earlier round: the family's best rank share there (rank / scored entries)
        ROUND_IDS.forEach(
            (id) =>
                (columns[`r${id}`] = {
                    value: (f) => {
                        const e = f.earlier.find((x) => x.competition === id);

                        return e?.best_place && e.of ? e.best_place / e.of : null;
                    },
                }),
        );

        return columns;
    }, [famStage]);
    const earningColumns = useMemo<Record<string, SortColumn<EarningRow>>>(
        () => ({
            family: { value: (e) => e.name },
            entries: { value: (e) => e.entries, first: 'desc' },
            sn114: { value: (e) => e.sn114, first: 'desc' },
            last30: { value: (e) => e.last30, first: 'desc' },
            now: { value: (e) => e.now, first: 'desc' },
            months: { value: (e) => Object.values(e.months).filter((v) => v >= 0.01).length, first: 'desc' },
            others: { value: (e) => e.others_total, first: 'desc' },
        }),
        [],
    );
    const stage2Rows = useMemo(() => rows.filter((m) => m.stage2 && Object.keys(m.stage2).length > 0), [rows]);
    const fullRows = useMemo(() => rows.filter((m) => m.advanced), [rows]);
    const lb = useSort('lb', rows, minerColumns);
    const s2 = useSort('s2', stage2Rows, minerColumns);
    const fe = useSort('fe', fullRows, minerColumns);
    const pr = useSort('pr', rows, minerColumns);
    const ou = useSort('ours', ours, minerColumns);
    const famRows = useMemo(
        () => (fam ? miners.filter((m) => m.family === fam).sort((a, b) => (phaseRank(a, props.phase) ?? 9999) - (phaseRank(b, props.phase) ?? 9999)) : []),
        [miners, fam, props.phase],
    );
    const fr = useSort('family', famRows, minerColumns);
    const famInfo = families.find((f) => f.key === fam) ?? null;
    const famEarning = earnings.find((e) => e.key === fam) ?? null;
    const famName = famInfo?.name ?? famEarning?.name ?? famRows[0]?.family_name ?? fam;
    const fa = useSort('fam', families, familyColumns);
    const hi = useSort('hist', families, familyColumns);
    const ea = useSort('earn', earnings, earningColumns);
    const by = (x: { sort: typeof lb.sort; toggle: (key: string) => void }) => ({ sort: x.sort, onSort: x.toggle });
    const href = (hotkey: string) => minerRoute({ competition: competition.id, hotkey });
    const scoreOf = (m: MinerRow) => (scored ? m.overall : evalPhase ? m.stage2_score : m.stage1_score);
    const rankOf = (m: MinerRow) => (scored ? m.place : evalPhase ? m.stage2_rank : m.rank);
    const ranked = evalPhase ? miners.filter((m) => m.stage2_rank !== null && m.stage2_rank !== undefined).length : miners.filter((m) => scoreOf(m) !== null).length;
    const stage2Count = miners.filter((m) => m.stage2_rank !== null && m.stage2_rank !== undefined).length;
    const runTotals = fullRows.reduce((a, m) => ({ run: a.run + (m.full_progress?.run ?? 0), planned: a.planned + (m.full_progress?.planned ?? 0) }), { run: 0, planned: 0 });
    const leaders = [...miners]
        .filter((m) => scoreOf(m) !== null && (evalPhase || scored || !isLive(m)))
        .sort((a, b) => (scoreOf(b) ?? 0) - (scoreOf(a) ?? 0))
        .slice(0, 3);
    const evaluationEnd = competition.evaluation_end_at ? new Date(competition.evaluation_end_at).getTime() : null;
    const phase = deadline && deadline > now ? 'upload open' : evaluationEnd && evaluationEnd > now ? 'evaluating' : 'finished';
    const phaseLabel = { stage1: 'stage 1', stage2: 'stage 2', full: 'full evaluation', scored: 'scored' }[props.phase];

    const filterBar = (
        <div className="flex flex-wrap items-center gap-2">
            <div className="relative">
                <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input placeholder="search hotkey, coldkey, family…" value={query} onChange={(e) => setQuery(e.target.value)} className="h-9 w-[280px] pr-8 pl-8" />
                {query && (
                    <button type="button" onClick={() => setQuery('')} aria-label="clear search" className="absolute top-1/2 right-2 -translate-y-1/2 text-muted-foreground hover:text-foreground">
                        <X className="size-4" />
                    </button>
                )}
            </div>
            <Select value={status} onValueChange={setStatus}>
                <SelectTrigger className="h-9 w-[160px]">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {statuses.map((s) => (
                        <SelectItem key={s} value={s}>
                            {s}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            <span className="text-sm text-muted-foreground">{rows.length} entries</span>
        </div>
    );

    return (
        <>
            <Head title={`SOMA ${competition.id}`} />
            <div className={cn(PAGE, 'max-w-none')}>
                <PageHero
                    tone="indigo"
                    eyebrow="SOMA · subnet 114"
                    title="Leaderboard"
                    subtitle={
                        <>
                            competition {competition.id}
                            {competition.round ? ` · round ${competition.round}` : ''} · {miners.length} entries
                        </>
                    }
                    right={
                        <div className="flex flex-col items-end gap-2">
                            <Select value={String(competition.id)} onValueChange={(v) => router.get(overviewRoute.url({ query: { comp: v, tab } }))}>
                                <SelectTrigger className="h-9 w-[240px] border-white/30 bg-white/15 text-white backdrop-blur [&_svg]:text-white/80">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {competitions.map((c) => (
                                        <SelectItem key={c.id} value={String(c.id)}>
                                            {c.id} · round {c.round ?? '?'} · {c.miners} entries
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <span className={cn('rounded-full px-3 py-1 text-xs font-medium', phase === 'upload open' ? 'bg-rose-500' : phase === 'evaluating' ? 'bg-amber-500' : 'bg-white/20')}>
                                {phase}
                                {phase === 'evaluating' && ` · ${phaseLabel}`}
                            </span>
                        </div>
                    }
                >
                    <HeroChip>
                        <RefreshCw className="size-3" /> leaderboard {ago(watcher.leaderboard_synced_at, now)}
                    </HeroChip>
                    <HeroChip>chain {ago(watcher.metagraph_synced_at, now)}</HeroChip>
                    <HeroChip>queue {pending}</HeroChip>
                    {watcher.failed > 0 && <HeroChip warn>{watcher.failed} failed (24 h)</HeroChip>}
                    <HeroChip>
                        owners traced {watcher.owners_traced}/{watcher.owners}
                    </HeroChip>
                    {watcher.unowned > 0 && <HeroChip warn>{watcher.unowned} owner unknown</HeroChip>}
                </PageHero>
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <StatCard title="Entries" icon={Users}>
                        <div className="flex items-baseline gap-2">
                            <span className="text-2xl font-semibold tabular-nums">{miners.length}</span>
                            <span className="text-sm text-muted-foreground">in round {competition.round ?? '?'}</span>
                        </div>
                        <div className="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs">
                            {Object.entries(counts)
                                .sort((a, b) => b[1] - a[1])
                                .map(([s, n]) => (
                                    <span key={s} className="inline-flex items-center gap-1.5">
                                        <span className={cn('size-2 rounded-full', STATUS_DOT[s] ?? 'bg-zinc-400')} />
                                        {s} <span className="font-medium tabular-nums">{n}</span>
                                    </span>
                                ))}
                        </div>
                    </StatCard>
                    <StatCard title="Our entries" icon={Star}>
                        {ours.length === 0 ? (
                            <p className="text-sm text-muted-foreground">none in this round</p>
                        ) : (
                            <div className="grid grid-cols-1 gap-y-0.5 sm:grid-cols-2 sm:gap-x-4">
                                {ours.map((m) => (
                                    <a key={m.id} href={somaUrl(competition.id, m.hotkey)} target="_blank" rel="noopener noreferrer" className="-mx-1 flex items-center gap-2 rounded-md px-1 py-0.5 hover:bg-muted/70">
                                        <Badge className="border-transparent bg-indigo-600 text-white">{m.our_label}</Badge>
                                        {/* a running or still-scoring entry's stage-2 value is partial (unscored tasks count as -1): show its state, not the number */}
                                        {evalPhase && (entryState(m) === 'running' || entryState(m) === 'scoring') ? (
                                            <span className="flex items-baseline gap-1 whitespace-nowrap" title={`${entryState(m)}: ${Math.round(100 * stage2Progress(m))}% of the runs scored - ${PARTIAL_TIP}`}>
                                                <span className="font-mono text-sm text-amber-700 tabular-nums dark:text-amber-300">{num(scoreOf(m))}</span>
                                                <span className={cn('text-[10px]', entryState(m) === 'running' ? 'text-sky-600 dark:text-sky-400' : 'text-amber-600 dark:text-amber-400')}>{Math.round(100 * stage2Progress(m))}%</span>
                                            </span>
                                        ) : evalPhase && entryState(m) === 'waiting' ? (
                                            <span className="text-xs text-muted-foreground">waiting</span>
                                        ) : (
                                            <span className="font-mono text-sm tabular-nums">{num(scoreOf(m))}</span>
                                        )}
                                        {evalPhase && m.advanced && <Flag className="size-3 text-emerald-600 dark:text-emerald-400" aria-label="advanced to the full evaluation" />}
                                        <span className="ml-auto text-xs text-muted-foreground tabular-nums">
                                            {rankOf(m) ? `#${rankOf(m)}/${scored ? miners.length : ranked}` : ''}
                                            {props.phase === 'full' && m.full_progress && <> · {pct(m.full_progress)}</>}
                                        </span>
                                    </a>
                                ))}
                            </div>
                        )}
                    </StatCard>
                    <StatCard title={scored ? 'Top overall' : evalPhase ? 'Top of stage 2' : 'Top of stage 1'} icon={Trophy}>
                        <ol className="space-y-0.5">
                            {leaders.map((m, i) => (
                                <li key={m.id}>
                                    <a href={somaUrl(competition.id, m.hotkey)} target="_blank" rel="noopener noreferrer" className={cn('-mx-1 flex items-center gap-2 rounded-md px-1 py-0.5 text-sm hover:bg-muted/70', m.is_ours && 'text-indigo-600 dark:text-indigo-300')}>
                                        <span className="w-3 text-xs text-muted-foreground tabular-nums">{i + 1}</span>
                                        <span className="truncate font-medium">{m.our_label ?? m.family_name ?? shortKey(m.coldkey)}</span>
                                        <span className="hidden font-mono text-xs text-muted-foreground sm:inline">{shortKey(m.hotkey)}</span>
                                        <span className="ml-auto font-mono tabular-nums">{num(scoreOf(m))}</span>
                                    </a>
                                </li>
                            ))}
                        </ol>
                    </StatCard>
                    <StatCard title="Timing" icon={Clock}>
                        {phase === 'upload open' && deadline ? (
                            <>
                                <div className="text-2xl font-semibold text-rose-600 tabular-nums dark:text-rose-400">{left((deadline - now) / 1000)} left</div>
                                <div className="text-xs text-muted-foreground">upload closes {utc(competition.upload_deadline_at)} UTC</div>
                            </>
                        ) : phase === 'evaluating' && evaluationEnd ? (
                            <>
                                <div className="text-2xl font-semibold text-amber-600 tabular-nums dark:text-amber-400">{left((evaluationEnd - now) / 1000)} left</div>
                                <div className="text-xs text-muted-foreground">evaluation ends {utc(competition.evaluation_end_at)} UTC</div>
                            </>
                        ) : (
                            <>
                                <div className="text-2xl font-semibold">finished</div>
                                <div className="text-xs text-muted-foreground">{competition.evaluation_end_at ? `evaluation ended ${utc(competition.evaluation_end_at)} UTC` : 'no dates known'}</div>
                            </>
                        )}
                        {evalPhase && (
                            <div className="mt-1 text-xs text-muted-foreground">
                                {props.phase === 'full'
                                    ? `${cut.advanced} of ${cut.cohort} advanced · full-eval runs ${runTotals.run}/${runTotals.planned}`
                                    : noCut
                                      ? 'stage 2 running · every stage-1 passer gets the full evaluation'
                                      : `stage 2 running · top ${cut.size} of ${cut.cohort} advance`}
                                {cut.line !== null && ` · line ${num(cut.line)}`}
                            </div>
                        )}
                        <div className="mt-1.5 truncate text-xs text-muted-foreground">
                            last event: {events[0] ? `${events[0].type.replace('_', ' ')} ${shortKey(events[0].hotkey)} ${ago(events[0].at, now)}` : '—'}
                        </div>
                    </StatCard>
                </div>

                <Tabs value={tab} onValueChange={setTab} className="min-h-0 flex-1 gap-3">
                    <TabsList className={TABS_LIST}>
                        <TabTrigger value="leaderboard" label="Leaderboard" icon={Trophy} n={miners.length} />
                        {stage2Tasks.length > 0 && <TabTrigger value="stage2" label="Stage 2" icon={ListChecks} n={stage2Rows.length} />}
                        {fullTasks.length > 0 && <TabTrigger value="full" label="Full evaluation" icon={Activity} n={fullRows.length} />}
                        <TabTrigger value="problems" label="Stage 1" icon={ListChecks} />
                        <TabTrigger value="ours" label="Ours" icon={Star} n={ours.length} />
                        {fam && <TabTrigger value="family" label={`Family · ${famName}`} icon={UserRound} n={famRows.length} />}
                        <TabTrigger value="families" label="Families" icon={Users} n={families.length} />
                        <TabTrigger value="earnings" label="Earnings" icon={Coins} n={earnings.length} />
                        <TabTrigger value="history" label="Earlier rounds" icon={History} />
                        <TabTrigger value="events" label="Events" icon={Bell} n={events.length} />
                    </TabsList>

                    <TabsContent value="leaderboard" className="flex min-h-0 flex-col gap-2">
                        {filterBar}
                        <Scroll>
                            <Table className="text-sm">
                                <Head2>
                                    <SortHead id="rank" label="#" {...by(lb)} className="w-12" />
                                    <SortHead id="hotkey" label="hotkey" {...by(lb)} />
                                    <TableHead>
                                        <span className="inline-flex items-center gap-1.5">
                                            <SortButton id="family" label="family" {...by(lb)} />
                                            <span className="text-muted-foreground">·</span>
                                            <SortButton id="size" label="family size" {...by(lb)} />
                                        </span>
                                    </TableHead>
                                    <SortHead id="overall" label="overall" {...by(lb)} className="text-right" />
                                    <SortHead id="stage1" label="stage 1" {...by(lb)} className="text-right" />
                                    <SortHead id="stage2" label="stage 2" {...by(lb)} className="text-right" />
                                    <SortHead id="short" label="short" {...by(lb)} className="text-right" />
                                    <SortHead id="medium" label="medium" {...by(lb)} className="text-right" />
                                    <SortHead id="long" label="long" {...by(lb)} className="text-right" />
                                    <SortHead id="uploaded" label="uploaded (UTC)" {...by(lb)} />
                                    <SortHead id="status" label="status" {...by(lb)} />
                                    {props.phase === 'full' && <SortHead id="progress" label="full-eval runs" {...by(lb)} />}
                                    <SortHead id="steps" label="fingerprint" {...by(lb)} />
                                    <SortHead id="earlier" label="earlier rounds (this hotkey)" {...by(lb)} />
                                </Head2>
                                <TableBody>
                                    {lb.sorted.map((m, i) => (
                                        <Fragment key={m.id}>
                                        {lb.sort === null && cutBefore(lb.sorted, i, props.phase, cut) && <CutRow span={props.phase === 'full' ? 15 : 14} cut={cut} phase={props.phase} />}
                                        <TableRow className={cn('h-14', m.is_ours && 'bg-indigo-500/10 hover:bg-indigo-500/15', m.family && 'cursor-pointer')} onClick={rowClick(m.family)} title={FAMILY_HINT}>
                                            <TableCell className="text-muted-foreground">{evalPhase ? (m.stage2_rank ?? '—') : (m.place ?? m.rank)}</TableCell>
                                            <TableCell>
                                                <HotkeyCell m={m} href={href(m.hotkey)} />
                                            </TableCell>
                                            <TableCell>
                                                <FamilyCell m={m} size={m.family ? familySize[m.family] : 0} qualified={m.family ? (familyQualified[m.family] ?? 0) : 0} />
                                            </TableCell>
                                            <TableCell className="text-right font-mono font-medium">
                                                <OverallCell m={m} running={props.phase === 'full'} />
                                            </TableCell>
                                            <TableCell className="text-right font-mono">
                                                <Stage1Score m={m} />
                                            </TableCell>
                                            <TableCell className="text-right font-mono">
                                                <Stage2Score m={m} />
                                            </TableCell>
                                            {(['short', 'medium', 'long'] as const).map((c) => (
                                                <TableCell key={c} className="text-right font-mono text-muted-foreground">
                                                    <ScoreCell value={m.categories[c]} live={m.live} band={c} digits={2} />
                                                </TableCell>
                                            ))}
                                            <TableCell className="font-mono text-muted-foreground">{utc(m.last_submit_at)}</TableCell>
                                            <TableCell>
                                                <StatusBadge status={m.status} />
                                            </TableCell>
                                            {props.phase === 'full' && (
                                                <TableCell>
                                                    <ProgressCell p={m.full_progress} />
                                                </TableCell>
                                            )}
                                            <TableCell>
                                                <FingerprintBadge fp={m.fingerprint} />
                                            </TableCell>
                                            <TableCell>
                                                <EarlierBadges history={m.history ?? []} />
                                            </TableCell>
                                        </TableRow>
                                        </Fragment>
                                    ))}
                                </TableBody>
                            </Table>
                        </Scroll>
                    </TabsContent>

                    <TabsContent value="stage2" className="flex min-h-0 flex-col gap-2">
                        {filterBar}
                        <p className="text-sm text-muted-foreground">
                            {noCut
                                ? 'From this round every stage-1 passer gets the full evaluation (team, 2026-11-25); the stage-2 score is the final formula over these six tasks, band-blind. Ranks count only the entries whose runs are all scored'
                                : `Stage 2 picks who goes to the full evaluation: the top ${cut.size} of the ${cut.cohort} stage-1 passers by the stage-2 score (the final formula over these six tasks, band-blind)`}
                            {cut.line !== null && (
                                <>
                                    {' '}
                                    — the {cut.size}th score is <span className="font-mono">{num(cut.line)}</span>
                                </>
                            )}
                            . Cells: passes / runs and the task score; green = at or above the baseline, amber = under the 0.8 quality line, red = under 0.5, blue = still running.
                        </p>
                        <Scroll>
                            <Table className="text-sm">
                                <Head2>
                                    <SortHead id="stage2rank" label="#" {...by(s2)} className="w-12" />
                                    <SortHead id="hotkey" label="hotkey" {...by(s2)} />
                                    <SortHead id="family" label="family" {...by(s2)} />
                                    <SortHead id="stage2" label="stage 2" {...by(s2)} className="text-right" />
                                    <SortHead id="status" label="status" {...by(s2)} />
                                    {stage2Tasks.map((t) => (
                                        <SortHead key={t.name} id={`s2:${t.name}`} label={<TaskHead t={t} />} {...by(s2)} className="text-center" />
                                    ))}
                                </Head2>
                                <TableBody>
                                    {s2.sorted.map((m, i) => (
                                        <Fragment key={m.id}>
                                            {s2.sort === null && cutBefore(s2.sorted, i, props.phase, cut) && <CutRow span={5 + stage2Tasks.length} cut={cut} phase={props.phase} />}
                                            <TableRow className={cn(m.is_ours && 'bg-indigo-500/10 hover:bg-indigo-500/15', m.family && 'cursor-pointer')} onClick={rowClick(m.family)} title={FAMILY_HINT}>
                                                <TableCell className="text-muted-foreground">{m.stage2_rank ?? '—'}</TableCell>
                                                <TableCell>
                                                    <HotkeyCell m={m} href={href(m.hotkey)} />
                                                </TableCell>
                                                <TableCell className="font-medium">{m.family_name ?? '—'}</TableCell>
                                                <TableCell className="text-right font-mono font-medium">
                                                    <Stage2Score m={m} />
                                                </TableCell>
                                                <TableCell>
                                                    <StatusBadge status={m.status} />
                                                </TableCell>
                                                {stage2Tasks.map((t) => (
                                                    <TableCell key={t.name} className="w-28 px-1.5">
                                                        <ProblemCell cell={m.stage2?.[t.name]} />
                                                    </TableCell>
                                                ))}
                                            </TableRow>
                                        </Fragment>
                                    ))}
                                </TableBody>
                            </Table>
                        </Scroll>
                    </TabsContent>

                    <TabsContent value="full" className="flex min-h-0 flex-col gap-2">
                        {filterBar}
                        <p className="text-sm text-muted-foreground">
                            The {fullRows.length} entries in the full evaluation, {fullTasks.length} tasks ({bandCounts(fullTasks)}). Runs recorded {runTotals.run} of {runTotals.planned}; a cell
                            reads passes / finished runs while the task runs. Overall · short · medium · long are where each entry stands now: the platform formula over the
                            30 SOMA tasks (stage 2 + these) that have at least 3 graded runs — a running estimate, marked live, until the platform scores the entry.
                        </p>
                        <Scroll>
                            <Table className="text-sm">
                                <Head2>
                                    <SortHead id="stage2rank" label="#" {...by(fe)} className="w-12" />
                                    <SortHead id="hotkey" label="hotkey" {...by(fe)} />
                                    <SortHead id="family" label="family" {...by(fe)} />
                                    <SortHead id="overall" label="overall" {...by(fe)} className="text-right" />
                                    <SortHead id="short" label="short" {...by(fe)} className="text-right" />
                                    <SortHead id="medium" label="medium" {...by(fe)} className="text-right" />
                                    <SortHead id="long" label="long" {...by(fe)} className="text-right" />
                                    <SortHead id="stage2" label="stage 2" {...by(fe)} className="text-right" />
                                    <SortHead id="progress" label="runs" {...by(fe)} />
                                    {fullTasks.map((t) => (
                                        <SortHead key={t.name} id={`fe:${t.name}`} label={<TaskHead t={t} />} {...by(fe)} className="text-center" />
                                    ))}
                                </Head2>
                                <TableBody>
                                    {fe.sorted.map((m) => (
                                        <TableRow key={m.id} className={cn(m.is_ours && 'bg-indigo-500/10 hover:bg-indigo-500/15', m.family && 'cursor-pointer')} onClick={rowClick(m.family)} title={FAMILY_HINT}>
                                            <TableCell className="text-muted-foreground">{m.stage2_rank ?? '—'}</TableCell>
                                            <TableCell>
                                                <HotkeyCell m={m} href={href(m.hotkey)} />
                                            </TableCell>
                                            <TableCell className="font-medium">{m.family_name ?? '—'}</TableCell>
                                            <TableCell className="text-right font-mono font-medium">
                                                <ScoreCell value={m.overall} live={m.live} band="overall" />
                                            </TableCell>
                                            {(['short', 'medium', 'long'] as const).map((c) => (
                                                <TableCell key={c} className="text-right font-mono">
                                                    <ScoreCell value={m.categories[c]} live={m.live} band={c} />
                                                </TableCell>
                                            ))}
                                            <TableCell className="text-right font-mono text-muted-foreground">
                                                <Stage2Score m={m} />
                                            </TableCell>
                                            <TableCell>
                                                <ProgressCell p={m.full_progress} />
                                            </TableCell>
                                            {fullTasks.map((t) => (
                                                <TableCell key={t.name} className="w-24 px-1">
                                                    <ProblemCell cell={m.full?.[t.name]} />
                                                </TableCell>
                                            ))}
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </Scroll>
                    </TabsContent>

                    <TabsContent value="problems" className="flex min-h-0 flex-col gap-2">
                        {filterBar}
                        <p className="text-sm text-muted-foreground">
                            Passes / runs and the platform score per problem. Green = at or above the baseline, amber = under the 0.8 quality line, red = under 0.5, blue = still running.
                        </p>
                        <Scroll>
                            <Table className="text-sm">
                                <Head2>
                                    <SortHead id="rank" label="#" {...by(pr)} className="w-12" />
                                    <SortHead id="hotkey" label="hotkey" {...by(pr)} />
                                    <SortHead id="family" label="family" {...by(pr)} />
                                    <SortHead id="stage1" label="stage 1" {...by(pr)} className="text-right" />
                                    {problems.map((p) => (
                                        <SortHead key={p} id={`p:${p}`} label={PROBLEM_SHORT[p] ?? p} {...by(pr)} className="text-center" />
                                    ))}
                                </Head2>
                                <TableBody>
                                    {pr.sorted.map((m) => (
                                        <TableRow key={m.id} className={cn(m.is_ours && 'bg-indigo-500/10 hover:bg-indigo-500/15', m.family && 'cursor-pointer')} onClick={rowClick(m.family)} title={FAMILY_HINT}>
                                            <TableCell className="text-muted-foreground">{m.place ?? m.rank}</TableCell>
                                            <TableCell>
                                                <HotkeyCell m={m} href={href(m.hotkey)} />
                                            </TableCell>
                                            <TableCell className="font-medium">{m.family_name ?? '—'}</TableCell>
                                            <TableCell className="text-right font-mono">
                                                <Stage1Score m={m} />
                                            </TableCell>
                                            {problems.map((p) => (
                                                <TableCell key={p} className="w-28 px-1.5">
                                                    <ProblemCell cell={m.stage1?.[p]} />
                                                </TableCell>
                                            ))}
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </Scroll>
                    </TabsContent>

                    <TabsContent value="ours" className="flex min-h-0 flex-col gap-2">
                        <Scroll>
                            <EntriesTable
                                rows={ou.sorted}
                                sort={by(ou)}
                                first="entry"
                                empty="no entry of ours in this competition"
                                phase={props.phase}
                                problems={problems}
                                stage2Tasks={stage2Tasks}
                                stage2Count={stage2Count}
                                entries={miners.length}
                                href={href}
                            />
                        </Scroll>
                    </TabsContent>

                    <TabsContent value="family" className="flex min-h-0 flex-col gap-2">
                        {fam ? (
                            <>
                                <FamilyHeader
                                    name={famName ?? fam}
                                    info={famInfo}
                                    earning={famEarning}
                                    rows={famRows}
                                    ranked={ranked}
                                    entries={miners.length}
                                    scoreOf={scoreOf}
                                    rankOf={rankOf}
                                    onClose={() => {
                                        setFam(null);
                                        setTab('families');
                                    }}
                                />
                                <Scroll>
                                    <EntriesTable
                                        rows={fr.sorted}
                                        sort={by(fr)}
                                        first="rank"
                                        empty="this family has no entry in this competition"
                                        phase={props.phase}
                                        problems={problems}
                                        stage2Tasks={stage2Tasks}
                                        stage2Count={stage2Count}
                                        entries={miners.length}
                                        href={href}
                                    />
                                </Scroll>
                            </>
                        ) : (
                            <p className="text-sm text-muted-foreground">Click a row in any table to see that family's entries here.</p>
                        )}
                    </TabsContent>

                    <TabsContent value="families" className="flex min-h-0 flex-col gap-2">
                        <p className="text-sm text-muted-foreground">
                            Families with entries in this competition. One family = owners joined by a direct TAO transfer or a confirmed merge; a personal wallet (≤ 300 transfers) shared with
                            another owner is shown as evidence, exchange-like wallets are ignored.
                            {famStage === 'full' &&
                                ' Scores are the family’s best entry in the full evaluation - overall, per band and per two-band element (an element is one entry’s mean of its bands, as the platform scores it; # = platform place; ≈ = running estimate while the entry is still evaluated; failed review never counts).'}
                        </p>
                        <Scroll>
                            <Table className="text-sm">
                                <Head2>
                                    <SortHead id="family" label="family" {...by(fa)} />
                                    <SortHead id="entries" label="entries" {...by(fa)} className="text-right" />
                                    <SortHead id="best" label={famStage === 'full' ? 'best overall' : famStage === 'stage2' ? 'best stage 2' : 'best stage 1'} {...by(fa)} className="text-right" />
                                    {famStage === 'full' &&
                                        (['short', 'medium', 'long'] as const).map((b) => <SortHead key={b} id={b} label={`best ${b}`} {...by(fa)} className="text-right" />)}
                                    {famStage === 'full' && PAIRS.map((p) => <SortHead key={p.id} id={p.id} label={`best ${p.label}`} {...by(fa)} className="text-right" />)}
                                    <SortHead id="subnets" label="other subnets" {...by(fa)} />
                                    <SortHead id="links" label="direct transfers between owners" {...by(fa)} />
                                    <SortHead id="wallets" label="shared personal wallets" {...by(fa)} />
                                </Head2>
                                <TableBody>
                                    {fa.sorted.map((f) => (
                                        <TableRow key={f.key} className={cn('cursor-pointer align-top', f.is_ours && 'bg-indigo-500/10')} onClick={rowClick(f.key)} title={FAMILY_HINT}>
                                            <TableCell className="min-w-[230px]">
                                                <FamilyName name={f.name} label={f.label} coldkeys={f.owners.map((o) => o.coldkey)} untraced={f.owners.filter((o) => !o.profiled).length} />
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {f.entries}
                                                <div className="text-xs text-muted-foreground">
                                                    {famStage === 'stage1' ? `${f.qualified} qualified${f.screening ? ` · ${f.screening} screening` : ''}` : <StatusCounts statuses={f.statuses} />}
                                                </div>
                                            </TableCell>
                                            {famStage === 'full' ? (
                                                <>
                                                    <TableCell className="text-right font-mono">
                                                        <BestCell best={f.best_overall} />
                                                    </TableCell>
                                                    {(['short', 'medium', 'long'] as const).map((b) => (
                                                        <TableCell key={b} className="text-right font-mono">
                                                            <BestCell best={f.best_bands[b]} tone={BAND_TONE[b]} />
                                                        </TableCell>
                                                    ))}
                                                    {PAIRS.map((p) => (
                                                        <TableCell key={p.id} className="text-right font-mono">
                                                            <BestCell best={f.best_pairs[p.key]} />
                                                        </TableCell>
                                                    ))}
                                                </>
                                            ) : (
                                                <TableCell className="text-right font-mono">
                                                    {num(famStage === 'stage2' ? f.best_stage2?.score : f.best_stage1)}
                                                    {(famStage === 'stage2' ? f.best_stage2?.hotkey : f.best_hotkey) && (
                                                        <div className="text-xs text-muted-foreground">{shortKey(famStage === 'stage2' ? f.best_stage2?.hotkey : f.best_hotkey)}</div>
                                                    )}
                                                </TableCell>
                                            )}
                                            <TableCell className="w-[290px] max-w-[290px] whitespace-normal">
                                                <MoreList
                                                    items={Object.entries(f.subnets).sort((a, b) => b[1] - a[1])}
                                                    limit={5}
                                                    title="Registrations on other subnets"
                                                    render={([n, c]) => (
                                                        <Badge variant="outline" className="font-mono font-normal">
                                                            SN{n} ×{c}
                                                        </Badge>
                                                    )}
                                                />
                                                {Object.keys(f.subnets).length > 0 && (
                                                    <div className="mt-1 text-xs text-muted-foreground">
                                                        {Object.keys(f.subnets).length} subnets · {Object.values(f.subnets).reduce((a, b) => a + b, 0)} registrations
                                                    </div>
                                                )}
                                            </TableCell>
                                            <TableCell className="w-[280px] max-w-[280px] whitespace-normal">
                                                <MoreList
                                                    items={f.direct_links}
                                                    limit={2}
                                                    title="Direct transfers between owners"
                                                    render={(l) => (
                                                        <span className="block font-mono text-xs">
                                                            {shortKey(l.a)} ↔ {shortKey(l.b)} · {l.n}× · τ{l.tao.toFixed(2)}
                                                        </span>
                                                    )}
                                                />
                                            </TableCell>
                                            <TableCell className="w-[300px] max-w-[300px] whitespace-normal">
                                                <MoreList
                                                    items={f.shared_wallets}
                                                    limit={2}
                                                    title="Personal wallets shared with other owners"
                                                    render={(w) => (
                                                        <span className="block font-mono text-xs">
                                                            {shortKey(w.address)} ({w.transfers ?? '?'} tr.) {w.others.length ? `→ ${w.others.map((o) => shortKey(o)).join(', ')}` : '· inside the family'}
                                                        </span>
                                                    )}
                                                />
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </Scroll>
                    </TabsContent>

                    <TabsContent value="earnings" className="flex min-h-0 flex-col gap-2">
                        <p className="text-sm text-muted-foreground">
                            Mining rewards in TAO from Taostats daily snapshots. SN114: {coverage.days} days read ({coverage.from ?? '—'} → {coverage.to ?? '—'})
                            {coverage.queued > 0 ? `, ${coverage.queued} reads still queued (newest days first)` : ''}. Other subnets: {coverage.other_hotkeys} hotkeys read; a subnet that
                            pays in short bursts is undercounted.
                        </p>
                        <Scroll>
                            <Table className="text-sm">
                                <Head2>
                                    <SortHead id="family" label="family" {...by(ea)} />
                                    <SortHead id="entries" label="entries now" {...by(ea)} className="text-right" />
                                    <SortHead id="sn114" label="SN114 earned" {...by(ea)} className="text-right" />
                                    <SortHead id="last30" label="last 30 days" {...by(ea)} className="text-right" />
                                    <SortHead id="now" label="per day now" {...by(ea)} className="text-right" />
                                    <SortHead id="months" label="SN114 by month" {...by(ea)} />
                                    <SortHead id="others" label="other subnets" {...by(ea)} />
                                </Head2>
                                <TableBody>
                                    {ea.sorted.map((e) => (
                                        <TableRow key={e.key} className={cn('cursor-pointer align-top', e.key === 'ours' && 'bg-indigo-500/10')} onClick={rowClick(e.key)} title={FAMILY_HINT}>
                                            <TableCell className="min-w-[230px]">
                                                <FamilyName name={e.name} label={e.label} coldkeys={e.coldkeys} />
                                            </TableCell>
                                            <TableCell className="text-right">{e.entries || '—'}</TableCell>
                                            <TableCell className="text-right font-mono font-medium">{tao(e.sn114)}</TableCell>
                                            <TableCell className="text-right font-mono">{tao(e.last30)}</TableCell>
                                            <TableCell className="text-right font-mono">{tao(e.now, 3)}</TableCell>
                                            <TableCell className="max-w-[360px] whitespace-normal">
                                                <div className="flex flex-wrap gap-1">
                                                    {Object.entries(e.months)
                                                        .filter(([, v]) => v >= 0.01)
                                                        .map(([m, v]) => (
                                                            <Badge key={m} variant="outline" className="font-mono font-normal">
                                                                {m.slice(2)} · {v.toFixed(1)}
                                                            </Badge>
                                                        ))}
                                                </div>
                                            </TableCell>
                                            <TableCell className="w-[300px] max-w-[300px] whitespace-normal">
                                                <div className="font-mono">{tao(e.others_total)}</div>
                                                {Object.keys(e.others).length > 0 && (
                                                    <MoreList
                                                        items={Object.entries(e.others).sort((a, b) => b[1] - a[1])}
                                                        limit={3}
                                                        title="Earnings on other subnets"
                                                        render={([n, v]) => (
                                                            <Badge variant="outline" className="font-mono font-normal">
                                                                SN{n} τ{v.toFixed(2)}
                                                            </Badge>
                                                        )}
                                                    />
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </Scroll>
                    </TabsContent>

                    <TabsContent value="history" className="flex min-h-0 flex-col gap-2">
                        <p className="text-sm text-muted-foreground">
                            Every earlier entry of each family that has an entry now - rank among scored entries (failed review counted apart). Owners not traced yet may hide older hotkeys.
                        </p>
                        <Scroll>
                            <Table className="text-sm">
                                <Head2>
                                    <SortHead id="family" label="family" {...by(hi)} />
                                    <SortHead id="entries" label="now" {...by(hi)} className="text-right" />
                                    {ROUND_IDS.map((id) => (
                                        <SortHead key={id} id={`r${id}`} label={`round ${competitions.find((c) => c.id === id)?.round ?? '?'} (${id})`} {...by(hi)} />
                                    ))}
                                </Head2>
                                <TableBody>
                                    {hi.sorted.map((f) => (
                                        <TableRow key={f.key} className={cn('cursor-pointer align-top', f.is_ours && 'bg-indigo-500/10')} onClick={rowClick(f.key)} title={FAMILY_HINT}>
                                            <TableCell className="min-w-[230px]">
                                                <FamilyName name={f.name} label={f.label} coldkeys={f.owners.map((o) => o.coldkey)} />
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {f.entries}
                                                <div className="font-mono text-xs text-muted-foreground">
                                                    {famStage === 'full' && f.best_overall
                                                        ? `best overall ${f.best_overall.estimate ? '≈' : ''}${num(f.best_overall.score)}`
                                                        : `best stage 1 ${num(f.best_stage1)}`}
                                                </div>
                                            </TableCell>
                                            {ROUND_IDS.map((id) => {
                                                const e = f.earlier.find((x) => x.competition === id);

                                                return (
                                                    <TableCell key={id}>
                                                        {e ? (
                                                            <div>
                                                                <span className="font-medium">{e.entries}</span> {e.entries === 1 ? 'entry' : 'entries'}
                                                                {e.best_place ? (
                                                                    <span className="ml-1 font-mono">
                                                                        · best #{e.best_place}/{e.of}
                                                                    </span>
                                                                ) : null}
                                                                {e.failed_review > 0 && <div className="text-xs text-red-600">{e.failed_review} failed review</div>}
                                                                {e.best_overall !== null && <div className="font-mono text-xs text-muted-foreground">overall {num(e.best_overall)}</div>}
                                                            </div>
                                                        ) : (
                                                            <span className="text-muted-foreground">—</span>
                                                        )}
                                                    </TableCell>
                                                );
                                            })}
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </Scroll>
                    </TabsContent>

                    <TabsContent value="events" className="flex min-h-0 flex-col gap-2">
                        <Scroll>
                            <div className="p-3">
                                <EventsList events={events} competition={competition.id} known={known} />
                            </div>
                        </Scroll>
                    </TabsContent>
                </Tabs>
            </div>
        </>
    );
}

function TabTrigger({ value, label, icon: Icon, n }: { value: string; label: string; icon: LucideIcon; n?: number }) {
    return (
        <TabsTrigger value={value} className={TABS_TRIGGER}>
            <Icon className="size-4" />
            {label}
            {n !== undefined && <span className="rounded bg-muted-foreground/15 px-1.5 text-xs">{n}</span>}
        </TabsTrigger>
    );
}

/**
 * One table per tab, in a frame that scrolls both ways while the header row stays visible. The table's own overflow
 * wrapper is switched off: it would be the sticky header's scroll container, and the header would scroll away.
 */
function Scroll({ children }: { children: ReactNode }) {
    return <div className={cn('max-h-[calc(100svh-5rem)] overflow-auto [&>[data-slot=table-container]]:overflow-visible', TABLE_FRAME)}>{children}</div>;
}

function Head2({ children }: { children: ReactNode }) {
    return (
        <TableHeader className="sticky top-0 z-10 bg-muted shadow-[0_1px_0_var(--border)]">
            <TableRow className="hover:bg-muted">{children}</TableRow>
        </TableHeader>
    );
}

function HotkeyCell({ m, href }: { m: MinerRow; href: ReturnType<typeof minerRoute> }) {
    const competition = Number(href.url.split('/')[2]) || 0;

    return (
        <div className="flex items-center gap-1.5">
            <a href={somaUrl(competition, m.hotkey)} target="_blank" rel="noopener noreferrer" className="font-mono hover:underline" title="open on thesoma.ai">
                {shortKey(m.hotkey, 10)}
            </a>
            <Link href={href} className="rounded p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground" title="our analysis of this entry">
                <ChartColumn className="size-3.5" />
            </Link>
            {m.is_ours && <Badge className="border-transparent bg-indigo-600 text-white">{m.our_label}</Badge>}
            {m.uid !== null && <span className="text-xs text-muted-foreground">uid {m.uid}</span>}
        </div>
    );
}

/** Rank in the current phase: the final place once scored, the stage-2 rank while stage 2 and the full evaluation run, else the stage-1 rank. */
function phaseRank(m: MinerRow, phase: Phase): number | null {
    return (phase === 'scored' ? m.place : phase === 'stage2' || phase === 'full' ? m.stage2_rank : m.rank) ?? null;
}

const FAMILY_HINT = "click the row to see this family's entries";

type EntriesProps = {
    rows: MinerRow[];
    sort: { sort: SortState; onSort: (key: string) => void };
    /** first column: our entry label (the Ours tab) or the entry's rank (a family) */
    first: 'entry' | 'rank';
    empty: string;
    phase: Phase;
    problems: string[];
    stage2Tasks: TaskRef[];
    stage2Count: number;
    entries: number;
    href: (hotkey: string) => ReturnType<typeof minerRoute>;
};

/** A set of entries in one table (ours, or one family's): bands, stage 2 and its tasks, stage 1 and its problems, fingerprint, upload time. */
function EntriesTable({ rows, sort, first, empty, phase, problems, stage2Tasks, stage2Count, entries, href }: EntriesProps) {
    const full = phase === 'full';
    const evalPhase = phase === 'stage2' || phase === 'full';
    const span = 13 + (full ? 1 : 0) + stage2Tasks.length + problems.length;

    return (
        <Table className="text-sm">
            <Head2>
                {first === 'entry' ? <SortHead id="entry" label="entry" {...sort} /> : <SortHead id="now" label="#" {...sort} className="w-12" />}
                <SortHead id="hotkey" label="hotkey" {...sort} />
                <SortHead id="status" label="status" {...sort} />
                <SortHead id="overall" label="overall" {...sort} className="text-right" />
                <SortHead id="short" label="short" {...sort} className="text-right" />
                <SortHead id="medium" label="medium" {...sort} className="text-right" />
                <SortHead id="long" label="long" {...sort} className="text-right" />
                <SortHead id="stage2" label="stage 2" {...sort} className="text-right" />
                <SortHead id="stage2rank" label="stage-2 rank" {...sort} className="text-right" />
                {full && <SortHead id="progress" label="full-eval runs" {...sort} />}
                {stage2Tasks.map((t) => (
                    <SortHead key={t.name} id={`s2:${t.name}`} label={<TaskHead t={t} />} {...sort} className="text-center" />
                ))}
                <SortHead id="stage1" label="stage 1" {...sort} className="text-right" />
                <SortHead id="s1rank" label="stage-1 rank" {...sort} className="text-right" />
                {problems.map((p) => (
                    <SortHead key={p} id={`p:${p}`} label={PROBLEM_SHORT[p] ?? p} {...sort} className="text-center" />
                ))}
                <SortHead id="steps" label="fingerprint" {...sort} />
                <SortHead id="uploaded" label="uploaded (UTC)" {...sort} />
            </Head2>
            <TableBody>
                {rows.length === 0 && (
                    <TableRow>
                        <TableCell colSpan={span} className="text-muted-foreground">
                            {empty}
                        </TableCell>
                    </TableRow>
                )}
                {rows.map((m) => (
                    <TableRow key={m.id} className={cn('h-14', first === 'rank' && m.is_ours && 'bg-indigo-500/10 hover:bg-indigo-500/15')}>
                        <TableCell>
                            {first === 'entry' ? (
                                <Badge className="border-transparent bg-indigo-600 text-white">{m.our_label}</Badge>
                            ) : (
                                <span className="text-muted-foreground">{phaseRank(m, phase) ?? '—'}</span>
                            )}
                        </TableCell>
                        <TableCell>
                            {first === 'entry' ? (
                                <>
                                    <HotkeyCell m={{ ...m, is_ours: false, uid: null }} href={href(m.hotkey)} />
                                    <span className="ml-1 text-muted-foreground">uid {m.uid ?? '?'}</span>
                                </>
                            ) : (
                                <HotkeyCell m={m} href={href(m.hotkey)} />
                            )}
                        </TableCell>
                        <TableCell>
                            <StatusBadge status={m.status} />
                        </TableCell>
                        <TableCell className="text-right font-mono font-medium">
                            <OverallCell m={m} running={full} />
                        </TableCell>
                        {(['short', 'medium', 'long'] as const).map((c) => (
                            <TableCell key={c} className="text-right font-mono">
                                <ScoreCell value={m.categories[c]} live={m.live} band={c} />
                            </TableCell>
                        ))}
                        <TableCell className="text-right font-mono">
                            <Stage2Score m={m} />
                        </TableCell>
                        <TableCell className="text-right font-mono">
                            {m.stage2_rank ? `#${m.stage2_rank}/${stage2Count}` : '—'}
                            {evalPhase && m.advanced && <Flag className="ml-1 inline size-3 text-emerald-600 dark:text-emerald-400" />}
                        </TableCell>
                        {full && (
                            <TableCell>
                                <ProgressCell p={m.full_progress} />
                            </TableCell>
                        )}
                        {stage2Tasks.map((t) => (
                            <TableCell key={t.name} className="w-28 px-1.5">
                                <ProblemCell cell={m.stage2?.[t.name]} />
                            </TableCell>
                        ))}
                        <TableCell className="text-right font-mono">
                            <Stage1Score m={m} />
                        </TableCell>
                        <TableCell className="text-right font-mono text-muted-foreground">{m.rank ? `#${m.rank}/${entries}` : '—'}</TableCell>
                        {problems.map((p) => (
                            <TableCell key={p} className="w-28 px-1.5">
                                <ProblemCell cell={m.stage1?.[p]} />
                            </TableCell>
                        ))}
                        <TableCell>
                            <FingerprintBadge fp={m.fingerprint} />
                        </TableCell>
                        <TableCell className="font-mono text-muted-foreground">{utc(m.last_submit_at)}</TableCell>
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}

/** The opened family over its table: owners, entries by status, its best entry now, earlier rounds, earnings and link evidence. */
function FamilyHeader(props: {
    name: string;
    info: FamilySummary | null;
    earning: EarningRow | null;
    rows: MinerRow[];
    ranked: number;
    entries: number;
    scoreOf: (m: MinerRow) => number | null;
    rankOf: (m: MinerRow) => number | null | undefined;
    onClose: () => void;
}) {
    const { name, info, earning, rows, ranked, scoreOf, rankOf, onClose } = props;
    const coldkeys = info?.owners.map((o) => o.coldkey) ?? earning?.coldkeys ?? [...new Set(rows.map((m) => m.coldkey).filter((c): c is string => !!c))];
    const label = info?.label ?? earning?.label ?? null;
    const statuses = info?.statuses ?? rows.reduce<Record<string, number>>((a, m) => ({ ...a, [m.status ?? 'unknown']: (a[m.status ?? 'unknown'] ?? 0) + 1 }), {});
    const best = [...rows].filter((m) => scoreOf(m) !== null).sort((a, b) => (scoreOf(b) ?? 0) - (scoreOf(a) ?? 0))[0];
    const untraced = info?.owners.filter((o) => !o.profiled).length ?? 0;

    return (
        <div className="flex flex-wrap items-start gap-x-8 gap-y-3 rounded-xl border bg-card p-4 text-sm shadow-sm">
            <div className="min-w-[220px]">
                <div className="flex items-center gap-2">
                    <span className="text-base font-semibold">{name}</span>
                    {info?.is_ours && <Badge className="border-transparent bg-indigo-600 text-white">ours</Badge>}
                    <button type="button" onClick={onClose} aria-label="close this family" title="close this family" className="rounded p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground">
                        <X className="size-4" />
                    </button>
                </div>
                {label && label !== name && label !== 'ours' && <div className="text-xs text-muted-foreground">{label}</div>}
                <div className="mt-1 flex flex-col">
                    {coldkeys.map((c) => (
                        <KeyLabel key={c} value={c} className="text-muted-foreground" />
                    ))}
                </div>
                {untraced > 0 && <div className="text-xs text-amber-600">{untraced} owner(s) not traced yet</div>}
            </div>
            <Fact title="entries here">
                <span className="text-lg font-semibold tabular-nums">{rows.length}</span>
                <div className="text-xs text-muted-foreground">
                    <StatusCounts statuses={statuses} />
                </div>
            </Fact>
            <Fact title="best entry now">
                {best ? (
                    <>
                        <span className="font-mono text-lg font-semibold tabular-nums">{num(scoreOf(best))}</span>
                        <span className="ml-2 text-muted-foreground tabular-nums">
                            #{rankOf(best) ?? '—'}/{ranked}
                        </span>
                        <div className="font-mono text-xs text-muted-foreground">{shortKey(best.hotkey, 10)}</div>
                    </>
                ) : (
                    <span className="text-muted-foreground">—</span>
                )}
            </Fact>
            <Fact title="earlier rounds">
                <div className="flex max-w-[360px] flex-wrap gap-1">
                    {(info?.earlier ?? []).length === 0 && <span className="text-muted-foreground">—</span>}
                    {(info?.earlier ?? []).map((e) => (
                        <Badge key={e.competition} variant="outline" className={cn('font-normal', e.failed_review > 0 && 'border-red-500')}>
                            R{e.round ?? '?'} ×{e.entries}
                            {e.best_place ? ` · best #${e.best_place}/${e.of}` : ''}
                            {e.failed_review > 0 && <span className="text-red-600"> · {e.failed_review} failed review</span>}
                        </Badge>
                    ))}
                </div>
            </Fact>
            <Fact title="SN114 earned">
                {earning ? (
                    <>
                        <span className="font-mono">{tao(earning.sn114)}</span>
                        <div className="text-xs text-muted-foreground">
                            last 30 days {tao(earning.last30)} · now {tao(earning.now, 3)}/day
                        </div>
                    </>
                ) : (
                    <span className="text-muted-foreground">—</span>
                )}
            </Fact>
            {info && (
                <Fact title="links">
                    <div className="text-xs">
                        {info.owners.length} owner{info.owners.length === 1 ? '' : 's'} · {info.direct_links.length} direct transfers · {info.shared_wallets.length} shared personal wallets
                    </div>
                    <div className="text-xs text-muted-foreground">{Object.keys(info.subnets).length} other subnets</div>
                </Fact>
            )}
        </div>
    );
}

function Fact({ title, children }: { title: string; children: ReactNode }) {
    return (
        <div>
            <div className="mb-0.5 text-xs font-medium tracking-wide text-muted-foreground uppercase">{title}</div>
            {children}
        </div>
    );
}

const STATUS_ORDER = ['scored', 'failed review', 'evaluating', 'qualified', 'screening', 'not qualified'];
/** Statuses of entries that passed stage 1 (qualified, and every later stage). */
const PASSED_STAGE1 = ['qualified', 'evaluating', 'scored', 'failed review'];
/** The two-band elements of the payout (the three-band one is the overall); `id` is the sort key in the URL, no "+". */
const PAIRS = [
    { id: 'sm', key: 'short+medium', label: 'S+M' },
    { id: 'sl', key: 'short+long', label: 'S+L' },
    { id: 'ml', key: 'medium+long', label: 'M+L' },
] as const;

/** A family's entries by status, the ones that reached the full evaluation first; failed review in red. */
function StatusCounts({ statuses }: { statuses: Record<string, number> }) {
    const order = (s: string) => (STATUS_ORDER.includes(s) ? STATUS_ORDER.indexOf(s) : STATUS_ORDER.length);
    const list = Object.entries(statuses).sort((a, b) => order(a[0]) - order(b[0]));

    return (
        <>
            {list.map(([s, n], i) => (
                <Fragment key={s}>
                    {i > 0 && ' · '}
                    <span className={cn(s === 'failed review' && 'font-medium text-red-600 dark:text-red-400')}>
                        {n} {s}
                    </span>
                </Fragment>
            ))}
        </>
    );
}

/** A family's best full-evaluation value with the entry that holds it and its platform place (≈ = running estimate). */
function BestCell({ best, tone }: { best: FamilySummary['best_overall']; tone?: string }) {
    if (!best) {
        return <span className="text-muted-foreground">—</span>;
    }

    return (
        <>
            <span className={tone}>
                {best.estimate && '≈'}
                {num(best.score)}
            </span>
            <div className="text-xs text-muted-foreground">
                {shortKey(best.hotkey)}
                {!best.estimate && best.place !== null && ` · #${best.place}`}
            </div>
        </>
    );
}

/** Family name on top with its entries here and how many of them passed stage 1, the owner's coldkey under it. */
function FamilyCell({ m, size, qualified }: { m: MinerRow; size: number; qualified: number }) {
    if (!m.coldkey) {
        return <span className="text-muted-foreground">owner unknown</span>;
    }

    return (
        <div className="leading-tight">
            <div className="flex items-center gap-1.5">
                <span className="font-medium">{m.family_name ?? 'unnamed'}</span>
                {size > 1 && (
                    <span className="text-xs text-muted-foreground">
                        ×{size} here · <span className={qualified > 0 ? 'text-emerald-600' : undefined}>{qualified} qualified</span>
                    </span>
                )}
            </div>
            <KeyLabel value={m.coldkey} className="text-muted-foreground" />
        </div>
    );
}

function FamilyName({ name, label, coldkeys, untraced = 0 }: { name: string; label: string | null; coldkeys: string[]; untraced?: number }) {
    return (
        <div className="leading-tight">
            <div className="font-medium">{name}</div>
            {label && label !== name && label !== 'ours' && <div className="text-xs text-muted-foreground">{label}</div>}
            <div className="mt-1 flex flex-col">
                {coldkeys.map((c) => (
                    <KeyLabel key={c} value={c} className="text-muted-foreground" />
                ))}
            </div>
            {untraced > 0 && <div className="text-xs text-amber-600">{untraced} owner(s) not traced yet</div>}
        </div>
    );
}

Overview.layout = {
    breadcrumbs: [
        { title: 'SOMA', href: homeRoute() },
        { title: 'Leaderboard', href: overviewRoute() },
    ],
};

/** An entry that is still running: its stage-1 score is the platform's partial value. */
function isLive(m: MinerRow): boolean {
    return m.status === 'screening' || m.status === 'in queue';
}

function Stage1Score({ m }: { m: MinerRow }) {
    if (!isLive(m)) {
        return <>{num(m.stage1_score)}</>;
    }

    return (
        <span className="inline-flex items-center gap-1.5 text-muted-foreground" title="still running - partial score">
            {num(m.stage1_score)}
            <span className="rounded bg-sky-500/15 px-1 py-px font-sans text-[10px] font-medium text-sky-700 uppercase dark:text-sky-300">live</span>
        </span>
    );
}

/** Stage 2 (the platform's screener 2): the score once it is out, otherwise the verdict ("running" while it runs). */
function Stage2Score({ m }: { m: MinerRow }) {
    const st = entryState(m);
    if ((st === 'scoring' || st === 'running') && m.stage2_score !== null) {
        return (
            <span className="inline-flex items-center gap-1.5 text-amber-700 dark:text-amber-300" title={PARTIAL_TIP}>
                {num(m.stage2_score)}
                <span className="rounded bg-amber-500/15 px-1 py-px font-sans text-[10px] font-medium uppercase">
                    {st} {Math.round(100 * stage2Progress(m))}%
                </span>
            </span>
        );
    }
    if (m.stage2_score !== null || !m.stage2_verdict || m.stage2_verdict === 'none') {
        return <>{num(m.stage2_score)}</>;
    }

    return (
        <span
            className={cn(
                'rounded px-1 py-px font-sans text-[10px] font-medium uppercase',
                m.stage2_verdict === 'running' ? 'bg-violet-500/15 text-violet-700 dark:text-violet-300' : 'bg-muted text-muted-foreground',
            )}
        >
            {m.stage2_verdict}
        </span>
    );
}

function progressShare(p: FullProgress | null | undefined): number | null {
    return p && p.planned > 0 ? p.run / p.planned : null;
}

function pct(p: FullProgress): string {
    return `${Math.round((progressShare(p) ?? 0) * 100)}%`;
}

function bandCounts(tasks: TaskRef[]): string {
    return (['short', 'medium', 'long'] as const).map((b) => `${tasks.filter((t) => t.band === b).length} ${b}`).join(' · ');
}

/** Whether the advance line falls just above row i (default order only): after the last advanced entry once the selection is
 *  out, else after the size-th stage-2 rank. */
function cutBefore(rows: MinerRow[], i: number, phase: Phase, cut: CutInfo): boolean {
    // no line when there is no selection (size 0: from competition 151 every stage-1 passer is evaluated)
    if (i === 0 || (phase !== 'stage2' && phase !== 'full') || (cut.size <= 0 && cut.advanced <= 0)) {
        return false;
    }
    if (cut.advanced > 0) {
        return !!rows[i - 1].advanced && !rows[i].advanced;
    }

    return (rows[i - 1].stage2_rank ?? 0) === cut.size && (rows[i].stage2_rank ?? Infinity) > cut.size;
}

function CutRow({ span, cut, phase }: { span: number; cut: CutInfo; phase: Phase }) {
    return (
        <TableRow className="hover:bg-transparent">
            <TableCell colSpan={span} className="py-1">
                <div className="flex items-center gap-2 text-xs font-medium text-emerald-700 dark:text-emerald-300">
                    <span className="h-px flex-1 bg-emerald-500/40" />
                    {cut.advanced > 0 ? `▲ ${cut.advanced} advanced to the full evaluation` : `▲ top ${cut.size} advance${phase === 'stage2' ? ' (if stage 2 ended now)' : ''}`}
                    {cut.line !== null && ` · stage-2 line ${num(cut.line)}`}
                    <span className="h-px flex-1 bg-emerald-500/40" />
                </div>
            </TableCell>
        </TableRow>
    );
}

/** Column head for one task: its short name over its band. */
function TaskHead({ t }: { t: TaskRef }) {
    return (
        <span className="inline-flex flex-col items-center leading-tight">
            <span>{shortTask(t.name)}</span>
            <span className={cn('text-[10px] font-normal', BAND_TONE[t.band ?? ''] ?? 'text-muted-foreground')}>{t.band ?? '—'}</span>
        </span>
    );
}

/** Full-evaluation runs of one entry: recorded / planned with a bar, and graded passes. */
function ProgressCell({ p }: { p: FullProgress | null | undefined }) {
    if (!p || p.planned === 0) {
        return <span className="text-muted-foreground">—</span>;
    }
    const share = progressShare(p) ?? 0;

    return (
        <div className="w-28">
            <div className="flex justify-between gap-2 font-mono text-xs">
                <span>
                    {p.run}/{p.planned}
                </span>
                <span className="text-muted-foreground">{p.graded > 0 ? `${p.passed}/${p.graded} ✓` : ''}</span>
            </div>
            <div className="mt-0.5 h-1.5 rounded bg-muted">
                <div className="h-1.5 rounded bg-emerald-500" style={{ width: `${Math.max(2, Math.round(share * 100))}%` }} />
            </div>
        </div>
    );
}

/** The overall, or a "running" tag while the full evaluation runs (the platform's value is partial then). */
function OverallCell({ m, running }: { m: MinerRow; running: boolean }) {
    if (m.overall === null && running && m.advanced) {
        return m.live?.overall != null ? (
            <ScoreCell value={null} live={m.live} band="overall" />
        ) : (
            <span className="rounded bg-amber-500/15 px-1 py-px font-sans text-[10px] font-medium text-amber-700 uppercase dark:text-amber-300">running</span>
        );
    }

    return <>{num(m.overall)}</>;
}

/** The platform's score when it has one, else the running estimate (marked live, with how many tasks it covers). */
function ScoreCell({ value, live, band, digits = 3 }: { value: number | null; live: LiveScore | null | undefined; band: 'overall' | 'short' | 'medium' | 'long'; digits?: number }) {
    if (value !== null) {
        return <>{num(value, digits)}</>;
    }
    const v = live?.[band] ?? null;
    if (v === null || !live) {
        return <span className="text-muted-foreground">—</span>;
    }
    const tasks = band === 'overall' ? live.tasks : live[`${band}_tasks`];
    const full = band === 'overall' ? 30 : 10;

    return (
        <span className="inline-flex items-center justify-end gap-1" title={`running estimate over ${tasks} of ${full} tasks (${live.done} of ${live.tasks} finished)`}>
            <span className={cn(tasks < full && 'text-muted-foreground')}>{num(v, digits)}</span>
            <span className="rounded bg-sky-500/15 px-0.5 font-sans text-[9px] font-medium text-sky-700 uppercase dark:text-sky-300">{tasks < full ? `${tasks}/${full}` : 'live'}</span>
        </span>
    );
}

const STAT_TONE: Record<string, KpiTone> = { Entries: 'violet', 'Our entries': 'indigo', Timing: 'amber' };

function StatCard({ title, icon, children }: { title: string; icon: LucideIcon; children: ReactNode }) {
    return (
        <Kpi icon={icon} title={title} tone={STAT_TONE[title] ?? 'sky'}>
            {children}
        </Kpi>
    );
}

/** Earlier rounds of an entry in one line (rows keep one height): the newest three, the rest behind "+N" with all in the tooltip. */
function EarlierBadges({ history }: { history: NonNullable<MinerRow['history']> }) {
    if (history.length === 0) {
        return <span className="text-muted-foreground">—</span>;
    }
    const text = (h: (typeof history)[number]) => `R${h.round ?? '?'} ${h.status === 'scored' ? `#${h.place}/${h.of}` : h.status}`;

    return (
        <div className="flex flex-nowrap items-center gap-1 whitespace-nowrap" title={history.map(text).join(' · ')}>
            {history.slice(0, 3).map((h) => (
                <Badge key={h.competition} variant="outline" className={cn('font-normal', h.status === 'failed review' && 'border-red-500 text-red-600')}>
                    {text(h)}
                </Badge>
            ))}
            {history.length > 3 && <span className="text-xs text-muted-foreground">+{history.length - 3}</span>}
        </div>
    );
}
