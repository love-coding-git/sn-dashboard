import { Head, Link, usePoll } from '@inertiajs/react';
import { ChevronRight, Coins, ExternalLink, Fingerprint as FingerprintIcon, Flag, Gauge, ListChecks, ShieldCheck, UserRound, Users } from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { EventsList } from '@/components/soma/events-list';
import { HeroChip, HeroStat, Kpi, PageHero, PAGE, scoreTone, signed, TABLE_FRAME, TABS_LIST, TABS_TRIGGER } from '@/components/soma/kit';
import { FingerprintBadge } from '@/components/soma/fingerprint-badge';
import { KeyLabel } from '@/components/soma/key-label';
import { ProblemCell } from '@/components/soma/problem-cell';
import { SortButton, SortHead } from '@/components/soma/sort-head';
import { StatusBadge } from '@/components/soma/status-badge';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import type { SortColumn, SortState } from '@/hooks/use-sort';
import { useSort } from '@/hooks/use-sort';
import { useTab } from '@/hooks/use-tab';
import { ago, num, PROBLEM_SHORT, shortKey, times, utc } from '@/lib/soma';
import { somaUrl } from '@/lib/soma-state';
import { cn } from '@/lib/utils';
import { home as homeRoute, miner as minerRoute, overview as overviewRoute } from '@/routes/soma';
import type { CompetitionInfo, DirectLink, Fingerprint, HistoryRow, MinerRow, SharedWallet, TaskCell, WatchEvent } from '@/types';

type Run = { seq: number; passed: boolean | null; steps: number | null; input: number | null; cached: number | null; output: number | null; jev: number | null; weighted: number | null; seconds: number | null };
type Task = TaskCell & { reference: { passed: number | null; steps: number | null; ratio: number | null; score: number | null } | null; runs: Run[] };
type Sibling = { hotkey: string; status: string | null; stage1: number | null; uid: number | null; fingerprint: Fingerprint | null; is_ours: boolean; our_label: string | null };
type Family = {
    key: string;
    name: string;
    label: string | null;
    owners: { coldkey: string; created_on: string | null; balance_tao: number | null; profiled_at: string | null; sn114_hotkeys: number }[];
    siblings: Sibling[];
    direct_links: DirectLink[];
    shared_wallets: SharedWallet[];
};
type Owner = {
    coldkey: string;
    family: string;
    label: string | null;
    created_on: string | null;
    balance_tao: number | null;
    profiled_at: string | null;
    registrations: { netuid: number; uid: number | null; hotkey: string; at: string | null; cost_tao: number | null }[];
    transfers: { at: string | null; direction: 'in' | 'out'; tao: number; other: string; other_is_owner: boolean; other_kind: string | null; other_transfers: number | null }[];
};

type Props = {
    competition: CompetitionInfo;
    problems: string[];
    miner: MinerRow & { stage2_verdict: string | null; tasks_synced_at: string | null; of: number };
    tasks: Task[];
    reference: { hotkey: string } | null;
    family: Family | null;
    owner: Owner | null;
    history: HistoryRow[];
    events: WatchEvent[];
};

const TABS = ['stage1', 'soma', 'history', 'family', 'registrations', 'money', 'events'];

