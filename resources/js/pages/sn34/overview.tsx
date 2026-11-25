import { Head, usePoll } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { Activity, Bell, BookOpen, Crown, Database, Gavel, IdCard, KeyRound, ListChecks, MessageSquare, Shield, UserRound, Users } from 'lucide-react';
import type { ReactNode } from 'react';
import { useMemo, useState } from 'react';
import { HeroChip, HeroStat, Kpi, PageHero, PAGE, Pill, scoreTone, Section, TABLE_FRAME, TABS_LIST, TABS_TRIGGER } from '@/components/soma/kit';
import { SortHead } from '@/components/soma/sort-head';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useQueryParam } from '@/hooks/use-query-param';
import type { SortColumn, SortState } from '@/hooks/use-sort';
import { useSort } from '@/hooks/use-sort';
import { useTab } from '@/hooks/use-tab';
import { ago, num, shortKey, utc } from '@/lib/soma';
import { cn } from '@/lib/utils';
import { overview as sn34Overview } from '@/routes/sn34';

type ClassRow = { q: number | null; usd: number | null; s: number | null; hard: number | null; fails: string[]; nv: number | null; d_usd?: number | null; d_hard?: number | null };
type Classes = Partial<Record<'agentic' | 'chat_qa' | 'math_code', ClassRow>>;
type RunRow = {
    run: string; run_id: string; hotkey: string; coldkey: string | null; family: string | null; ours: boolean; pack: string | null; state: string;
    enqueued_at: string | null; started_at: string | null; finished_at: string | null; bundle: string; second_attempts: number;
    eligible: boolean | null; promoted: boolean | null; nv: number | null; lb: number | null; reasons: string[]; classes: Classes;
};
type NowRow = RunRow & { queue_state: string | null; queue_reason: string | null; executed: number | null; assigned: number | null; graded: number | null; ungraded: number | null; results_status: string | null; progress_at: string | null };
type Decision = RunRow & { first_confirmed_at: string | null; near_miss: boolean };
type King = { seat: string; hotkey: string; run: string; share: number | null; nv: number | null; lb: number | null; coldkey: string | null; family: string | null; ours: boolean; incentive: number | null; classes: Classes };
type PackStat = { runs: number; states: Record<string, number>; judged: number; eligible: number; crowns: number; best_nv: number | null };
type Family = {
    key: string; coldkeys: string[]; hotkeys: string[]; ours: boolean; runs: number; crowns: number; current_runs: number; current_eligible: number;
    current_best_nv: number | null; current_best_lb: number | null; packs: Record<string, PackStat>; seat_hours: Record<string, number>; incentive: number;
    last_enqueue: string | null; running: string[];
};
type SeatSpan = { from: string; to: string | null; hours: number; open: boolean; policy: string | null; seats: { seat: string; hotkey: string; run: string; share: number | null; family: string | null }[] };
type DatasetRow = { dataset: string; items: number; runs: number; median: number; best: { score: number; run: string; hotkey: string; family: string | null }; by_family: Record<string, number> };
type CardRow = { sha: string; synced_at: string | null; choices: { choice: string; present: boolean; format: string; in: number | null; out: number | null }[] };
type EventRow = { id: number; at: string; type: string; run: string | null; hotkey: string | null; family: string | null; data: Record<string, unknown> };
type OurHotkey = {
    name: string | null; hotkey: string; coldkey: string | null; registered: boolean; uid: number | null; incentive: number | null;
    daily_alpha: number | null; daily_tao: number | null; emission: number | null; registered_at: string | null; age_blocks: number | null;
    immune: boolean | null; immunity_left_hours: number | null; in_danger: boolean | null; place: number | null;
    last_run: { run: string; state: string; enqueued_at: string | null; eligible: boolean | null; promoted: boolean | null; nv: number | null } | null;
};
type Notes = { generated_at: string | null; watch_rows: string[][]; discord_rows: string[][]; discord_count?: number; discord_newest?: string | null; discord_read_at?: string | null; discord_next_at?: string | null; loop_stops: { log: string; line: string }[]; family_notes: { coldkeys: string[]; kind: string; strength: string; verdict: string; text: string; source: string }[] };

type Props = {
    board: { confirmed_at: string | null; policy: string | null; phase: string | null; paused: boolean | null; weights: Record<string, number>; floors: Record<string, { quality: number | null; hard: number | null; private: number | null }>; queue: { run_id: string; hotkey: string; state: string; reason: string | null }[]; read_at: string | null } | null;
    pack: string | null;
    kings: King[];
    runs_now: NowRow[];
    decisions: Decision[];
    runs: RunRow[];
    runs_omitted: number;
    families: Family[];
    evidence: { direct: { from: string; to: string; tao: number; at: string | null }[]; shared: { address: string; owners: string[]; transfers: number | null; kind: string | null }[] };
    seat_history: SeatSpan[];
    datasets: DatasetRow[];
    cards: CardRow[];
    events: EventRow[];
    notes: Notes;
    our_hotkeys: { rows: OurHotkey[]; uids: number; non_immune: number; immunity_period: number; read_at: string | null };
    reads: Record<string, string | null>;
};

const TABS = ['now', 'decisions', 'runs', 'families', 'family', 'seats', 'datasets', 'card', 'events', 'watch', 'discord'];
const CLASS_LABEL: Record<string, string> = { agentic: 'agentic', chat_qa: 'chat', math_code: 'math' };
const usd = (v: number | null | undefined) => (v === null || v === undefined ? '-' : `$${v.toFixed(2)}`);
const signed = (v: number | null | undefined, d = 3) => (v === null || v === undefined ? '-' : `${v >= 0 ? '+' : ''}${v.toFixed(d)}`);
const famLabel = (key: string | null | undefined, ours = false) => (ours ? 'ours' : key ? key.slice(0, 8) : '-');
/** A read older than its cadence allows (board and runs every 1 - 2 min, card and metagraph every 10 min). */
const stale = (k: string, iso: string | null, now: number) => !iso || now - new Date(iso).getTime() > (k === 'board' || k === 'runs' ? 15 : 40) * 60_000;
const totalUsd = (c: Classes) => Object.values(c).reduce((s, x) => s + (x?.usd ?? 0), 0);
const YUGOS = 'https://competition.yugos.ai';
const TAOSTATS = 'https://taostats.io';
const EXT = { target: '_blank', rel: 'noopener noreferrer' } as const;

