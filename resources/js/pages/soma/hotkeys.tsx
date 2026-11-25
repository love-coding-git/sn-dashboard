import { Head, Link, usePoll } from '@inertiajs/react';
import { KeyRound, Network, TriangleAlert, UserPlus } from 'lucide-react';
import type { ReactNode } from 'react';
import { HeroChip, HeroStat, Kpi, PageHero, PAGE, TABLE_FRAME, TABS_LIST, TABS_TRIGGER } from '@/components/soma/kit';
import { KeyLabel } from '@/components/soma/key-label';
import { SortHead } from '@/components/soma/sort-head';
import { StatusBadge } from '@/components/soma/status-badge';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import type { SortColumn, SortState } from '@/hooks/use-sort';
import { useSort } from '@/hooks/use-sort';
import { useTab } from '@/hooks/use-tab';
import { ago, num, utc } from '@/lib/soma';
import { cn } from '@/lib/utils';
import { home as homeRoute, hotkeys as hotkeysRoute, miner as minerRoute } from '@/routes/soma';

type Snapshot = {
    block: number;
    taken_at: string;
    immunity_period: number;
    uids: number;
    immune: number;
    zero_emission: number;
    registrations: { h1: number; h6: number; h24: number; d3: number; d7: number };
    per_day_24h: number;
    per_day_7d: number;
};
type Entry = { competition: number; round: number | null; label: string | null; status: string | null; stage1: number | null; overall: number | null; place: number | null; uploaded_at: string | null };
type Ours = {
    name: string;
    hotkey: string;
    registered: boolean;
    uid: number | null;
    emission: number | null;
    registered_at_block: number | null;
    age_days: number | null;
    immune: boolean;
    immune_hours_left: number | null;
    rank: number | null;
    rank_day_ago: number | null;
    eta_days_24h: number | null;
    eta_days_7d: number | null;
    note: string | null;
    in_current: boolean;
    entries: Entry[];
};
type QueueRow = { rank: number | null; uid: number; hotkey: string; coldkey: string; emission: number; registered_at_block: number; age_days: number; validator: boolean; validating: boolean; ours: string | null; family: string | null };
type HistoryRow = { taken_at: string; block: number; zero_emission: number; ranks: Record<string, number | null>; emission: Record<string, number | null> };

type Props = {
    snapshot: Snapshot | null;
    ours: Ours[];
    queue: QueueRow[];
    history: HistoryRow[];
    names: string[];
    current: { id: number; round: number | null };
};

const TABS = ['ours', 'queue', 'history'];
const WINDOWS = [
    ['h1', '1 h'],
    ['h6', '6 h'],
    ['h24', '24 h'],
    ['d3', '3 d'],
    ['d7', '7 d'],
] as const;

const OURS_COLUMNS: Record<string, SortColumn<Ours>> = {
    name: { value: (o) => o.name },
    uid: { value: (o) => o.uid },
    emission: { value: (o) => o.emission },
    rank: { value: (o) => o.rank },
    change: { value: (o) => (o.rank !== null && o.rank_day_ago !== null ? o.rank - o.rank_day_ago : null) },
    eta: { value: (o) => o.eta_days_24h },
    age: { value: (o) => o.age_days, first: 'desc' },
};
const QUEUE_COLUMNS: Record<string, SortColumn<QueueRow>> = {
    rank: { value: (q) => q.rank },
    uid: { value: (q) => q.uid },
    emission: { value: (q) => q.emission },
    age: { value: (q) => q.age_days, first: 'desc' },
    family: { value: (q) => q.ours ?? q.family },
};

/** Chain emission per tempo, short: 2.34M, 72.4G. */
function emission(v: number | null): string {
    if (v === null) {
        return '—';
    }

    if (v === 0) {
        return '0';
    }

    return v >= 1e9 ? `${(v / 1e9).toFixed(1)}G` : v >= 1e6 ? `${(v / 1e6).toFixed(2)}M` : v >= 1e3 ? `${(v / 1e3).toFixed(1)}k` : String(v);
}

/** Queue place tone: red near the front, amber within reach of a busy day. */
function rankTone(rank: number | null): string {
    if (rank === null) {
        return 'text-muted-foreground';
    }

    return rank <= 10 ? 'bg-red-600 text-white' : rank <= 30 ? 'bg-amber-500 text-white' : 'bg-muted text-foreground';
}

function days(v: number | null): string {
    if (v === null) {
        return '—';
    }

    return v < 1 ? `≈${Math.round(v * 24)} h` : `≈${v.toFixed(1)} d`;
}