// Sortable columns (click a header).
const TASK_COLUMNS: Record<string, SortColumn<Task>> = {
    task: { value: (t) => PROBLEM_SHORT[t.name] ?? t.name },
    size: { value: (t) => t.complexity ?? (t.is_screener ? 'stage 1' : null) },
    passed: { value: (t) => (t.passed !== null && t.finished ? t.passed / t.finished : null), first: 'desc' },
    baseline: { value: (t) => (t.baseline_passed !== null && t.baseline_total ? t.baseline_passed / t.baseline_total : null), first: 'desc' },
    score: { value: (t) => t.score, first: 'desc' },
    ratio: { value: (t) => t.ratio },
    steps: { value: (t) => t.steps },
    reference: { value: (t) => t.reference?.score, first: 'desc' },
};
const HISTORY_COLUMNS: Record<string, SortColumn<HistoryRow>> = {
    round: { value: (h) => h.round ?? h.competition, first: 'desc' },
    hotkey: { value: (h) => h.hotkey },
    status: { value: (h) => h.status },
    rank: { value: (h) => (h.place && h.of ? h.place / h.of : null) },
    overall: { value: (h) => h.overall, first: 'desc' },
    short: { value: (h) => h.categories.short, first: 'desc' },
    medium: { value: (h) => h.categories.medium, first: 'desc' },
    long: { value: (h) => h.categories.long, first: 'desc' },
    stage1: { value: (h) => h.stage1, first: 'desc' },
    steps: { value: (h) => h.fingerprint?.u_steps_x ?? h.fingerprint?.steps_x },
};
type Registration = Owner['registrations'][number];
const REGISTRATION_COLUMNS: Record<string, SortColumn<Registration>> = {
    at: { value: (r) => r.at, first: 'desc' },
    netuid: { value: (r) => r.netuid },
    uid: { value: (r) => r.uid },
    hotkey: { value: (r) => r.hotkey },
    cost: { value: (r) => r.cost_tao, first: 'desc' },
};
type Transfer = Owner['transfers'][number];
const TRANSFER_COLUMNS: Record<string, SortColumn<Transfer>> = {
    at: { value: (t) => t.at, first: 'desc' },
    direction: { value: (t) => t.direction },
    tao: { value: (t) => t.tao, first: 'desc' },
    other: { value: (t) => t.other },
    kind: { value: (t) => t.other_kind },
};
const NO_REGISTRATIONS: Registration[] = [];
const NO_TRANSFERS: Transfer[] = [];
const k = (v: number | null) => (v === null ? '—' : v >= 1e6 ? `${(v / 1e6).toFixed(2)}M` : v >= 1e3 ? `${(v / 1e3).toFixed(1)}k` : String(v));