/** SortHead props from a useSort result. */
const by = (s: { sort: SortState; toggle: (key: string) => void }) => ({ sort: s.sort, onSort: s.toggle });
const RUN_COLUMNS: Record<string, SortColumn<RunRow>> = {
    enqueued: { value: (r) => r.enqueued_at, first: 'desc' }, run: { value: (r) => r.run }, family: { value: (r) => famLabel(r.family, r.ours) },
    state: { value: (r) => r.state }, nv: { value: (r) => r.nv, first: 'desc' }, lb: { value: (r) => r.lb, first: 'desc' }, usd: { value: (r) => totalUsd(r.classes) },
};

export default function Sn34Overview(props: Props) {
    const { board, kings, runs_now: runsNow, decisions, runs, families, seat_history: seats, datasets, cards, events, notes, reads } = props;
    // the page polls every minute (the jobs read the API every 1 - 10 min)
    usePoll(60_000);
    const [tab, setTab] = useTab('now', TABS);
    const [fam, setFam] = useQueryParam('fam');
    const [query, setQuery] = useState('');
    const [pack, setPack] = useState(props.pack ?? 'all');
    const now = Date.now();
    const ourHotkeys = new Set(runs.filter((r) => r.ours).map((r) => r.hotkey));
    const ourWeight = Object.entries(board?.weights ?? {}).reduce((s, [h, w]) => s + (ourHotkeys.has(h) ? w : 0), 0);
    const openFamily = (key: string | null | undefined) => {
        if (key) {
            setFam(key);
            setTab('family');
        }
    };
    const packs = useMemo(() => Array.from(new Set(runs.map((r) => r.pack ?? 'unknown'))).sort().reverse(), [runs]);
    const q = query.trim().toLowerCase();
    const match = (r: RunRow) => !q || [r.run, r.hotkey, r.coldkey ?? '', r.family ?? '', r.state].some((x) => x.toLowerCase().includes(q));
    const runRows = runs.filter((r) => (pack === 'all' || (r.pack ?? 'unknown') === pack) && match(r));
    const famRow = families.find((f) => f.key === fam);
    const famRuns = runs.filter((r) => r.family === fam);

    const nowSort = useSort('now', runsNow, {
        run: { value: (r) => r.run }, family: { value: (r) => famLabel(r.family, r.ours) }, state: { value: (r) => r.queue_state ?? r.state },
        progress: { value: (r) => (r.assigned ? (r.executed ?? 0) / r.assigned : null), first: 'desc' }, ungraded: { value: (r) => r.ungraded, first: 'desc' },
        started: { value: (r) => r.started_at }, enqueued: { value: (r) => r.enqueued_at },
    });
    const decSort = useSort('decisions', decisions, {
        finished: { value: (r) => r.finished_at, first: 'desc' }, run: { value: (r) => r.run }, family: { value: (r) => famLabel(r.family, r.ours) },
        nv: { value: (r) => r.nv, first: 'desc' }, lb: { value: (r) => r.lb, first: 'desc' }, gates: { value: (r) => r.reasons.length },
        usd: { value: (r) => totalUsd(r.classes) },
    });
    const runSort = useSort('runs', runRows, RUN_COLUMNS);
    const famRunSort = useSort('family_runs', famRuns, RUN_COLUMNS);
    const famSort = useSort('families', families, {
        family: { value: (f) => famLabel(f.key, f.ours) }, runs: { value: (f) => f.runs, first: 'desc' }, crowns: { value: (f) => f.crowns, first: 'desc' },
        current: { value: (f) => f.current_runs, first: 'desc' }, best: { value: (f) => f.current_best_nv, first: 'desc' }, lb: { value: (f) => f.current_best_lb, first: 'desc' },
        king: { value: (f) => f.seat_hours.current ?? 0, first: 'desc' }, incentive: { value: (f) => f.incentive, first: 'desc' }, last: { value: (f) => f.last_enqueue, first: 'desc' },
    });
    const dsSort = useSort('datasets', datasets, {
        dataset: { value: (d) => d.dataset }, median: { value: (d) => d.median, first: 'desc' }, best: { value: (d) => d.best?.score, first: 'desc' },
        gap: { value: (d) => (d.best ? d.best.score - d.median : null), first: 'desc' },
    });

    return (
        <>
            <Head title="Yugos SN34" />
            <div className={PAGE}>
                <PageHero
                    tone="amber"
                    eyebrow="Yugos | subnet 34"
                    title="Competition"
                    subtitle={`${props.pack ?? 'no pack'} | policy ${board?.policy?.slice(0, 8) ?? '-'} | ${board?.phase ?? '?'}${board?.paused ? ' | paused' : ''} | board ${utc(board?.confirmed_at, true)} UTC`}
                    right={
                        <>
                            <HeroStat label="king NV" icon={Crown} value={signed(kings[0]?.nv)} note={`${famLabel(kings[0]?.family, kings[0]?.ours)} | ${kings[0]?.run ?? '-'} | LB ${signed(kings[0]?.lb)}`} />
                            <HeroStat label="our weight" value={ourWeight.toFixed(2)} note={ourWeight > 0 ? 'in a seat' : 'no seat'} />
                        </>
                    }
                >
                    {Object.entries(reads).map(([k, v]) => (
                        <HeroChip key={k} warn={stale(k, v, now)}>
                            {k} read {ago(v, now)}
                        </HeroChip>
                    ))}
                </PageHero>

                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <Kpi icon={Crown} title="Seats" tone="amber">
                        {kings.length === 0 && <p className="text-sm text-muted-foreground">empty throne</p>}
                        {kings.map((k) => (
                            <div key={k.seat} className="flex w-full items-center gap-2 py-0.5 text-sm">
                                <SeatLink>
                                    <span className="w-16 text-xs text-muted-foreground">{k.seat}</span>
                                </SeatLink>
                                <FamilyCell fam={k.family} ours={k.ours} onOpen={openFamily} />
                                <HotkeyLink hotkey={k.hotkey} />
                                <span className="ml-auto font-mono tabular-nums">{k.share ?? '-'}</span>
                            </div>
                        ))}
                        <div className="mt-2 text-xs text-muted-foreground">chain incentive {kings.map((k) => (k.incentive ?? 0).toFixed(3)).join(' | ')}</div>
                    </Kpi>
                    <Kpi icon={Shield} title="Floors (from the king)" tone="sky">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-xs text-muted-foreground">
                                    <th className="text-left font-normal">class</th>
                                    <th className="text-right font-normal">quality</th>
                                    <th className="text-right font-normal">hard</th>
                                    <th className="text-right font-normal">private</th>
                                </tr>
                            </thead>
                            <tbody>
                                {Object.entries(board?.floors ?? {}).map(([c, f]) => (
                                    <tr key={c}>
                                        <td>{CLASS_LABEL[c] ?? c}</td>
                                        <td className="text-right font-mono">{num(f.quality, 4)}</td>
                                        <td className="text-right font-mono">{num(f.hard, 4)}</td>
                                        <td className="text-right font-mono">{num(f.private, 4)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </Kpi>
                    <Kpi icon={Activity} title={`Runs now (${runsNow.length})`} tone="emerald">
                        <div className="flex flex-col gap-1.5">
                            {runsNow.slice(0, 6).map((r) => (
                                <div key={r.run} className="flex items-center gap-2 text-sm">
                                    <RunLink run={r.run} />
                                    <span className="flex w-28 shrink-0 items-center overflow-hidden text-xs">
                                        <FamilyCell fam={r.family} ours={r.ours} onOpen={openFamily} />
                                    </span>
                                    {r.queue_state === 'error' ? <Pill tone="rose">error</Pill> : <Progress r={r} />}
                                </div>
                            ))}
                        </div>
                    </Kpi>
                    <Kpi icon={Gavel} title="Latest decisions" tone="violet">
                        <div className="flex flex-col gap-1.5">
                            {decisions.slice(0, 5).map((d) => (
                                <div key={d.run} className="flex items-center gap-2 text-sm">
                                    <RunLink run={d.run} />
                                    <span className="flex w-28 shrink-0 items-center overflow-hidden text-xs">
                                        <FamilyCell fam={d.family} ours={d.ours} onOpen={openFamily} />
                                    </span>
                                    <VerdictBadge r={d} near={d.near_miss} />
                                    <span className={cn('ml-auto font-mono tabular-nums', scoreTone(d.nv))}>{signed(d.nv)}</span>
                                </div>
                            ))}
                        </div>
                    </Kpi>
                </div>

                <Section icon={KeyRound} title="Our registered hotkeys" hint={`netuid 34 | ${props.our_hotkeys.uids} uids, ${props.our_hotkeys.non_immune} not immune | metagraph read ${ago(props.our_hotkeys.read_at, now)}`}>
                    <div className={cn('overflow-x-auto', TABLE_FRAME)}>
                        <Table className="text-sm">
                            <TableHeader>
                                <TableRow>
                                    <TableHead>wallet / hotkey</TableHead>
                                    <TableHead>hotkey</TableHead>
                                    <TableHead className="text-right">uid</TableHead>
                                    <TableHead className="text-right">incentive</TableHead>
                                    <TableHead className="text-right">alpha / day</TableHead>
                                    <TableHead className="text-right">TAO / day</TableHead>
                                    <TableHead>registered (UTC)</TableHead>
                                    <TableHead>immunity</TableHead>
                                    <TableHead>deregistration place</TableHead>
                                    <TableHead>newest run</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {props.our_hotkeys.rows.map((h) => (
                                    <TableRow key={h.hotkey} className="bg-indigo-500/5">
                                        <TableCell className="font-medium">{h.name ?? '-'}</TableCell>
                                        <TableCell>{h.registered ? <HotkeyLink hotkey={h.hotkey} /> : <span className="font-mono text-xs">{h.hotkey}</span>}</TableCell>
                                        <TableCell className="text-right font-mono">{h.uid ?? '-'}</TableCell>
                                        <TableCell className="text-right font-mono">{num(h.incentive, 4)}</TableCell>
                                        <TableCell className="text-right font-mono">{num(h.daily_alpha, 3)}</TableCell>
                                        <TableCell className="text-right font-mono">{num(h.daily_tao, 4)}</TableCell>
                                        <TableCell className="font-mono text-muted-foreground">
                                            {h.registered ? `${utc(h.registered_at)} (${ago(h.registered_at, now)})` : <Pill tone="rose">not registered</Pill>}
                                        </TableCell>
                                        <TableCell>{h.immune ? <Pill tone="sky">immune {h.immunity_left_hours ?? '?'} h left</Pill> : h.registered ? <Pill>none</Pill> : '-'}</TableCell>
                                        <TableCell>
                                            {h.place !== null ? (
                                                <span className="inline-flex items-center gap-1.5">
                                                    <span className="font-mono">
                                                        #{h.place} / {props.our_hotkeys.non_immune}
                                                    </span>
                                                    {h.in_danger && <Pill tone="rose">in danger</Pill>}
                                                </span>
                                            ) : (
                                                '-'
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {h.last_run ? (
                                                <span className="inline-flex items-center gap-1.5">
                                                    <RunLink run={h.last_run.run} />
                                                    <StateBadge state={h.last_run.state} />
                                                    {h.last_run.nv !== null && <span className={cn('font-mono text-xs', scoreTone(h.last_run.nv))}>NV {signed(h.last_run.nv)}</span>}
                                                </span>
                                            ) : (
                                                '-'
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        deregistration place: uids that are not immune, lowest emission first, then the earliest registration (our reading of the chain rule, as on the SOMA Hotkeys page); immunity {props.our_hotkeys.immunity_period} blocks x 12 s.
                    </p>
                </Section>

                <Tabs value={tab} onValueChange={setTab} className="min-h-0 flex-1 gap-3">
                    <TabsList className={TABS_LIST}>
                        <TabTrigger value="now" label="Runs now" icon={Activity} n={runsNow.length} />
                        <TabTrigger value="decisions" label="Decisions" icon={Gavel} n={decisions.length} />
                        <TabTrigger value="runs" label="All runs" icon={ListChecks} n={runs.length} />
                        <TabTrigger value="families" label="Families" icon={Users} n={families.length} />
                        {fam && <TabTrigger value="family" label={`Family | ${famLabel(fam, famRow?.ours)}`} icon={UserRound} n={famRuns.length} />}
                        <TabTrigger value="seats" label="Seat history" icon={Crown} n={seats.length} />
                        <TabTrigger value="datasets" label="Datasets" icon={Database} n={datasets.length} />
                        <TabTrigger value="card" label="Card (our routes)" icon={IdCard} n={cards.length} />
                        <TabTrigger value="events" label="Events" icon={Bell} n={events.length} />
                        <TabTrigger value="watch" label="Watch log" icon={BookOpen} n={notes.watch_rows.length} />
                        <TabTrigger value="discord" label="Discord" icon={MessageSquare} n={notes.discord_rows.length} />
                    </TabsList>

                    <TabsContent value="now" className="flex min-h-0 flex-col gap-2">
                        <Scroll>
                            <Table className="text-sm">
                                <Head2>
                                    <SortHead id="run" label="run" {...by(nowSort)} />
                                    <TableHead>hotkey</TableHead>
                                    <SortHead id="family" label="family" {...by(nowSort)} />
                                    <SortHead id="state" label="state" {...by(nowSort)} />
                                    <SortHead id="progress" label="executed / assigned" {...by(nowSort)} />
                                    <TableHead className="text-right">graded</TableHead>
                                    <SortHead id="ungraded" label="ungraded" {...by(nowSort)} className="text-right" />
                                    <TableHead className="text-right">2nd attempts</TableHead>
                                    <SortHead id="enqueued" label="enqueued" {...by(nowSort)} />
                                    <SortHead id="started" label="started" {...by(nowSort)} />
                                </Head2>
                                <TableBody>
                                    {nowSort.sorted.map((r) => (
                                        <TableRow key={r.run} className={cn(r.ours && 'bg-indigo-500/10')}>
                                            <TableCell>
                                                <RunLink run={r.run} />
                                            </TableCell>
                                            <TableCell>
                                                <HotkeyLink hotkey={r.hotkey} />
                                            </TableCell>
                                            <TableCell>
                                                <FamilyCell fam={r.family} ours={r.ours} onOpen={openFamily} />
                                            </TableCell>
                                            <TableCell>
                                                <StateBadge state={r.queue_state === 'error' ? 'error' : r.state} />
                                                {r.queue_reason && <div className="text-xs text-muted-foreground">{r.queue_reason}</div>}
                                            </TableCell>
                                            <TableCell>
                                                <Progress r={r} />
                                            </TableCell>
                                            <TableCell className="text-right font-mono">{r.graded ?? '-'}</TableCell>
                                            <TableCell className={cn('text-right font-mono', (r.ungraded ?? 0) > 0 && 'text-amber-600')}>{r.ungraded ?? '-'}</TableCell>
                                            <TableCell className="text-right font-mono">{r.second_attempts || '-'}</TableCell>
                                            <TableCell className="font-mono whitespace-nowrap text-muted-foreground">{utc(r.enqueued_at)}</TableCell>
                                            <TableCell className="font-mono whitespace-nowrap text-muted-foreground">{utc(r.started_at)}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </Scroll>
                        <p className="text-xs text-muted-foreground">
                            failed / error is a state now, not the end: on this host a run left with ungraded items gets a second attempt (a new started_at) or turns completed later with them counted as failures.
                        </p>
                    </TabsContent>

                    <TabsContent value="decisions" className="flex min-h-0 flex-col gap-2">
                        <Scroll>
                            <Table className="text-sm">
                                <Head2>
                                    <SortHead id="finished" label="run finished (UTC)" {...by(decSort)} />
                                    <SortHead id="run" label="run" {...by(decSort)} />
                                    <SortHead id="family" label="family" {...by(decSort)} />
                                    <TableHead>verdict</TableHead>
                                    <SortHead id="nv" label="NV" {...by(decSort)} className="text-right" />
                                    <SortHead id="lb" label="LB" {...by(decSort)} className="text-right" />
                                    <SortHead id="gates" label="failed gates" {...by(decSort)} />
                                    {['agentic', 'chat', 'math'].map((c) => (
                                        <TableHead key={c} className="text-right">
                                            {c} q | hard | $ | NV
                                        </TableHead>
                                    ))}
                                    <SortHead id="usd" label="total $" {...by(decSort)} className="text-right" />
                                </Head2>
                                <TableBody>
                                    {decSort.sorted.map((d) => (
                                        <TableRow key={d.run} className={cn(d.ours && 'bg-indigo-500/10')}>
                                            <TableCell className="font-mono whitespace-nowrap text-muted-foreground">{utc(d.finished_at)}</TableCell>
                                            <TableCell>
                                                <RunLink run={d.run} />
                                            </TableCell>
                                            <TableCell>
                                                <FamilyCell fam={d.family} ours={d.ours} onOpen={openFamily} />
                                            </TableCell>
                                            <TableCell>
                                                <VerdictBadge r={d} near={d.near_miss} />
                                            </TableCell>
                                            <TableCell className={cn('text-right font-mono', scoreTone(d.nv))}>{signed(d.nv)}</TableCell>
                                            <TableCell className="text-right font-mono">{signed(d.lb)}</TableCell>
                                            <TableCell className="max-w-[24rem] min-w-[16rem] text-xs whitespace-normal">{d.reasons.join('; ') || '-'}</TableCell>
                                            {(['agentic', 'chat_qa', 'math_code'] as const).map((c) => (
                                                <TableCell key={c} className="min-w-[15rem] text-right font-mono text-xs whitespace-nowrap">
                                                    <ClassCell c={d.classes[c]} />
                                                </TableCell>
                                            ))}
                                            <TableCell className="text-right font-mono">{usd(totalUsd(d.classes))}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </Scroll>
                    </TabsContent>

                    <TabsContent value="runs" className="flex min-h-0 flex-col gap-2">
                        <div className="flex flex-wrap items-center gap-2">
                            <Input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="run, hotkey, family, state" className="h-9 w-[260px]" />
                            <Select value={pack} onValueChange={setPack}>
                                <SelectTrigger className="h-9 w-[220px]">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">every pack</SelectItem>
                                    {packs.map((p) => (
                                        <SelectItem key={p} value={p}>
                                            {p}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <span className="text-sm text-muted-foreground">
                                {runRows.length} runs
                                {props.runs_omitted > 0 && ` | ${props.runs_omitted} older runs not sent (the family counts include them)`}
                            </span>
                        </div>
                        <RunsTable sort={by(runSort)} rows={runSort.sorted} onFamily={openFamily} />
                    </TabsContent>

                    <TabsContent value="families" className="flex min-h-0 flex-col gap-2">
                        <Scroll>
                            <Table className="text-sm">
                                <Head2>
                                    <SortHead id="family" label="family (coldkey)" {...by(famSort)} />
                                    <TableHead>hotkeys</TableHead>
                                    <SortHead id="runs" label="runs (all packs)" {...by(famSort)} className="text-right" />
                                    <SortHead id="crowns" label="crowns" {...by(famSort)} className="text-right" />
                                    <SortHead id="current" label="this pack runs" {...by(famSort)} className="text-right" />
                                    <TableHead className="text-right">eligible</TableHead>
                                    <SortHead id="best" label="best NV" {...by(famSort)} className="text-right" />
                                    <SortHead id="lb" label="best LB" {...by(famSort)} className="text-right" />
                                    <SortHead id="king" label="king h | prev | two_back" {...by(famSort)} className="text-right" />
                                    <SortHead id="incentive" label="incentive now" {...by(famSort)} className="text-right" />
                                    <SortHead id="last" label="last submit" {...by(famSort)} />
                                    <TableHead>running</TableHead>
                                </Head2>
                                <TableBody>
                                    {famSort.sorted.map((f) => (
                                        <TableRow key={f.key} className={cn(f.ours && 'bg-indigo-500/10')}>
                                            <TableCell>
                                                <FamilyCell fam={f.key} ours={f.ours} onOpen={openFamily} />
                                                {f.coldkeys.length > 1 && <span className="text-xs text-muted-foreground"> +{f.coldkeys.length - 1}</span>}
                                            </TableCell>
                                            <TableCell className="text-xs">
                                                <span className="flex flex-wrap gap-x-2">
                                                    {f.hotkeys.map((h) => (
                                                        <HotkeyLink key={h} hotkey={h} />
                                                    ))}
                                                </span>
                                            </TableCell>
                                            <TableCell className="text-right font-mono">{f.runs}</TableCell>
                                            <TableCell className="text-right font-mono">{f.crowns || '-'}</TableCell>
                                            <TableCell className="text-right font-mono">{f.current_runs || '-'}</TableCell>
                                            <TableCell className="text-right font-mono">{f.current_eligible || '-'}</TableCell>
                                            <TableCell className="text-right font-mono">{signed(f.current_best_nv)}</TableCell>
                                            <TableCell className="text-right font-mono">{signed(f.current_best_lb)}</TableCell>
                                            <TableCell className="text-right font-mono text-xs">
                                                {(f.seat_hours.current ?? 0).toFixed(0)} | {(f.seat_hours.previous ?? 0).toFixed(0)} | {(f.seat_hours.two_back ?? 0).toFixed(0)}
                                            </TableCell>
                                            <TableCell className="text-right font-mono">{f.incentive ? f.incentive.toFixed(3) : '-'}</TableCell>
                                            <TableCell className="font-mono text-muted-foreground">{utc(f.last_enqueue)}</TableCell>
                                            <TableCell className="font-mono text-xs">{f.running.join(' ') || '-'}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </Scroll>
                        <EvidenceBox evidence={props.evidence} notes={notes.family_notes} />
                    </TabsContent>

                    <TabsContent value="family" className="flex min-h-0 flex-col gap-2">
                        {famRow && (
                            <div className="flex flex-col gap-2 rounded-lg border p-3 text-sm">
                                <div className="flex flex-wrap gap-x-6 gap-y-1">
                                    <span>
                                        coldkeys{' '}
                                        {famRow.coldkeys.map((c) => (
                                            <a key={c} href={`${TAOSTATS}/account/${c}`} {...EXT} className="mr-2 font-mono hover:underline">
                                                {c}
                                            </a>
                                        ))}
                                    </span>
                                    <span>
                                        hotkeys{' '}
                                        {famRow.hotkeys.map((h) => (
                                            <span key={h} className="mr-2">
                                                <HotkeyLink hotkey={h} />
                                            </span>
                                        ))}
                                    </span>
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    {Object.entries(famRow.packs)
                                        .sort()
                                        .reverse()
                                        .map(([p, s]) => (
                                            <Badge key={p} variant="outline" className="font-normal">
                                                {p.replace('competition-', '')}: {s.runs} runs | judged {s.judged} | eligible {s.eligible} | crowns {s.crowns} | best {signed(s.best_nv, 2)}
                                            </Badge>
                                        ))}
                                </div>
                                <FamilyDatasets fam={famRow.key} datasets={datasets} />
                            </div>
                        )}
                        <RunsTable sort={by(famRunSort)} rows={famRunSort.sorted} onFamily={openFamily} />
                    </TabsContent>

                    <TabsContent value="seats" className="flex min-h-0 flex-col gap-2">
                        <Scroll>
                            <Table className="text-sm">
                                <Head2>
                                    <TableHead>from (UTC)</TableHead>
                                    <TableHead>to</TableHead>
                                    <TableHead className="text-right">hours</TableHead>
                                    <TableHead>policy</TableHead>
                                    <TableHead>current</TableHead>
                                    <TableHead>previous</TableHead>
                                    <TableHead>two_back</TableHead>
                                </Head2>
                                <TableBody>
                                    {seats.map((s) => (
                                        <TableRow key={s.from}>
                                            <TableCell className="font-mono">{utc(s.from)}</TableCell>
                                            <TableCell className="font-mono text-muted-foreground">{s.open ? 'now' : utc(s.to)}</TableCell>
                                            <TableCell className="text-right font-mono">{s.hours.toFixed(1)}</TableCell>
                                            <TableCell className="font-mono text-xs text-muted-foreground">{s.policy?.slice(0, 8) ?? '-'}</TableCell>
                                            {['current', 'previous', 'two_back'].map((seat) => {
                                                const x = s.seats.find((y) => y.seat === seat);

                                                return (
                                                    <TableCell key={seat}>
                                                        {x ? (
                                                            <span className="inline-flex items-center gap-1.5">
                                                                <FamilyCell fam={x.family} onOpen={openFamily} />
                                                                <SeatLink>
                                                                    <span className="font-mono text-xs text-muted-foreground">
                                                                        {x.run} {x.share}
                                                                    </span>
                                                                </SeatLink>
                                                            </span>
                                                        ) : (
                                                            <span className="text-muted-foreground">-</span>
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

                    <TabsContent value="datasets" className="flex min-h-0 flex-col gap-2">
                        <Scroll>
                            <Table className="text-sm">
                                <Head2>
                                    <SortHead id="dataset" label="dataset" {...by(dsSort)} />
                                    <TableHead className="text-right">items</TableHead>
                                    <TableHead className="text-right">runs</TableHead>
                                    <SortHead id="median" label="median" {...by(dsSort)} className="text-right" />
                                    <SortHead id="best" label="best" {...by(dsSort)} className="text-right" />
                                    <SortHead id="gap" label="best - median" {...by(dsSort)} className="text-right" />
                                    <TableHead>best run</TableHead>
                                </Head2>
                                <TableBody>
                                    {dsSort.sorted.map((d) => (
                                        <TableRow key={d.dataset}>
                                            <TableCell>{d.dataset}</TableCell>
                                            <TableCell className="text-right font-mono">{d.items}</TableCell>
                                            <TableCell className="text-right font-mono">{d.runs}</TableCell>
                                            <TableCell className="text-right font-mono">{num(d.median)}</TableCell>
                                            <TableCell className="text-right font-mono">{num(d.best?.score)}</TableCell>
                                            <TableCell className="text-right font-mono">{d.best ? num(d.best.score - d.median) : '-'}</TableCell>
                                            <TableCell>
                                                {d.best && (
                                                    <span className="inline-flex items-center gap-1.5">
                                                        <RunLink run={d.best.run} />
                                                        <FamilyCell fam={d.best.family} onOpen={openFamily} />
                                                    </span>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </Scroll>
                    </TabsContent>

                    <TabsContent value="card" className="flex min-h-0 flex-col gap-2">
                        <Scroll>
                            <Table className="text-xs">
                                <Head2>
                                    <TableHead>card synced (UTC)</TableHead>
                                    <TableHead>sha</TableHead>
                                    {(cards[0]?.choices ?? []).map((c) => (
                                        <TableHead key={c.choice} className="whitespace-nowrap">
                                            {c.choice.replace(/^gm\//, '')}
                                        </TableHead>
                                    ))}
                                </Head2>
                                <TableBody>
                                    {cards.map((card, i) => (
                                        <TableRow key={card.sha}>
                                            <TableCell className="font-mono">{utc(card.synced_at)}</TableCell>
                                            <TableCell className="font-mono text-muted-foreground">{card.sha}</TableCell>
                                            {card.choices.map((c, j) => {
                                                const prev = cards[i + 1]?.choices[j];
                                                const moved = prev && (prev.present !== c.present || prev.format !== c.format);
                                                const priced = prev && (prev.in !== c.in || prev.out !== c.out);

                                                return (
                                                    <TableCell key={c.choice} className={cn('font-mono', !c.present && 'bg-red-500/15 text-red-600', moved && 'ring-2 ring-amber-500 ring-inset')} title={`in ${c.in ?? '-'} / out ${c.out ?? '-'} per M`}>
                                                        {c.present ? c.format : 'absent'}
                                                        {priced && <span className="text-amber-600"> $</span>}
                                                    </TableCell>
                                                );
                                            })}
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </Scroll>
                        <p className="text-xs text-muted-foreground">our 13 hosted route choices on every stored card: red = missing, amber ring = presence or api format moved, $ = price moved (hover for prices).</p>
                    </TabsContent>

                    <TabsContent value="events" className="flex min-h-0 flex-col gap-2">
                        <Scroll>
                            <Table className="text-sm">
                                <Head2>
                                    <TableHead>time (UTC)</TableHead>
                                    <TableHead>type</TableHead>
                                    <TableHead>run</TableHead>
                                    <TableHead>family</TableHead>
                                    <TableHead>detail</TableHead>
                                </Head2>
                                <TableBody>
                                    {events.map((e) => (
                                        <TableRow key={e.id}>
                                            <TableCell className="font-mono whitespace-nowrap text-muted-foreground">{utc(e.at, true)}</TableCell>
                                            <TableCell>
                                                <Badge variant="outline" className={cn('font-normal', e.type === 'seats' && 'border-amber-500', e.type === 'verdict' && e.data.near_miss ? 'border-red-500' : '')}>
                                                    {e.type}
                                                </Badge>
                                            </TableCell>
                                            <TableCell>{e.run ? <RunLink run={e.run} /> : '-'}</TableCell>
                                            <TableCell>
                                                <FamilyCell fam={e.family} onOpen={openFamily} />
                                            </TableCell>
                                            <TableCell className="max-w-[640px] truncate font-mono text-xs text-muted-foreground" title={JSON.stringify(e.data)}>
                                                {JSON.stringify(e.data)}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </Scroll>
                    </TabsContent>

                    <TabsContent value="watch" className="flex min-h-0 flex-col gap-2">
                        <NotesTable rows={notes.watch_rows} heads={['when (UTC)', 'reads', 'what happened', 'alerts']} note="Written by this dashboard from its own reads (board every 2 min, runs every minute, world card every 10 min): one row per minute in which something changed, newest first" />
                    </TabsContent>

                    <TabsContent value="discord" className="flex min-h-0 flex-col gap-2">
                        <NotesTable rows={notes.discord_rows} heads={['when (UTC)', 'author', 'message']} note={`#34 mind (the Yugos channel of the Bittensor Discord), read by this dashboard at random 10 - 30 minute intervals (last read ${utc(notes.discord_read_at ?? null, true)}, next ${utc(notes.discord_next_at ?? null, true)}): the newest ${notes.discord_rows.length} of ${notes.discord_count ?? notes.discord_rows.length} messages, as written (newest ${utc(notes.discord_newest ?? null, true)})`} />
                    </TabsContent>
                </Tabs>
            </div>
        </>
    );
}

Sn34Overview.layout = {
    breadcrumbs: [{ title: 'SN34 Yugos', href: sn34Overview() }],
};

function TabTrigger({ value, label, icon: Icon, n }: { value: string; label: string; icon: LucideIcon; n?: number }) {
    return (
        <TabsTrigger value={value} className={TABS_TRIGGER}>
            <Icon className="size-4" />
            {label}
            {n !== undefined && <span className="rounded bg-muted-foreground/15 px-1.5 text-xs">{n}</span>}
        </TabsTrigger>
    );
}

/** One table per tab, scrolling both ways with a sticky header (same frame as the SOMA pages). */
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

function Progress({ r }: { r: NowRow }) {
    const pct = r.assigned ? Math.round((100 * (r.executed ?? 0)) / r.assigned) : null;

    return (
        <div className="flex min-w-[140px] items-center gap-2">
            <div className="h-2 flex-1 overflow-hidden rounded bg-muted">
                <div className={cn('h-full', (r.ungraded ?? 0) > 0 ? 'bg-amber-500' : 'bg-emerald-500')} style={{ width: `${pct ?? 0}%` }} />
            </div>
            <span className="font-mono text-xs tabular-nums">
                {r.executed ?? '-'}/{r.assigned ?? '-'}
            </span>
        </div>
    );
}

function StateBadge({ state }: { state: string }) {
    const tone: Record<string, 'sky' | 'zinc' | 'emerald' | 'amber' | 'rose'> = { running: 'sky', queued: 'zinc', completed: 'emerald', failed: 'amber', error: 'rose', rejected: 'zinc' };

    return <Pill tone={tone[state] ?? 'zinc'}>{state}</Pill>;
}

function VerdictBadge({ r, near }: { r: RunRow; near?: boolean }) {
    if (r.promoted) {
        return (
            <Pill tone="amber">
                <Crown className="size-3" /> king
            </Pill>
        );
    }

    if (r.eligible) {
        return <Pill tone="emerald">eligible</Pill>;
    }

    if (r.eligible === false) {
        return <Pill tone={near ? 'rose' : 'zinc'}>{near ? 'near miss' : `${r.reasons.length} gates`}</Pill>;
    }

    return <span className="text-muted-foreground">-</span>;
}

function ClassCell({ c }: { c?: ClassRow }) {
    if (!c) {
        return <>-</>;
    }

    return (
        <span className={cn(c.fails?.length ? 'text-red-600' : '')}>
            {num(c.q, 3)} | {num(c.hard, 3)} | {usd(c.usd)} | {signed(c.nv, 2)}
        </span>
    );
}

type RunSort = { sort: SortState; onSort: (key: string) => void };

function RunsTable({ sort, rows, onFamily }: { sort: RunSort; rows: RunRow[]; onFamily: (key: string | null | undefined) => void }) {
    return (
        <Scroll>
            <Table className="text-sm">
                <Head2>
                    <SortHead id="run" label="run" {...sort} className="sticky left-0 z-20 bg-muted" />
                    <SortHead id="enqueued" label="enqueued (UTC)" {...sort} className="whitespace-nowrap" />
                    <TableHead>hotkey</TableHead>
                    <SortHead id="family" label="family" {...sort} />
                    <TableHead>pack</TableHead>
                    <SortHead id="state" label="state" {...sort} />
                    <TableHead>verdict</TableHead>
                    <SortHead id="nv" label="NV" {...sort} className="text-right" />
                    <SortHead id="lb" label="LB" {...sort} className="text-right" />
                    {['agentic', 'chat', 'math'].map((c) => (
                        <TableHead key={c} className="text-right">
                            {c} q | hard | $ | NV
                        </TableHead>
                    ))}
                    <SortHead id="usd" label="total $" {...sort} className="text-right" />
                    <TableHead>bundle</TableHead>
                </Head2>
                <TableBody>
                    {rows.map((r) => (
                        <TableRow key={r.run_id} className={cn(r.ours && 'bg-indigo-500/10')}>
                            <TableCell className="sticky left-0 z-[1] bg-card">
                                <RunLink run={r.run} />
                            </TableCell>
                            <TableCell className="font-mono whitespace-nowrap text-muted-foreground">{utc(r.enqueued_at)}</TableCell>
                            <TableCell>
                                <HotkeyLink hotkey={r.hotkey} />
                            </TableCell>
                            <TableCell>
                                <FamilyCell fam={r.family} ours={r.ours} onOpen={onFamily} />
                            </TableCell>
                            <TableCell className="text-xs text-muted-foreground">{r.pack?.replace('competition-', '') ?? '-'}</TableCell>
                            <TableCell>
                                <StateBadge state={r.state} />
                            </TableCell>
                            <TableCell>
                                <VerdictBadge r={r} near={r.eligible === false && r.reasons.length === 1} />
                            </TableCell>
                            <TableCell className={cn('text-right font-mono', scoreTone(r.nv))}>{signed(r.nv)}</TableCell>
                            <TableCell className="text-right font-mono">{signed(r.lb)}</TableCell>
                            {(['agentic', 'chat_qa', 'math_code'] as const).map((c) => (
                                <TableCell key={c} className="min-w-[15rem] text-right font-mono text-xs whitespace-nowrap">
                                    <ClassCell c={r.classes[c]} />
                                </TableCell>
                            ))}
                            <TableCell className="text-right font-mono">{Object.keys(r.classes).length ? usd(totalUsd(r.classes)) : '-'}</TableCell>
                            <TableCell className="font-mono text-xs text-muted-foreground">{r.bundle}</TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </Scroll>
    );
}

function FamilyDatasets({ fam, datasets }: { fam: string; datasets: DatasetRow[] }) {
    const rows = datasets.filter((d) => d.by_family[fam] !== undefined).sort((a, b) => b.by_family[fam] - a.by_family[fam]);

    if (rows.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-wrap gap-1 text-xs">
            <span className="text-muted-foreground">this pack, mean minus field median (answers):</span>
            {rows.map((d) => (
                <Badge key={d.dataset} variant="outline" className={cn('font-normal', d.by_family[fam] > 0 ? 'border-emerald-500 text-emerald-700 dark:text-emerald-300' : d.by_family[fam] < 0 ? 'border-red-500 text-red-600' : '')}>
                    {d.dataset.split('/').pop() === 'private' ? d.dataset : d.dataset.split('/').pop()} {d.by_family[fam] > 0 ? '+' : ''}
                    {d.by_family[fam]}
                </Badge>
            ))}
        </div>
    );
}

function EvidenceBox({ evidence, notes }: { evidence: Props['evidence']; notes: Notes['family_notes'] }) {
    return (
        <details className={cn('p-3 text-xs', TABLE_FRAME)}>
            <summary className="cursor-pointer font-medium">
                Links between families ({evidence.direct.length} direct transfers, {evidence.shared.length} shared wallets, {notes.length} notes)
            </summary>
            <div className="text-muted-foreground">
                direct transfers {evidence.direct.length} | shared personal / medium wallets {evidence.shared.length}. No money link = link unconfirmed, never "different operators".
            </div>
            {evidence.direct.map((d, i) => (
                <div key={`d${i}`} className="font-mono">
                    {utc(d.at)} {d.from.slice(0, 8)} -&gt; {d.to.slice(0, 8)} {d.tao.toFixed(4)} TAO
                </div>
            ))}
            {evidence.shared.map((w) => (
                <div key={w.address} className="font-mono">
                    {w.address.slice(0, 8)} ({w.kind ?? '?'}, {w.transfers ?? '?'} transfers): {w.owners.map((o) => o.slice(0, 8)).join(', ')}
                </div>
            ))}
            {notes.map((n, i) => (
                <div key={`n${i}`} className="mt-1">
                    <Badge variant="outline" className="font-normal">
                        {n.kind} | {n.strength} | {n.verdict}
                    </Badge>{' '}
                    {n.text}
                </div>
            ))}
        </details>
    );
}

function NotesTable({ rows, heads, note }: { rows: string[][]; heads: string[]; note: string }) {
    const [open, setOpen] = useState<Record<number, boolean>>({});

    return (
        <>
            <Scroll>
                <Table className="text-sm">
                    <Head2>
                        {heads.map((h) => (
                            <TableHead key={h}>{h}</TableHead>
                        ))}
                    </Head2>
                    <TableBody>
                        {rows.map((r, i) => (
                            <TableRow key={i} className="cursor-pointer align-top" onClick={() => setOpen((o) => ({ ...o, [i]: !o[i] }))} title={open[i] ? 'click to fold' : 'click to show the whole row'}>
                                {heads.map((_, j) => (
                                    <TableCell key={j} className={cn('whitespace-normal', j === 0 ? 'w-[180px] min-w-[180px] font-mono text-xs' : 'max-w-[760px] min-w-[200px] text-xs')}>
                                        <div className={cn(!open[i] && 'line-clamp-3')}>{r[j] ?? ''}</div>
                                    </TableCell>
                                ))}
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </Scroll>
            <p className="text-xs text-muted-foreground">{note} - long rows show three lines; click a row to show all of it.</p>
        </>
    );
}

/**
 * Official pages in a new tab. The Yugos site has no page per run or per hotkey (its only URL parameter is ?tab=, read
 * from its client bundle 2026-11-25), so a run opens the official submissions list and a seat the official overview;
 * hotkeys and coldkeys open Taostats.
 */
function RunLink({ run }: { run: string }) {
    return (
        <a href={`${YUGOS}/?tab=submissions`} {...EXT} className="font-mono hover:underline" title={`run ${run}: the official submissions list (no per-run page)`}>
            {run}
        </a>
    );
}

function SeatLink({ children }: { children: ReactNode }) {
    return (
        <a href={`${YUGOS}/?tab=overview`} {...EXT} className="hover:underline" title="the official overview (seats)">
            {children}
        </a>
    );
}

function HotkeyLink({ hotkey }: { hotkey: string | null | undefined }) {
    if (!hotkey) {
        return <span className="text-muted-foreground">-</span>;
    }

    return (
        <a href={`${TAOSTATS}/hotkey/${hotkey}`} {...EXT} className="font-mono text-xs text-muted-foreground hover:text-foreground hover:underline" title={`${hotkey} on Taostats`}>
            {shortKey(hotkey)}
        </a>
    );
}

/** A family (its coldkey): the key opens Taostats in a new tab; the small button opens this page's family tab. */
function FamilyCell({ fam, ours = false, onOpen }: { fam: string | null | undefined; ours?: boolean; onOpen: (key: string | null | undefined) => void }) {
    if (!fam) {
        return <span className="text-muted-foreground">-</span>;
    }

    return (
        <span className="inline-flex min-w-0 items-center gap-1">
            <a href={`${TAOSTATS}/account/${fam}`} {...EXT} className={cn('max-w-[6rem] truncate font-mono hover:underline', ours && 'text-indigo-600 dark:text-indigo-300')} title={`${fam} on Taostats`}>
                {famLabel(fam, ours)}
            </a>
            <button type="button" onClick={() => onOpen(fam)} className="shrink-0 rounded p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground" title="open this family's tab here" aria-label="open family tab">
                <UserRound className="size-3.5" />
            </button>
        </span>
    );
}