export default function Hotkeys({ snapshot, ours, queue, history, names, current }: Props) {
    usePoll(60_000);

    const [tab, setTab] = useTab('ours', TABS);
    const now = Date.now();
    const os = useSort('ours', ours, OURS_COLUMNS);
    const qs = useSort('queue', queue, QUEUE_COLUMNS);
    const by = (x: { sort: SortState; toggle: (key: string) => void }) => ({ sort: x.sort, onSort: x.toggle });

    const registered = ours.filter((o) => o.registered);
    const front = registered.filter((o) => o.rank !== null).sort((a, b) => (a.rank ?? 0) - (b.rank ?? 0))[0] ?? null;
    const withEmission = registered.filter((o) => (o.emission ?? 0) > 0).length;

    return (
        <>
            <Head title="Hotkeys" />
            <div className={PAGE}>
                <PageHero
                    tone="emerald"
                    eyebrow="SOMA · subnet 114 · chain"
                    title="Our hotkeys"
                    subtitle={snapshot ? `block ${snapshot.block.toLocaleString()} · read from the chain every 10 min` : 'no chain snapshot yet - php artisan soma:hotkeys --now'}
                    right={
                        front ? (
                            <HeroStat label="closest to deregistration" value={`#${front.rank}`} note={`${front.name} · ${days(front.eta_days_7d)} at the 7-day pace`} />
                        ) : undefined
                    }
                >
                    {snapshot && (
                        <>
                            <HeroChip>
                                read {utc(snapshot.taken_at)} UTC ({ago(snapshot.taken_at, now)})
                            </HeroChip>
                            <HeroChip>
                                {registered.length} of {ours.length} registered
                            </HeroChip>
                            <HeroChip warn={withEmission === 0}>{withEmission} with emission</HeroChip>
                            <HeroChip>{snapshot.registrations.h24} new registrations in 24 h</HeroChip>
                        </>
                    )}
                </PageHero>

                {snapshot && (
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                        <Kpi icon={TriangleAlert} title="Closest to deregistration" tone="amber">
                            <div className="space-y-1 text-sm">
                                {front ? (
                                    <>
                                        <div className="flex items-center gap-2">
                                            <span className={cn('rounded px-2 py-0.5 font-mono text-base font-semibold', rankTone(front.rank))}>#{front.rank}</span>
                                            <span className="font-medium">{front.name}</span>
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {days(front.eta_days_24h)} at the last day&apos;s pace · {days(front.eta_days_7d)} at the 7-day pace
                                        </div>
                                    </>
                                ) : (
                                    <span className="text-muted-foreground">none of ours in the queue</span>
                                )}
                            </div>
                        </Kpi>
                        <Kpi icon={KeyRound} title="Ours" tone="indigo">
                            <div className="grid grid-cols-2 gap-x-3 gap-y-1 text-sm">
                                <span className="text-muted-foreground">registered</span>
                                <span className="font-mono">
                                    {registered.length} of {ours.length}
                                </span>
                                <span className="text-muted-foreground">with emission</span>
                                <span className={cn('font-mono', withEmission === 0 && 'text-red-600 dark:text-red-400')}>{withEmission}</span>
                                <span className="text-muted-foreground">in round {current.round ?? '?'} ({current.id})</span>
                                <span className="font-mono">{registered.filter((o) => o.in_current).length}</span>
                            </div>
                        </Kpi>
                        <Kpi icon={UserPlus} title="New SN114 registrations" tone="violet">
                            <div className="grid grid-cols-5 gap-x-2 text-center text-sm">
                                {WINDOWS.map(([w, label]) => (
                                    <div key={w}>
                                        <div className="font-mono text-base">{snapshot.registrations[w]}</div>
                                        <div className="text-xs text-muted-foreground">{label}</div>
                                    </div>
                                ))}
                            </div>
                        </Kpi>
                        <Kpi icon={Network} title="Subnet 114" tone="sky">
                            <div className="grid grid-cols-2 gap-x-3 gap-y-1 text-sm">
                                <span className="text-muted-foreground">uids</span>
                                <span className="font-mono">{snapshot.uids}</span>
                                <span className="text-muted-foreground">emission 0</span>
                                <span className="font-mono">{snapshot.zero_emission}</span>
                                <span className="text-muted-foreground">immune now</span>
                                <span className="font-mono">
                                    {snapshot.immune} <span className="text-xs text-muted-foreground">({snapshot.immunity_period} blocks ≈ {Math.round((snapshot.immunity_period * 12) / 3600)} h)</span>
                                </span>
                            </div>
                        </Kpi>
                    </div>
                )}

                <p className="text-xs text-muted-foreground">
                    Queue order: lowest emission first, then the earliest registration; uids inside the immunity period are skipped (our reading of the chain rule, not checked in the subtensor code). The subnet is full, so every new registration
                    removes place 1. A place also moves forward when an older uid ahead gains emission (for example, an upload to the current round), and back when one of ours gains it. Time left = place ÷ registrations per day.
                </p>

                <Tabs value={tab} onValueChange={setTab} className="min-h-0 flex-1 gap-3">
                    <TabsList className={TABS_LIST}>
                        <TabTrigger value="ours" label="Our hotkeys" n={ours.length} />
                        <TabTrigger value="queue" label="Deregistration queue" n={queue.length} />
                        <TabTrigger value="history" label="Place history" n={history.length} />
                    </TabsList>

                    <TabsContent value="ours" className="flex min-h-0 flex-col gap-2">
                        <Scroll>
                            <Table className="text-sm">
                                <Head2>
                                    <SortHead id="name" label="hotkey" {...by(os)} />
                                    <TableHead>address</TableHead>
                                    <SortHead id="uid" label="uid" {...by(os)} className="text-right" />
                                    <SortHead id="emission" label="emission" {...by(os)} className="text-right" />
                                    <SortHead id="rank" label="queue place" {...by(os)} className="text-center" />
                                    <SortHead id="change" label="vs 24 h ago" {...by(os)} className="text-right" />
                                    <SortHead id="eta" label="time left (1 d · 7 d pace)" {...by(os)} className="text-right" />
                                    <SortHead id="age" label="registered" {...by(os)} className="text-right" />
                                    <TableHead>entries</TableHead>
                                    <TableHead>note</TableHead>
                                </Head2>
                                <TableBody>
                                    {os.sorted.map((o) => (
                                        <TableRow key={o.hotkey} className={cn(!o.registered && 'opacity-60')}>
                                            <TableCell className="font-medium">{o.name}</TableCell>
                                            <TableCell>
                                                <KeyLabel value={o.hotkey} />
                                            </TableCell>
                                            <TableCell className="text-right font-mono">{o.uid ?? '—'}</TableCell>
                                            <TableCell className={cn('text-right font-mono', o.registered && !o.emission && 'text-red-600 dark:text-red-400')}>{emission(o.emission)}</TableCell>
                                            <TableCell className="text-center">
                                                {!o.registered ? (
                                                    <Badge variant="outline">not registered</Badge>
                                                ) : o.immune ? (
                                                    <Badge className="border-transparent bg-sky-600 text-white">immune · {o.immune_hours_left} h</Badge>
                                                ) : (
                                                    <span className={cn('rounded px-2 py-0.5 font-mono font-semibold', rankTone(o.rank))}>{o.rank}</span>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right font-mono">
                                                <Change now={o.rank} before={o.rank_day_ago} />
                                            </TableCell>
                                            <TableCell className="text-right font-mono">
                                                {o.rank ? (
                                                    <>
                                                        {days(o.eta_days_24h)} · <span className="text-muted-foreground">{days(o.eta_days_7d)}</span>
                                                    </>
                                                ) : (
                                                    '—'
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right font-mono text-muted-foreground" title={o.registered_at_block ? `block ${o.registered_at_block}` : undefined}>
                                                {o.age_days !== null ? `${o.age_days} d ago` : '—'}
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex flex-wrap gap-1">
                                                    {o.entries.length === 0 && <span className="text-muted-foreground">—</span>}
                                                    {o.entries.map((e) => (
                                                        <Link
                                                            key={e.competition}
                                                            href={minerRoute({ competition: e.competition, hotkey: o.hotkey })}
                                                            className="inline-flex items-center gap-1 rounded border px-1.5 py-0.5 text-xs hover:bg-muted"
                                                            title={`uploaded ${utc(e.uploaded_at)} UTC · stage 1 ${num(e.stage1)} · overall ${num(e.overall)}${e.place ? ` · place ${e.place}` : ''}`}
                                                        >
                                                            R{e.round ?? '?'}
                                                            {e.label ? ` ${e.label}` : ''}
                                                            <StatusBadge status={e.status} />
                                                        </Link>
                                                    ))}
                                                </div>
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">{o.note ?? ''}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </Scroll>
                    </TabsContent>

                    <TabsContent value="queue" className="flex min-h-0 flex-col gap-2">
                        <p className="text-sm text-muted-foreground">Every uid in queue order (immune uids at the bottom). Ours are highlighted.</p>
                        <Scroll>
                            <Table className="text-sm">
                                <Head2>
                                    <SortHead id="rank" label="place" {...by(qs)} className="text-right" />
                                    <SortHead id="uid" label="uid" {...by(qs)} className="text-right" />
                                    <TableHead>hotkey</TableHead>
                                    <TableHead>coldkey</TableHead>
                                    <SortHead id="family" label="ours / family" {...by(qs)} />
                                    <SortHead id="emission" label="emission" {...by(qs)} className="text-right" />
                                    <SortHead id="age" label="registered" {...by(qs)} className="text-right" />
                                </Head2>
                                <TableBody>
                                    {qs.sorted.map((q) => (
                                        <TableRow key={q.uid} className={cn(q.ours && 'bg-indigo-500/10 font-medium hover:bg-indigo-500/15')}>
                                            <TableCell className="text-right font-mono">{q.rank ?? <Badge className="border-transparent bg-sky-600 text-white">immune</Badge>}</TableCell>
                                            <TableCell className="text-right font-mono">{q.uid}</TableCell>
                                            <TableCell>
                                                <KeyLabel value={q.hotkey} />
                                            </TableCell>
                                            <TableCell>
                                                <KeyLabel value={q.coldkey} />
                                            </TableCell>
                                            <TableCell>
                                                {q.ours ? <Badge className="border-transparent bg-indigo-600 text-white">{q.ours}</Badge> : <span className="text-muted-foreground">{q.family ?? '—'}</span>}
                                                {q.validating ? (
                                                    <Badge variant="outline" className="ml-1" title="Validator permit and dividends: this uid validates">
                                                        validator
                                                    </Badge>
                                                ) : q.validator ? (
                                                    <Badge variant="outline" className="ml-1 text-muted-foreground" title="Validator permit only: the chain gives one to the uids with the most stake, miners included. No dividends, so it is not validating.">
                                                        permit
                                                    </Badge>
                                                ) : null}
                                            </TableCell>
                                            <TableCell className={cn('text-right font-mono', q.emission === 0 && 'text-muted-foreground')}>{emission(q.emission)}</TableCell>
                                            <TableCell className="text-right font-mono text-muted-foreground" title={`block ${q.registered_at_block}`}>
                                                {q.age_days} d ago
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </Scroll>
                    </TabsContent>

                    <TabsContent value="history" className="flex min-h-0 flex-col gap-2">
                        <p className="text-sm text-muted-foreground">Our queue places, one reading per hour, last 7 days (newest first). Red = no emission at that reading.</p>
                        <Scroll>
                            <Table className="text-sm">
                                <Head2>
                                    <TableHead>time (UTC)</TableHead>
                                    <TableHead className="text-right">block</TableHead>
                                    <TableHead className="text-right">emission 0</TableHead>
                                    {names.map((n) => (
                                        <TableHead key={n} className="text-right">
                                            {n}
                                        </TableHead>
                                    ))}
                                </Head2>
                                <TableBody>
                                    {history.map((h) => (
                                        <TableRow key={h.taken_at}>
                                            <TableCell className="font-mono">{utc(h.taken_at)}</TableCell>
                                            <TableCell className="text-right font-mono text-muted-foreground">{h.block.toLocaleString()}</TableCell>
                                            <TableCell className="text-right font-mono text-muted-foreground">{h.zero_emission}</TableCell>
                                            {names.map((n) => (
                                                <TableCell key={n} className={cn('text-right font-mono', h.emission[n] === 0 && 'text-red-600 dark:text-red-400')}>
                                                    {h.ranks[n] ?? '—'}
                                                </TableCell>
                                            ))}
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </Scroll>
                    </TabsContent>
                </Tabs>
            </div>
        </>
    );
}

/** Places gained toward the front in red (▲), moved back in green (▼). */
function Change({ now, before }: { now: number | null; before: number | null }) {
    if (now === null || before === null) {
        return <span className="text-muted-foreground">—</span>;
    }

    const d = now - before;

    if (d === 0) {
        return <span className="text-muted-foreground">0</span>;
    }

    return d < 0 ? <span className="text-red-600 dark:text-red-400">▲{-d}</span> : <span className="text-emerald-600 dark:text-emerald-400">▼{d}</span>;
}

function TabTrigger({ value, label, n }: { value: string; label: string; n?: number }) {
    return (
        <TabsTrigger value={value} className={TABS_TRIGGER}>
            {label}
            {n !== undefined && <span className="rounded bg-muted-foreground/15 px-1.5 text-xs">{n}</span>}
        </TabsTrigger>
    );
}

function Head2({ children }: { children: ReactNode }) {
    return (
        <TableHeader className="sticky top-0 z-10 bg-muted shadow-[0_1px_0_var(--border)]">
            <TableRow className="hover:bg-muted">{children}</TableRow>
        </TableHeader>
    );
}

/** A frame that scrolls both ways; the table's own overflow wrapper is off so the sticky header sticks to the frame. */
function Scroll({ children }: { children: ReactNode }) {
    return <div className={cn('max-h-[calc(100svh-5rem)] overflow-auto [&>[data-slot=table-container]]:overflow-visible', TABLE_FRAME)}>{children}</div>;
}

Hotkeys.layout = {
    breadcrumbs: [
        { title: 'SOMA', href: homeRoute() },
        { title: 'Our hotkeys', href: hotkeysRoute() },
    ],
};