export default function Miner({ competition, problems, miner, tasks, reference, family, owner, history, events }: Props) {
    usePoll(60_000, { only: ['miner', 'tasks', 'events'] });

    const [tab, setTab] = useTab('stage1', TABS);
    const stage1 = tasks.filter((t) => t.is_screener && problems.includes(t.name));
    const soma = tasks.filter((t) => !(t.is_screener && problems.includes(t.name)));
    const now = Date.now();
    // stage-2 (SOMA) tasks: share of planned runs finished, and passes against the baseline's on the finished runs
    const running = miner.status === 'screening';
    const s2 = soma.reduce(
        (a, t) => {
            const done = t.finished ?? 0;
            const base = done > 0 && t.baseline_passed !== null && t.baseline_total ? (t.baseline_passed / t.baseline_total) * done : 0;

            return { finished: a.finished + done, total: a.total + (t.total ?? t.baseline_total ?? 0), passed: a.passed + (done > 0 ? (t.passed ?? 0) : 0), base: a.base + base, tasks: a.tasks + (done > 0 ? 1 : 0), progress: 0 };
        },
        { finished: 0, total: 0, passed: 0, base: 0, tasks: 0, progress: 0 },
    );
    s2.progress = s2.total > 0 ? s2.finished / s2.total : 0;
    const hs = useSort('hist', history, HISTORY_COLUMNS);
    const rs = useSort('reg', owner?.registrations ?? NO_REGISTRATIONS, REGISTRATION_COLUMNS);
    const ts = useSort('money', owner?.transfers ?? NO_TRANSFERS, TRANSFER_COLUMNS);
    const by = (x: { sort: SortState; toggle: (key: string) => void }) => ({ sort: x.sort, onSort: x.toggle });

    return (
        <>
            <Head title={shortKey(miner.hotkey)} />
            <div className={PAGE}>
                <PageHero
                    tone="violet"
                    eyebrow={`entry · competition ${competition.id} · round ${competition.round ?? '?'}`}
                    title={
                        <span className="flex items-center gap-3">
                            {miner.is_ours && <span className="rounded-lg bg-white/20 px-2.5 py-0.5 text-2xl md:text-3xl">{miner.our_label}</span>}
                            <span className="font-mono">{shortKey(miner.hotkey, 12)}</span>
                        </span>
                    }
                    subtitle={<span className="font-mono text-xs break-all text-white/75">{miner.hotkey}</span>}
                    right={
                        <HeroStat
                            label="stage 2"
                            value={running ? `${Math.round(100 * s2.progress)}%` : signed(miner.stage2_score)}
                            note={running ? 'running - runs finished' : (miner.status ?? '')}
                        />
                    }
                >
                    <HeroChip>{miner.status ?? 'no status'}</HeroChip>
                    {miner.uid !== null && <HeroChip>uid {miner.uid}</HeroChip>}
                    {family && <HeroChip>family {family.name}</HeroChip>}
                    <HeroChip>
                        uploaded {utc(miner.last_submit_at, true)} UTC ({ago(miner.last_submit_at, now)})
                    </HeroChip>
                    <HeroChip>page read {ago(miner.tasks_synced_at, now)}</HeroChip>
                    <a href={somaUrl(competition.id, miner.hotkey)} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1.5 rounded-full bg-white px-3 py-1 font-medium text-violet-700 shadow-sm hover:bg-white/90">
                        open on thesoma.ai <ExternalLink className="size-3" />
                    </a>
                </PageHero>

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Kpi icon={Flag} title="Stage 1" tone="indigo">
                        <div className={cn('text-3xl font-semibold tabular-nums', scoreTone(miner.stage1_score))}>{signed(miner.stage1_score)}</div>
                        <p className="mt-1 text-sm text-muted-foreground">{miner.rank ? `#${miner.rank} of ${miner.of}` : '—'} · five public tasks</p>
                    </Kpi>
                    <Kpi icon={Gauge} title="Stage 2" tone="sky">
                        {running ? (
                            <>
                                <div className="text-3xl font-semibold tabular-nums">{Math.round(100 * s2.progress)}%</div>
                                <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-muted">
                                    <div className="h-full rounded-full bg-sky-500" style={{ width: `${100 * s2.progress}%` }} />
                                </div>
                                <p className="mt-1 text-sm text-muted-foreground">runs finished; its partial score is not a result</p>
                            </>
                        ) : (
                            <>
                                <div className={cn('text-3xl font-semibold tabular-nums', scoreTone(miner.stage2_score))}>{signed(miner.stage2_score)}</div>
                                <p className="mt-1 text-sm text-muted-foreground">{miner.stage2_score === null ? 'not started' : `${s2.tasks} tasks`}</p>
                            </>
                        )}
                    </Kpi>
                    <Kpi icon={Coins} title="Tokens vs untouched" tone="violet">
                        <div className="text-3xl font-semibold tabular-nums">{times(miner.fingerprint?.u_tokens_x ?? null, 3)}</div>
                        <p className="mt-1 text-sm text-muted-foreground">
                            steps {times(miner.fingerprint?.u_steps_x ?? null, 2)} · output {times(miner.fingerprint?.u_output_x ?? null, 2)}
                        </p>
                    </Kpi>
                    <Kpi icon={ShieldCheck} title="Quality" tone="emerald">
                        <div className="text-3xl font-semibold tabular-nums">{s2.base > 0 ? `${((100 * s2.passed) / s2.base).toFixed(1)}%` : '—'}</div>
                        <p className="mt-1 text-sm text-muted-foreground">{s2.base > 0 ? `${s2.passed} passes on finished stage-2 runs vs the baseline` : 'appears once stage-2 runs finish'}</p>
                    </Kpi>
                </div>

                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <Kpi icon={ListChecks} title="Scores" tone="sky">
                        <div className="grid grid-cols-2 gap-x-3 gap-y-1 text-sm">
                            <span className="text-muted-foreground">stage 1</span>
                            <span className="font-mono">
                                {num(miner.stage1_score)} {miner.stage1_verdict && <span className="text-xs text-muted-foreground">({miner.stage1_verdict})</span>}
                            </span>
                            <span className="text-muted-foreground">stage 2</span>
                            <span className="font-mono">
                                {num(miner.stage2_score)} {miner.stage2_verdict && <span className="text-xs text-muted-foreground">({miner.stage2_verdict})</span>}
                            </span>
                            <span className="text-muted-foreground">overall</span>
                            <span className="font-mono">{num(miner.overall)}</span>
                            <span className="text-muted-foreground">short · medium · long</span>
                            <span className="font-mono">
                                {num(miner.categories.short, 2)} · {num(miner.categories.medium, 2)} · {num(miner.categories.long, 2)}
                            </span>
                            {miner.overall !== null ? (
                                <>
                                    <span className="text-muted-foreground">place</span>
                                    <span className="font-mono">{miner.place ?? '—'}</span>
                                </>
                            ) : (
                                <>
                                    <span className="text-muted-foreground">stage-1 rank</span>
                                    <span className="font-mono">{miner.rank ? `#${miner.rank} of ${miner.of}` : '—'}</span>
                                </>
                            )}
                        </div>
                    </Kpi>
                    <Kpi icon={FingerprintIcon} title="Fingerprint vs untouched" tone="violet">
                        <div className="space-y-2 text-sm">
                            <FingerprintBadge fp={miner.fingerprint} />
                            {miner.fingerprint ? (
                                <div className="grid grid-cols-2 gap-x-3 font-mono text-xs">
                                    <span className="text-muted-foreground">tokens (weighted)</span>
                                    <span>{times(miner.fingerprint.u_tokens_x, 3)}</span>
                                    <span className="text-muted-foreground">output · cached</span>
                                    <span>
                                        {times(miner.fingerprint.u_output_x, 3)} · {times(miner.fingerprint.u_cached_x, 3)}
                                    </span>
                                    <span className="text-muted-foreground">uncached input</span>
                                    <span>{times(miner.fingerprint.u_uncached_x, 3)}</span>
                                    <span className="text-muted-foreground">steps ≈ · short ≈</span>
                                    <span>
                                        {times(miner.fingerprint.u_steps_x, 3)} · {times(miner.fingerprint.u_short_x, 3)}
                                    </span>
                                    <span className="text-muted-foreground">re-bill slope</span>
                                    <span>{miner.fingerprint.rebill_slope ?? '—'}</span>
                                    <span className="col-span-2 mt-1 font-sans text-[11px] text-muted-foreground">
                                        class and output/step · context@15 vs {reference ? shortKey(reference.hotkey) : 'the reference'}: hover the badge
                                    </span>
                                </div>
                            ) : (
                                <p className="text-xs text-muted-foreground">appears when all five stage-1 problems have finished</p>
                            )}
                        </div>
                    </Kpi>
                    <Kpi icon={UserRound} title="Owner" tone="amber">
                        <div className="grid grid-cols-2 gap-x-3 gap-y-1 text-sm">
                            <span className="text-muted-foreground">family</span>
                            <span className="font-medium">{family?.name ?? miner.family_name ?? '—'}</span>
                            <span className="text-muted-foreground">coldkey</span>
                            <KeyLabel value={miner.coldkey} />
                            <span className="text-muted-foreground">note</span>
                            <span>{owner?.label && owner.label !== 'ours' ? owner.label : (miner.owner_label ?? '—')}</span>
                            <span className="text-muted-foreground">created</span>
                            <span>{owner?.created_on ?? '—'}</span>
                            <span className="text-muted-foreground">balance</span>
                            <span className="font-mono">{owner?.balance_tao !== null && owner?.balance_tao !== undefined ? `τ${owner.balance_tao.toFixed(3)}` : '—'}</span>
                            <span className="text-muted-foreground">traced</span>
                            <span>{owner?.profiled_at ? ago(owner.profiled_at, now) : 'not yet (queued)'}</span>
                        </div>
                    </Kpi>
                    <Kpi icon={Users} title={<>Family · {family?.name ?? '—'}</>} tone="emerald">
                        <div className="space-y-1 text-sm">
                            {family?.label && family.label !== family.name && family.label !== 'ours' ? <div className="text-xs text-muted-foreground">{family.label}</div> : null}
                            {!family && <p className="text-muted-foreground">owner unknown</p>}
                            {family?.owners.map((o) => (
                                <div key={o.coldkey} className="flex items-center gap-2 text-xs">
                                    <KeyLabel value={o.coldkey} />
                                    <span className="text-muted-foreground">
                                        {o.sn114_hotkeys} SN114 hotkeys{o.created_on ? ` · since ${o.created_on}` : ''}
                                    </span>
                                </div>
                            ))}
                            {family && family.siblings.length > 1 && (
                                <div className="pt-1 text-xs">
                                    <div className="text-muted-foreground">entries in this competition:</div>
                                    {family.siblings.map((s) => (
                                        <Link key={s.hotkey} href={minerRoute({ competition: competition.id, hotkey: s.hotkey })} className={cn('flex items-center gap-2 hover:underline', s.hotkey === miner.hotkey && 'font-semibold')}>
                                            <span className="font-mono">{shortKey(s.hotkey)}</span>
                                            {s.is_ours && <Badge className="border-transparent bg-indigo-600 text-white">{s.our_label}</Badge>}
                                            <StatusBadge status={s.status} />
                                            <span className="ml-auto font-mono">{num(s.stage1)}</span>
                                        </Link>
                                    ))}
                                </div>
                            )}
                        </div>
                    </Kpi>
                </div>

                <Tabs value={tab} onValueChange={setTab} className="min-h-0 flex-1 gap-3">
                    <TabsList className={TABS_LIST}>
                        <TabTrigger value="stage1" label="Stage-1 problems" n={stage1.length} />
                        <TabTrigger value="soma" label="SOMA tasks" n={soma.length} />
                        <TabTrigger value="history" label="Earlier rounds" n={history.length} />
                        <TabTrigger value="family" label="Family links" n={(family?.direct_links.length ?? 0) + (family?.shared_wallets.length ?? 0)} />
                        <TabTrigger value="registrations" label="Subnet registrations" n={owner?.registrations.length ?? 0} />
                        <TabTrigger value="money" label="Money flows" n={owner?.transfers.length ?? 0} />
                        <TabTrigger value="events" label="Events" n={events.length} />
                    </TabsList>

                    <TabsContent value="stage1" className="flex min-h-0 flex-col gap-2">
                        <p className="text-sm text-muted-foreground">Click a row to see its runs. The last column is the reference miner {reference ? shortKey(reference.hotkey) : ''} on the same problem.</p>
                        <Scroll>
                            <TaskTable id="stage1" tasks={stage1} withReference />
                        </Scroll>
                    </TabsContent>

                    <TabsContent value="soma" className="flex min-h-0 flex-col gap-2">
                        <Scroll>{soma.length ? <TaskTable id="soma" tasks={soma} /> : <p className="p-4 text-sm text-muted-foreground">No SOMA task yet - the 30 SOMA tasks run in the final evaluation.</p>}</Scroll>
                    </TabsContent>

                    <TabsContent value="history" className="flex min-h-0 flex-col gap-2">
                        <p className="text-sm text-muted-foreground">This hotkey and every hotkey of its family in earlier rounds (rank among scored entries).</p>
                        <Scroll>
                            {history.length === 0 ? (
                                <p className="p-4 text-sm text-muted-foreground">No earlier entry known (new owner, or its earlier hotkeys are unknown until the owner is traced).</p>
                            ) : (
                                <Table className="text-sm">
                                    <Head2>
                                        <SortHead id="round" label="round" {...by(hs)} />
                                        <SortHead id="hotkey" label="hotkey" {...by(hs)} />
                                        <SortHead id="status" label="status" {...by(hs)} />
                                        <SortHead id="rank" label="rank" {...by(hs)} className="text-right" />
                                        <SortHead id="overall" label="overall" {...by(hs)} className="text-right" />
                                        <TableHead className="text-right">
                                            <span className="inline-flex items-center gap-1">
                                                <SortButton id="short" label="short" {...by(hs)} /> · <SortButton id="medium" label="medium" {...by(hs)} /> ·{' '}
                                                <SortButton id="long" label="long" {...by(hs)} />
                                            </span>
                                        </TableHead>
                                        <SortHead id="stage1" label="stage 1" {...by(hs)} className="text-right" />
                                        <SortHead id="steps" label="fingerprint" {...by(hs)} />
                                    </Head2>
                                    <TableBody>
                                        {hs.sorted.map((h) => (
                                            <TableRow key={`${h.competition}-${h.hotkey}`}>
                                                <TableCell>
                                                    <Link href={overviewRoute.url({ query: { comp: h.competition } })} className="hover:underline">
                                                        R{h.round ?? '?'} ({h.competition})
                                                    </Link>
                                                </TableCell>
                                                <TableCell>
                                                    <Link href={minerRoute({ competition: h.competition, hotkey: h.hotkey ?? '' })} className={cn('font-mono hover:underline', h.same_hotkey && 'font-semibold')}>
                                                        {shortKey(h.hotkey, 10)}
                                                    </Link>
                                                    {h.same_hotkey && <span className="ml-1 text-xs text-muted-foreground">this hotkey</span>}
                                                </TableCell>
                                                <TableCell>
                                                    <StatusBadge status={h.status} />
                                                </TableCell>
                                                <TableCell className="text-right font-mono">{h.place ? `${h.place}/${h.of}` : '—'}</TableCell>
                                                <TableCell className="text-right font-mono">{num(h.overall)}</TableCell>
                                                <TableCell className="text-right font-mono text-muted-foreground">
                                                    {num(h.categories.short, 2)} · {num(h.categories.medium, 2)} · {num(h.categories.long, 2)}
                                                </TableCell>
                                                <TableCell className="text-right font-mono">{num(h.stage1)}</TableCell>
                                                <TableCell>
                                                    <FingerprintBadge fp={h.fingerprint} compact />
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            )}
                        </Scroll>
                    </TabsContent>

                    <TabsContent value="family" className="flex min-h-0 flex-col gap-2">
                        <Scroll>
                            <Table className="text-sm">
                                <Head2>
                                    <TableHead>link</TableHead>
                                    <TableHead>between</TableHead>
                                    <TableHead>strength</TableHead>
                                    <TableHead>detail</TableHead>
                                </Head2>
                                <TableBody>
                                    {(family?.direct_links ?? []).length + (family?.shared_wallets ?? []).length === 0 && (
                                        <TableRow>
                                            <TableCell colSpan={4} className="text-muted-foreground">
                                                No link to another owner found{owner?.profiled_at ? '' : ' yet (owner not traced)'}.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {family?.direct_links.map((l) => (
                                        <TableRow key={`${l.a}${l.b}`}>
                                            <TableCell>direct transfer</TableCell>
                                            <TableCell>
                                                <KeyLabel value={l.a} /> ↔ <KeyLabel value={l.b} />
                                            </TableCell>
                                            <TableCell>
                                                <Badge className="border-transparent bg-violet-600 text-white">strong</Badge>
                                            </TableCell>
                                            <TableCell className="font-mono">
                                                {l.n} transfers · τ{l.tao.toFixed(3)} · first {l.first ? utc(l.first) : '—'}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                    {family?.shared_wallets.map((s) => (
                                        <TableRow key={s.address}>
                                            <TableCell>shared wallet</TableCell>
                                            <TableCell>
                                                <span className="flex flex-wrap items-center gap-1">
                                                    <KeyLabel value={s.address} />
                                                    <span className="text-muted-foreground">{s.others.length ? 'also with' : 'only inside this family'}</span>
                                                    {s.others.map((o) => (
                                                        <KeyLabel key={o} value={o} />
                                                    ))}
                                                </span>
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant="outline" className={cn(s.kind === 'personal' && 'border-amber-500 text-amber-700', s.kind === 'exchange-like' && 'text-muted-foreground')}>
                                                    {s.kind === 'personal' ? 'medium' : s.kind === 'exchange-like' ? 'weak' : (s.kind ?? 'unclassified')}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="font-mono">{s.transfers !== null ? `${s.transfers} transfers (${s.kind})` : 'not classified yet'}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </Scroll>
                    </TabsContent>

                    <TabsContent value="registrations" className="flex min-h-0 flex-col gap-2">
                        <Scroll>
                            <Table className="text-sm">
                                <Head2>
                                    <SortHead id="at" label="when (UTC)" {...by(rs)} />
                                    <SortHead id="netuid" label="subnet" {...by(rs)} />
                                    <SortHead id="uid" label="uid" {...by(rs)} />
                                    <SortHead id="hotkey" label="hotkey" {...by(rs)} />
                                    <SortHead id="cost" label="burn" {...by(rs)} className="text-right" />
                                </Head2>
                                <TableBody>
                                    {(owner?.registrations ?? []).length === 0 && (
                                        <TableRow>
                                            <TableCell colSpan={5} className="text-muted-foreground">
                                                {owner?.profiled_at ? 'none' : 'owner not traced yet (queued)'}
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {rs.sorted.map((r) => (
                                        <TableRow key={`${r.netuid}-${r.hotkey}-${r.at}`}>
                                            <TableCell className="font-mono">{utc(r.at)}</TableCell>
                                            <TableCell>{r.netuid === 114 ? <Badge>SN114</Badge> : `SN${r.netuid}`}</TableCell>
                                            <TableCell>{r.uid ?? '—'}</TableCell>
                                            <TableCell>
                                                <KeyLabel value={r.hotkey} />
                                            </TableCell>
                                            <TableCell className="text-right font-mono">{r.cost_tao !== null ? `τ${r.cost_tao.toFixed(3)}` : '—'}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </Scroll>
                    </TabsContent>

                    <TabsContent value="money" className="flex min-h-0 flex-col gap-2">
                        <Scroll>
                            <Table className="text-sm">
                                <Head2>
                                    <SortHead id="at" label="when (UTC)" {...by(ts)} />
                                    <SortHead id="direction" label="direction" {...by(ts)} />
                                    <SortHead id="tao" label="TAO" {...by(ts)} className="text-right" />
                                    <SortHead id="other" label="counterparty" {...by(ts)} />
                                    <SortHead id="kind" label="kind" {...by(ts)} />
                                </Head2>
                                <TableBody>
                                    {(owner?.transfers ?? []).length === 0 && (
                                        <TableRow>
                                            <TableCell colSpan={5} className="text-muted-foreground">
                                                {owner?.profiled_at ? 'none' : 'owner not traced yet (queued)'}
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {ts.sorted.map((t, i) => (
                                        <TableRow key={i}>
                                            <TableCell className="font-mono">{utc(t.at)}</TableCell>
                                            <TableCell className={t.direction === 'in' ? 'text-emerald-600' : 'text-red-600'}>{t.direction}</TableCell>
                                            <TableCell className="text-right font-mono">{t.tao.toFixed(3)}</TableCell>
                                            <TableCell>
                                                <span className="flex items-center gap-1">
                                                    <KeyLabel value={t.other} />
                                                    {t.other_is_owner && <Badge className="border-transparent bg-violet-600 text-white">SN114 owner</Badge>}
                                                </span>
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {t.other_kind ?? '—'}
                                                {t.other_transfers !== null ? ` (${t.other_transfers} transfers)` : ''}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </Scroll>
                    </TabsContent>

                    <TabsContent value="events" className="flex min-h-0 flex-col gap-2">
                        <Scroll>
                            <div className="p-3">
                                <EventsList events={events} competition={competition.id} />
                            </div>
                        </Scroll>
                    </TabsContent>
                </Tabs>
            </div>
        </>
    );
}

function TabTrigger({ value, label, n }: { value: string; label: string; n?: number }) {
    return (
        <TabsTrigger value={value} className={TABS_TRIGGER}>
            {label}
            {n !== undefined && <span className="rounded bg-muted-foreground/15 px-1.5 text-xs">{n}</span>}
        </TabsTrigger>
    );
}

/** A frame that scrolls both ways; the table's own overflow wrapper is off so the sticky header sticks to the frame. */
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

function TaskTable({ id, tasks, withReference = false }: { id: string; tasks: Task[]; withReference?: boolean }) {
    const { sorted, sort, toggle } = useSort(id, tasks, TASK_COLUMNS);
    const props = { sort, onSort: toggle };

    return (
        <Table className="text-sm">
            <Head2>
                <TableHead className="w-8" />
                <SortHead id="task" label="task" {...props} />
                <SortHead id="size" label="size" {...props} />
                <SortHead id="passed" label="passed" {...props} className="text-center" />
                <SortHead id="baseline" label="baseline" {...props} className="text-right" />
                <SortHead id="score" label="score" {...props} className="text-right" />
                <SortHead id="ratio" label="tokens vs baseline" {...props} className="text-right" />
                <SortHead id="steps" label="steps / run" {...props} className="text-right" />
                {withReference && <SortHead id="reference" label="reference" {...props} className="text-right" />}
            </Head2>
            <TableBody>
                {sorted.map((t) => (
                    <TaskRow key={t.name} task={t} withReference={withReference} />
                ))}
            </TableBody>
        </Table>
    );
}

function TaskRow({ task, withReference }: { task: Task; withReference: boolean }) {
    const [open, setOpen] = useState(false);

    return (
        <>
            <TableRow className="cursor-pointer" onClick={() => setOpen(!open)}>
                <TableCell>
                    <ChevronRight className={cn('size-4 transition-transform', open && 'rotate-90')} />
                </TableCell>
                <TableCell className="font-mono">{PROBLEM_SHORT[task.name] ?? task.name}</TableCell>
                <TableCell>{task.complexity ?? (task.is_screener ? 'stage 1' : '—')}</TableCell>
                <TableCell className="w-28">
                    <ProblemCell cell={task} />
                </TableCell>
                <TableCell className="text-right font-mono">
                    {task.baseline_passed}/{task.baseline_total}
                </TableCell>
                <TableCell className="text-right font-mono">{num(task.score)}</TableCell>
                <TableCell className="text-right font-mono">{times(task.ratio)}</TableCell>
                <TableCell className="text-right font-mono">{num(task.steps, 1)}</TableCell>
                {withReference && (
                    <TableCell className="text-right font-mono text-muted-foreground">
                        {task.reference ? `${task.reference.passed ?? '?'} pass · ${num(task.reference.steps, 1)} steps · ${times(task.reference.ratio)}` : '—'}
                    </TableCell>
                )}
            </TableRow>
            {open && (
                <TableRow className="bg-muted/30 hover:bg-muted/30">
                    <TableCell />
                    <TableCell colSpan={withReference ? 8 : 7}>
                        <div className="flex flex-wrap gap-1 py-1">
                            {task.runs.map((r) => (
                                <span
                                    key={r.seq}
                                    title={`run ${r.seq}: ${r.steps ?? '?'} steps, input ${k(r.input)}, cached ${k(r.cached)}, output ${k(r.output)}${r.jev ? `, jev ${k(r.jev)}` : ''}, weighted ${k(r.weighted)}, ${r.seconds ? Math.round(r.seconds) : '?'} s`}
                                    className={cn('rounded px-1.5 py-0.5 font-mono', r.passed === null ? 'bg-sky-500/15' : r.passed ? 'bg-emerald-500/20' : 'bg-red-500/20')}
                                >
                                    {r.steps ?? '·'}
                                    {r.passed === false ? 'F' : ''}
                                </span>
                            ))}
                        </div>
                        <div className="text-xs text-muted-foreground">each box = one run: its steps (F = failed, blue = still running); hover for tokens and time</div>
                    </TableCell>
                </TableRow>
            )}
        </>
    );
}

Miner.layout = {
    breadcrumbs: [
        { title: 'SOMA', href: homeRoute() },
        { title: 'Leaderboard', href: overviewRoute() },
        { title: 'Entry', href: '#' },
    ],
};
