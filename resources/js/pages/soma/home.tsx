import { Head, Link, usePoll } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { Activity, ChartColumn, CircleCheck, Crown, ExternalLink, Flag, Gauge, Hourglass, KeyRound, LoaderCircle, RefreshCw, ShieldCheck, Sparkles, Timer, Trophy, Upload, Users } from 'lucide-react';
import { useMemo } from 'react';
import { HeroChip, Kpi, MoreLink, Pill, Section, StackBar, scoreTone, signed } from '@/components/soma/kit';
import { ago, shortKey, shortTask, utc } from '@/lib/soma';
import type { EntryState } from '@/lib/soma-state';
import { entryState, somaUrl, stage2Progress } from '@/lib/soma-state';
import { cn } from '@/lib/utils';
import { home as homeRoute, hotkeys as hotkeysRoute, miner as minerRoute, overview as boardRoute } from '@/routes/soma';
import type { CompetitionInfo, CutInfo, FamilySummary, MinerRow, Phase, TaskCell, TaskRef, WatcherInfo, WatchEvent } from '@/types';

/**
 * The first page (user 2026-11-25: "좀 멋있게 만들고 첫 페지에서 필요한 정보를 한눈에 볼수 있게 하라"): where the round
 * stands, where our entries are and who leads, in one screen. The full tables stay on the leaderboard page.
 *
 * Stage-2 scores of an entry that is still running or still being scored are partial (tasks with unscored runs count
 * as -1), so such an entry shows its progress, never its partial score, and ranks are taken among the entries whose runs
 * are all scored. Clicking an entry opens its page on thesoma.ai in a new tab; the chart icon opens our analysis page.
 */
type Props = {
    competition: CompetitionInfo;
    stage2_problems: TaskRef[];
    phase: Phase;
    cut: CutInfo;
    miners: MinerRow[];
    families: FamilySummary[];
    events: WatchEvent[];
    watcher: WatcherInfo;
    hotkeys: {
        taken_at: string | null;
        per_day_24h: number | null;
        per_day_7d: number | null;
        ours: {
            name: string;
            hotkey: string;
            uid: number | null;
            emission: number | null;
            immune: boolean;
            immune_hours_left: number | null;
            rank: number | null;
            rank_day_ago: number | null;
            eta_days_24h: number | null;
            eta_days_7d: number | null;
            age_days: number | null;
            label: string | null;
        }[];
    };
};

type State = EntryState;

// the shared stage-2 state (lib/soma-state.ts): done = every run scored; scoring = ran, results still coming in
const stateOf = (m: MinerRow): State => entryState(m);
const progressOf = (m: MinerRow): number => stage2Progress(m);

const sum = (xs: number[]) => xs.reduce((a, b) => a + b, 0);

/** Passes against the baseline's passes on the finished runs of an entry (1 = same quality). */
function qualityOf(m: MinerRow): { passed: number; base: number } {
    let passed = 0;
    let base = 0;
    Object.values(m.stage2 ?? {}).forEach((c) => {
        if (c.finished > 0 && c.passed !== null && c.baseline_passed !== null && c.baseline_total) {
            passed += c.passed;
            base += (c.baseline_passed / c.baseline_total) * c.finished;
        }
    });

    return { passed, base };
}

/** A task cell as a colour: green for a token saving, red for a loss, grey while it has not run. */
function cellTone(c: TaskCell | undefined): string {
    if (!c || !c.finished) {
        return 'bg-muted';
    }
    if (c.total && c.finished < c.total) {
        return 'bg-sky-400/50 dark:bg-sky-500/40';
    }
    const s = c.score ?? 0;
    if (s >= 0.25) return 'bg-emerald-500';
    if (s >= 0.15) return 'bg-emerald-400';
    if (s >= 0.05) return 'bg-emerald-300 dark:bg-emerald-600';
    if (s >= -0.05) return 'bg-zinc-300 dark:bg-zinc-600';
    if (s >= -0.25) return 'bg-rose-300 dark:bg-rose-700';

    return 'bg-rose-500';
}

function countdown(ms: number): string {
    if (ms <= 0) {
        return 'ended';
    }
    const d = Math.floor(ms / 86_400_000);
    const h = Math.floor((ms % 86_400_000) / 3_600_000);
    const m = Math.floor((ms % 3_600_000) / 60_000);

    return d > 0 ? `${d}d ${h}h` : h > 0 ? `${h}h ${m}m` : `${m}m`;
}

export default function Home(props: Props) {
    const { competition, stage2_problems: tasks, phase, miners, events, watcher, hotkeys } = props;
    usePoll(60_000, { only: ['miners', 'families', 'events', 'watcher', 'competition', 'stage2_problems', 'phase', 'cut', 'hotkeys'] });
    const now = Date.now();

    const v = useMemo(() => {
        const passers = miners.filter((m) => stateOf(m) !== 'out');
        const done = miners.filter((m) => stateOf(m) === 'done').sort((a, b) => (b.stage2_score ?? -9) - (a.stage2_score ?? -9));
        const rank = new Map(done.map((m, i) => [m.hotkey, i + 1]));
        const running = miners.filter((m) => stateOf(m) === 'running');
        const scoring = miners.filter((m) => stateOf(m) === 'scoring');
        const s1sorted = [...passers].sort((a, b) => (b.stage1_score ?? -9) - (a.stage1_score ?? -9));
        const s1rank = new Map(s1sorted.map((m, i) => [m.hotkey, i + 1]));
        const label = (m: MinerRow) => Number((m.our_label ?? 'S999').replace(/\D/g, '')) || 999;
        const ours = miners.filter((m) => m.is_ours);
        const order: Record<State, number> = { done: 0, scoring: 1, running: 2, waiting: 3, out: 4 };
        ours.sort((a, b) => order[stateOf(a)] - order[stateOf(b)] || (rank.get(a.hotkey) ?? 0) - (rank.get(b.hotkey) ?? 0) || label(a) - label(b));
        const scores = done.map((m) => m.stage2_score ?? 0);
        const median = scores.length ? [...scores].sort((a, b) => a - b)[Math.floor(scores.length / 2)] : null;
        const q = ours.map(qualityOf);

        // families by their best finished stage-2 entry
        const fam = new Map<string, { key: string; name: string; ours: boolean; best: MinerRow; entries: number; done: number }>();
        miners.forEach((m) => {
            const key = m.family ?? m.coldkey ?? m.hotkey;
            const f = fam.get(key) ?? { key, name: m.family_name ?? shortKey(m.coldkey ?? m.hotkey), ours: m.is_ours, best: m, entries: 0, done: 0 };
            f.entries += 1;
            f.ours ||= m.is_ours;
            if (stateOf(m) === 'done') {
                f.done += 1;
                if (stateOf(f.best) !== 'done' || (m.stage2_score ?? -9) > (f.best.stage2_score ?? -9)) {
                    f.best = m;
                }
            }
            fam.set(key, f);
        });
        const famRows = [...fam.values()].filter((f) => f.done > 0).sort((a, b) => (b.best.stage2_score ?? -9) - (a.best.stage2_score ?? -9));

        return { passers, done, rank, running, scoring, s1rank, ours, median, scores, famRows, quality: { passed: sum(q.map((x) => x.passed)), base: sum(q.map((x) => x.base)) } };
    }, [miners]);

    const best = v.ours.find((m) => stateOf(m) === 'done') ?? null;
    const counts = { done: 0, scoring: 0, running: 0, waiting: 0, out: 0 } as Record<State, number>;
    v.ours.forEach((m) => (counts[stateOf(m)] += 1));
    const end = competition.evaluation_end_at ? new Date(competition.evaluation_end_at).getTime() : null;
    const uploadEnd = competition.upload_deadline_at ? new Date(competition.upload_deadline_at).getTime() : null;
    const pending = Object.values(watcher.pending ?? {}).reduce((a, b) => a + b, 0);

    return (
        <>
            <Head title={`Round ${competition.round ?? competition.id}`} />
            <div className="mx-auto flex w-full max-w-[1600px] flex-col gap-5 p-4 md:p-6">
                <Hero competition={competition} phase={phase} now={now} end={end} uploadEnd={uploadEnd} done={v.done.length} running={v.running.length} scoring={v.scoring.length} passers={v.passers.length} entries={miners.length} watcher={watcher} pending={pending} />

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Kpi icon={Trophy} title="Our best now" tone="indigo">
                        {best ? (
                            <>
                                <div className="flex items-baseline gap-2">
                                    <span className="text-3xl font-semibold tabular-nums">{signed(best.stage2_score)}</span>
                                    <Pill tone="indigo">{best.our_label}</Pill>
                                </div>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    #{v.rank.get(best.hotkey)} of {v.done.length} fully scored · top {signed(v.done[0]?.stage2_score)} · median {signed(v.median)}
                                </p>
                            </>
                        ) : (
                            <p className="text-sm text-muted-foreground">no entry of ours has finished stage 2 yet</p>
                        )}
                    </Kpi>
                    <Kpi icon={Sparkles} title="Our entries" tone="violet">
                        <div className="flex items-baseline gap-2">
                            <span className="text-3xl font-semibold tabular-nums">{v.ours.length}</span>
                            <span className="text-sm text-muted-foreground">in round {competition.round}</span>
                        </div>
                        <StackBar
                            parts={[
                                { n: counts.done, cls: 'bg-emerald-500', label: 'done' },
                                { n: counts.scoring, cls: 'bg-amber-400', label: 'scoring' },
                                { n: counts.running, cls: 'bg-sky-500', label: 'running' },
                                { n: counts.waiting, cls: 'bg-zinc-300 dark:bg-zinc-600', label: 'waiting' },
                                { n: counts.out, cls: 'bg-rose-500', label: 'out' },
                            ]}
                        />
                    </Kpi>
                    <Kpi icon={Gauge} title="Stage 2 progress" tone="sky">
                        <div className="flex items-baseline gap-2">
                            <span className="text-3xl font-semibold tabular-nums">{v.done.length}</span>
                            <span className="text-sm text-muted-foreground">of {v.passers.length} fully scored</span>
                        </div>
                        <StackBar
                            parts={[
                                { n: v.done.length, cls: 'bg-sky-500', label: 'scored' },
                                { n: v.scoring.length, cls: 'bg-amber-400', label: 'scoring' },
                                { n: v.running.length, cls: 'bg-sky-300 dark:bg-sky-700', label: 'running' },
                                { n: Math.max(0, v.passers.length - v.done.length - v.scoring.length - v.running.length), cls: 'bg-zinc-200 dark:bg-zinc-700', label: 'queued' },
                            ]}
                        />
                    </Kpi>
                    <Kpi icon={ShieldCheck} title="Our quality (stage 2)" tone="emerald">
                        {v.quality.base > 0 ? (
                            <>
                                <div className="flex items-baseline gap-2">
                                    <span className="text-3xl font-semibold tabular-nums">{((100 * v.quality.passed) / v.quality.base).toFixed(1)}%</span>
                                    <span className="text-sm text-muted-foreground">of the baseline's passes</span>
                                </div>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {v.quality.passed} passes on our finished runs · the line is 80%
                                </p>
                            </>
                        ) : (
                            <p className="text-sm text-muted-foreground">no finished runs yet</p>
                        )}
                    </Kpi>
                </div>

                <Section
                    icon={Sparkles}
                    title="Our entries"
                    hint="ranked among the entries whose stage-2 runs are all scored; others show their progress · click opens thesoma.ai"
                    action={<MoreLink href={boardRoute.url({ query: { tab: 'ours' } })}>details</MoreLink>}
                >
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                        {v.ours.map((m) => (
                            <EntryCard key={m.hotkey} m={m} competition={competition.id} tasks={tasks} rank={v.rank.get(m.hotkey) ?? null} of={v.done.length} s1rank={v.s1rank.get(m.hotkey) ?? null} passers={v.passers.length} />
                        ))}
                    </div>
                </Section>

                <Section
                    icon={KeyRound}
                    title="Our registered hotkeys"
                    hint={`subnet 114 · read from the chain ${ago(hotkeys.taken_at, now)} · ${hotkeys.per_day_24h ?? '—'} new registrations in 24 h, ${hotkeys.per_day_7d ?? '—'}/day over 7 d`}
                    action={<MoreLink href={hotkeysRoute.url()}>all hotkeys and the queue</MoreLink>}
                >
                    <HotkeyTable rows={hotkeys.ours} competition={competition.id} />
                </Section>

                <Section icon={Activity} title="Where we stand" hint="stage-2 scores of every fully scored entry; our entries marked">
                    <Spread scores={v.scores} ours={v.ours.filter((m) => stateOf(m) === 'done')} median={v.median} />
                </Section>

                <div className="grid gap-5 xl:grid-cols-5">
                    <Section
                        className="xl:col-span-3"
                        icon={Trophy}
                        title="Stage 2 standings"
                        hint="top 10 of the fully scored entries and ours"
                        action={<MoreLink href={boardRoute.url({ query: { tab: 'stage2' } })}>full table</MoreLink>}
                    >
                        <Standings done={v.done} tasks={tasks} competition={competition.id} />
                    </Section>
                    <Section
                        className="xl:col-span-2"
                        icon={Users}
                        title="Families"
                        hint="by their best fully scored stage-2 entry"
                        action={<MoreLink href={boardRoute.url({ query: { tab: 'families' } })}>all families</MoreLink>}
                    >
                        <FamilyTable rows={v.famRows} />
                    </Section>
                </div>

                <Section icon={Activity} title="Latest activity" action={<MoreLink href={boardRoute.url({ query: { tab: 'events' } })}>all events</MoreLink>}>
                    <Feed events={events.slice(0, 10)} competition={competition.id} now={now} />
                </Section>
            </div>
        </>
    );
}

Home.layout = {
    breadcrumbs: [
        { title: 'SOMA', href: homeRoute() },
        { title: `Round overview`, href: homeRoute() },
    ],
};

// ------------------------------------------------------------------------------------------------ hero

function Hero(p: {
    competition: CompetitionInfo;
    phase: Phase;
    now: number;
    end: number | null;
    uploadEnd: number | null;
    done: number;
    running: number;
    scoring: number;
    passers: number;
    entries: number;
    watcher: WatcherInfo;
    pending: number;
}) {
    const uploadsOpen = p.uploadEnd !== null && p.uploadEnd > p.now;
    const at = { stage1: 1, stage2: 2, full: 3, scored: 4 }[p.phase] ?? 0;
    const current = uploadsOpen ? 0 : at;
    const steps: { label: string; icon: LucideIcon; note: string }[] = [
        { label: 'Uploads', icon: Upload, note: p.uploadEnd ? `${uploadsOpen ? 'close' : 'closed'} ${utc(p.competition.upload_deadline_at)}` : '' },
        { label: 'Stage 1', icon: Flag, note: `${p.passers} passed` },
        { label: 'Stage 2', icon: Gauge, note: `${p.done} / ${p.passers} scored${p.scoring ? ` · ${p.scoring} scoring` : ''}${p.running ? ` · ${p.running} running` : ''}` },
        { label: 'Full evaluation', icon: Users, note: 'every stage-1 passer' },
        { label: 'Final', icon: Crown, note: p.end ? `ends ${utc(p.competition.evaluation_end_at)}` : '' },
    ];

    return (
        <div className="relative shrink-0 overflow-hidden rounded-2xl border bg-gradient-to-br from-indigo-600 via-violet-600 to-sky-600 p-6 text-white shadow-lg dark:from-indigo-950 dark:via-violet-950 dark:to-sky-950">
            <div className="pointer-events-none absolute -top-24 -right-24 size-72 rounded-full bg-white/10 blur-3xl" />
            <div className="pointer-events-none absolute -bottom-32 left-1/3 size-80 rounded-full bg-sky-300/20 blur-3xl" />
            <div className="relative flex flex-wrap items-start justify-between gap-6">
                <div>
                    <div className="text-xs font-medium tracking-[0.2em] text-white/70 uppercase">SOMA · subnet 114</div>
                    <h1 className="mt-1 text-4xl font-semibold tracking-tight">Round {p.competition.round ?? '?'}</h1>
                    <p className="mt-1 text-sm text-white/80">
                        competition {p.competition.id} · {p.entries} entries · {p.passers} passed stage 1
                    </p>
                </div>
                <div className="text-right">
                    <div className="flex items-center justify-end gap-2 text-xs font-medium tracking-[0.2em] text-white/70 uppercase">
                        <Timer className="size-3.5" /> evaluation ends in
                    </div>
                    <div className="mt-1 text-4xl font-semibold tabular-nums">{p.end ? countdown(p.end - p.now) : '—'}</div>
                    <p className="mt-1 text-sm text-white/80">{utc(p.competition.evaluation_end_at)} UTC</p>
                </div>
            </div>

            <ol className="relative mt-6 grid grid-cols-5 gap-2">
                {steps.map((s, i) => {
                    const state = i < current ? 'done' : i === current ? 'now' : 'next';

                    return (
                        <li key={s.label} className="flex flex-col gap-2">
                            <div className={cn('h-1.5 rounded-full', state === 'done' ? 'bg-white' : state === 'now' ? 'bg-white/60' : 'bg-white/20')}>
                                {state === 'now' && i === 2 && p.passers > 0 && <div className="h-full rounded-full bg-white" style={{ width: `${(100 * p.done) / p.passers}%` }} />}
                            </div>
                            <div className="flex items-center gap-1.5 text-sm font-medium">
                                {state === 'done' ? <CircleCheck className="size-4" /> : state === 'now' ? <LoaderCircle className="size-4 animate-spin" /> : <s.icon className="size-4 opacity-60" />}
                                <span className={cn(state === 'next' && 'opacity-60')}>{s.label}</span>
                            </div>
                            <div className={cn('text-xs text-white/75', state === 'next' && 'opacity-60')}>{s.note}</div>
                        </li>
                    );
                })}
            </ol>

            <div className="relative mt-5 flex flex-wrap gap-2 text-xs">
                <HeroChip>
                    <RefreshCw className="size-3" /> leaderboard {ago(p.watcher.leaderboard_synced_at, p.now)}
                </HeroChip>
                <HeroChip>chain {ago(p.watcher.metagraph_synced_at, p.now)}</HeroChip>
                <HeroChip>queue {p.pending}</HeroChip>
                {p.watcher.failed > 0 && <HeroChip warn>{p.watcher.failed} failed jobs (24 h)</HeroChip>}
                <HeroChip>
                    owners traced {p.watcher.owners_traced}/{p.watcher.owners}
                </HeroChip>
            </div>
        </div>
    );
}

// ------------------------------------------------------------------------------------------------ our entries

function EntryCard({ m, competition, tasks, rank, of, s1rank, passers }: { m: MinerRow; competition: number; tasks: TaskRef[]; rank: number | null; of: number; s1rank: number | null; passers: number }) {
    const state = stateOf(m);
    const pct = progressOf(m);
    const q = qualityOf(m);
    const qShare = q.base > 0 ? q.passed / q.base : null;
    const top = state === 'done' && rank !== null && of > 0 ? rank / of : null;

    return (
        <div
            className={cn(
                'group relative flex flex-col gap-3 overflow-hidden rounded-xl border bg-card p-4 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md',
                state === 'done' && top !== null && top <= 0.1 && 'ring-1 ring-emerald-500/40',
            )}
        >
            <a href={somaUrl(competition, m.hotkey)} target="_blank" rel="noopener noreferrer" className="absolute inset-0 z-0" aria-label={`${m.our_label ?? m.hotkey} on thesoma.ai`} />
            <div className="flex items-center gap-2">
                <span className="flex h-7 min-w-9 items-center justify-center rounded-md bg-indigo-600 px-2 text-sm font-semibold text-white">{m.our_label ?? '—'}</span>
                <span className="font-mono text-xs text-muted-foreground">{shortKey(m.hotkey)}</span>
                <span className="ml-auto flex items-center gap-1.5">
                    {state === 'done' && <Pill tone="emerald">#{rank} of {of}</Pill>}
                    {state === 'scoring' && (
                        <Pill tone="amber">
                            <LoaderCircle className="size-3 animate-spin" /> scoring
                        </Pill>
                    )}
                    {state === 'running' && (
                        <Pill tone="sky">
                            <LoaderCircle className="size-3 animate-spin" /> running
                        </Pill>
                    )}
                    {state === 'waiting' && (
                        <Pill>
                            <Hourglass className="size-3" /> waiting
                        </Pill>
                    )}
                    {state === 'out' && <Pill tone="rose">{m.status}</Pill>}
                    <Link
                        href={minerRoute({ competition, hotkey: m.hotkey })}
                        className="relative z-10 rounded-md p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                        title="our analysis of this entry"
                    >
                        <ChartColumn className="size-3.5" />
                    </Link>
                </span>
            </div>

            <div className="flex items-end justify-between gap-2">
                {state === 'done' ? (
                    <div>
                        <div className="text-xs text-muted-foreground">stage 2</div>
                        <div className={cn('text-2xl font-semibold tabular-nums', scoreTone(m.stage2_score))}>{signed(m.stage2_score)}</div>
                    </div>
                ) : state === 'running' || state === 'scoring' ? (
                    <div className="w-full">
                        <div className="text-xs text-muted-foreground">{state === 'running' ? 'stage 2 running · runs scored' : 'runs done · results coming in'}</div>
                        <div className="flex items-baseline gap-3">
                            <span className="text-2xl font-semibold tabular-nums">{Math.round(100 * pct)}%</span>
                            {m.stage2_score !== null && m.stage2_score !== undefined && (
                                <span className="text-sm text-muted-foreground" title="current platform score - partial: runs without a result still count against it, so it moves until every run is scored">
                                    now <span className={cn('text-lg font-semibold tabular-nums', scoreTone(m.stage2_score))}>{signed(m.stage2_score)}</span>
                                </span>
                            )}
                        </div>
                        <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-muted">
                            <div className={cn('h-full rounded-full', state === 'running' ? 'bg-sky-500' : 'bg-amber-400')} style={{ width: `${100 * pct}%` }} />
                        </div>
                    </div>
                ) : (
                    <div>
                        <div className="text-xs text-muted-foreground">stage 2</div>
                        <div className="text-2xl font-semibold text-muted-foreground">not started</div>
                    </div>
                )}
                {state !== 'running' && state !== 'scoring' && (
                    <div className="text-right">
                        <div className="text-xs text-muted-foreground">stage 1</div>
                        <div className="font-mono text-sm tabular-nums">{signed(m.stage1_score)}</div>
                        {s1rank !== null && (
                            <div className="text-[11px] text-muted-foreground">
                                #{s1rank} of {passers}
                            </div>
                        )}
                    </div>
                )}
            </div>

            <div className="relative z-10 grid grid-cols-6 gap-1">
                {tasks.map((t) => {
                    const c = m.stage2?.[t.name];

                    return (
                        <div
                            key={t.name}
                            title={`${shortTask(t.name)} · ${t.band ?? ''} · ${
                                !c?.finished
                                    ? 'not run yet'
                                    : c.total && c.finished < c.total
                                      ? `${c.finished} of ${c.total} runs finished`
                                      : `${c.passed}/${c.finished} passed (baseline ${c.baseline_passed}/${c.baseline_total}) · ${signed(c.score)}`
                            }`}
                        >
                            <div className={cn('h-2 rounded-sm', cellTone(c))} />
                        </div>
                    );
                })}
            </div>
            <div className="flex items-center justify-between text-[11px] text-muted-foreground">
                <span className="inline-flex items-center gap-1">
                    six stage-2 tasks <ExternalLink className="size-3 opacity-0 transition group-hover:opacity-100" />
                </span>
                {qShare !== null && <span className={cn(qShare < 0.95 && 'text-amber-600', qShare < 0.8 && 'text-rose-600')}>quality {(100 * qShare).toFixed(0)}% of baseline</span>}
            </div>
        </div>
    );
}

// ------------------------------------------------------------------------------------------------ spread

function Spread({ scores, ours, median }: { scores: number[]; ours: MinerRow[]; median: number | null }) {
    if (scores.length === 0) {
        return <div className="rounded-xl border bg-card p-6 text-sm text-muted-foreground">no entry has finished stage 2 yet</div>;
    }
    // the window: 0.2 below the median to the top (and every entry of ours); entries far below - tasks that failed or did
    // not run count as -1 - are counted, not drawn, so the field's crowd stays readable
    const sorted = [...scores].sort((a, b) => a - b);
    const mid = median ?? sorted[Math.floor(sorted.length / 2)];
    const lo = Math.min(Math.floor((mid - 0.2) * 20) / 20, ...ours.map((m) => Math.floor(((m.stage2_score ?? 0) - 0.02) * 20) / 20));
    const hi = Math.max(0.3, Math.ceil((sorted[sorted.length - 1] + 0.01) * 20) / 20);
    const below = scores.filter((s) => s < lo).length;
    const step = 0.005;
    const bins = Math.round((hi - lo) / step);
    const counts = new Array(bins).fill(0);
    scores.filter((s) => s >= lo).forEach((s) => (counts[Math.min(bins - 1, Math.floor((s - lo) / step))] += 1));
    const peak = Math.max(...counts, 1);
    const x = (s: number) => ((s - lo) / (hi - lo)) * 100;
    const ticks: number[] = [];
    for (let t = Math.ceil(lo * 20) / 20; t <= hi + 1e-9; t += 0.05) {
        ticks.push(Math.round(t * 20) / 20);
    }
    // our markers, sorted by score, stacked in rows so close labels never overlap
    const marks = [...ours].sort((a, b) => (a.stage2_score ?? 0) - (b.stage2_score ?? 0)).map((m, i) => ({ m, row: i % 3 }));

    return (
        <div className="rounded-xl border bg-card p-5 shadow-sm">
            <div className="relative h-48">
                <div className="absolute inset-x-0 top-14 bottom-0 flex items-end gap-px">
                    {counts.map((n, i) => (
                        <div key={i} className="flex-1 rounded-t-sm bg-sky-500/35 dark:bg-sky-400/30" style={{ height: `${(100 * n) / peak}%` }} title={`${(lo + i * step).toFixed(2)} – ${(lo + (i + 1) * step).toFixed(2)}: ${n}`} />
                    ))}
                </div>
                {median !== null && (
                    <div className="absolute top-14 bottom-0 border-l border-dashed border-muted-foreground/60" style={{ left: `${x(median)}%` }}>
                        <span className="absolute top-1 left-1 text-[10px] whitespace-nowrap text-muted-foreground">median {signed(median)}</span>
                    </div>
                )}
                {marks.map(({ m, row }) => (
                    <div key={m.hotkey} className="absolute bottom-0 flex flex-col items-center" style={{ left: `${x(m.stage2_score ?? 0)}%`, top: `${row * 17}px`, transform: 'translateX(-50%)' }}>
                        <span className="rounded bg-indigo-600 px-1.5 text-[10px] leading-4 font-semibold text-white shadow" title={signed(m.stage2_score)}>
                            {m.our_label}
                        </span>
                        <span className="w-px flex-1 bg-indigo-600/80" />
                    </div>
                ))}
            </div>
            <div className="relative mt-1 h-4 text-[10px] text-muted-foreground">
                {ticks.map((t) => (
                    <span key={t} className="absolute -translate-x-1/2 tabular-nums" style={{ left: `${x(t)}%` }}>
                        {t.toFixed(2)}
                    </span>
                ))}
            </div>
            {below > 0 && (
                <p className="mt-2 text-xs text-muted-foreground">
                    {below} {below === 1 ? 'entry' : 'entries'} below {lo.toFixed(2)} not drawn (tasks that failed or did not run count as −1)
                </p>
            )}
        </div>
    );
}

// ------------------------------------------------------------------------------------------------ standings, families, feed

function Standings({ done, tasks, competition }: { done: MinerRow[]; tasks: TaskRef[]; competition: number }) {
    const shown = done.map((m, i) => ({ m, rank: i + 1 })).filter((r) => r.rank <= 10 || r.m.is_ours);
    const rows: ({ gap: true; key: string } | { gap: false; m: MinerRow; rank: number })[] = [];
    shown.forEach((r, i) => {
        if (i > 0 && r.rank - shown[i - 1].rank > 1) {
            rows.push({ gap: true, key: `gap-${r.rank}` });
        }
        rows.push({ gap: false, ...r });
    });

    return (
        <div className="overflow-hidden rounded-xl border bg-card shadow-sm">
            <table className="w-full text-sm">
                <thead className="bg-muted/50 text-xs text-muted-foreground">
                    <tr>
                        <th className="w-12 px-3 py-2 text-left font-medium">#</th>
                        <th className="px-3 py-2 text-left font-medium">entry</th>
                        <th className="hidden px-3 py-2 text-left font-medium md:table-cell">tasks</th>
                        <th className="px-3 py-2 text-right font-medium">stage 2</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((r) =>
                        r.gap ? (
                            <tr key={r.key}>
                                <td colSpan={4} className="px-3 py-0.5 text-center text-xs text-muted-foreground">
                                    ···
                                </td>
                            </tr>
                        ) : (
                            <tr key={r.m.hotkey} className={cn('border-t', r.m.is_ours && 'bg-indigo-500/10')}>
                                <td className="px-3 py-2 font-medium text-muted-foreground tabular-nums">{r.rank}</td>
                                <td className="px-3 py-2">
                                    <a href={somaUrl(competition, r.m.hotkey)} target="_blank" rel="noopener noreferrer" className="flex items-center gap-2 hover:underline">
                                        {r.m.is_ours ? <span className="rounded bg-indigo-600 px-1.5 text-xs font-semibold text-white">{r.m.our_label}</span> : <span className="font-mono text-xs">{shortKey(r.m.hotkey)}</span>}
                                        <span className="truncate text-xs text-muted-foreground">{r.m.family_name ?? shortKey(r.m.coldkey)}</span>
                                    </a>
                                </td>
                                <td className="hidden px-3 py-2 md:table-cell">
                                    <div className="flex gap-0.5">
                                        {tasks.map((t) => (
                                            <div key={t.name} className={cn('h-3 w-5 rounded-sm', cellTone(r.m.stage2?.[t.name]))} title={`${shortTask(t.name)} ${signed(r.m.stage2?.[t.name]?.score)}`} />
                                        ))}
                                    </div>
                                </td>
                                <td className={cn('px-3 py-2 text-right font-mono tabular-nums', scoreTone(r.m.stage2_score))}>{signed(r.m.stage2_score)}</td>
                            </tr>
                        ),
                    )}
                </tbody>
            </table>
        </div>
    );
}

function FamilyTable({ rows }: { rows: { key: string; name: string; ours: boolean; best: MinerRow; entries: number; done: number }[] }) {
    const top = rows.slice(0, 10);
    const ourIndex = rows.findIndex((f) => f.ours);
    const shown = ourIndex >= 10 ? [...top, rows[ourIndex]] : top;
    const max = Math.max(...rows.map((f) => f.best.stage2_score ?? 0), 0.01);

    return (
        <div className="overflow-hidden rounded-xl border bg-card shadow-sm">
            <table className="w-full text-sm">
                <thead className="bg-muted/50 text-xs text-muted-foreground">
                    <tr>
                        <th className="w-10 px-3 py-2 text-left font-medium">#</th>
                        <th className="px-3 py-2 text-left font-medium">family</th>
                        <th className="px-3 py-2 text-right font-medium">entries</th>
                        <th className="w-40 px-3 py-2 text-right font-medium">best stage 2</th>
                    </tr>
                </thead>
                <tbody>
                    {shown.map((f) => {
                        const i = rows.indexOf(f);

                        return (
                            <tr key={f.key} className={cn('border-t', f.ours && 'bg-indigo-500/10')}>
                                <td className="px-3 py-2 text-muted-foreground tabular-nums">{i + 1}</td>
                                <td className="max-w-0 truncate px-3 py-2 font-medium">{f.ours ? <span className="text-indigo-600 dark:text-indigo-300">Ours</span> : f.name}</td>
                                <td className="px-3 py-2 text-right text-muted-foreground tabular-nums">
                                    {f.done}/{f.entries}
                                </td>
                                <td className="px-3 py-2">
                                    <div className="flex items-center justify-end gap-2">
                                        <div className="hidden h-1.5 w-16 overflow-hidden rounded-full bg-muted sm:block">
                                            <div className={cn('h-full rounded-full', f.ours ? 'bg-indigo-500' : 'bg-emerald-500')} style={{ width: `${Math.max(0, (100 * (f.best.stage2_score ?? 0)) / max)}%` }} />
                                        </div>
                                        <span className={cn('font-mono tabular-nums', scoreTone(f.best.stage2_score))}>{signed(f.best.stage2_score)}</span>
                                    </div>
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

const EVENT_ICON: Record<string, { icon: LucideIcon; cls: string }> = {
    upload: { icon: Upload, cls: 'bg-sky-500/15 text-sky-600 dark:text-sky-300' },
    stage1_done: { icon: Flag, cls: 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-300' },
    status: { icon: Activity, cls: 'bg-amber-500/15 text-amber-600 dark:text-amber-300' },
    registration: { icon: Users, cls: 'bg-violet-500/15 text-violet-600 dark:text-violet-300' },
};

function describe(e: WatchEvent): string {
    const d = e.data ?? {};
    if (e.type === 'status') {
        return `${d.from ?? '?'} → ${d.status ?? '?'}`;
    }
    if (e.type === 'stage1_done') {
        return `stage 1 ${typeof d.stage1 === 'number' ? signed(d.stage1) : ''}`.trim();
    }

    return e.type.replace('_', ' ');
}

function Feed({ events, competition, now }: { events: WatchEvent[]; competition: number; now: number }) {
    if (events.length === 0) {
        return <div className="rounded-xl border bg-card p-6 text-sm text-muted-foreground">no events yet</div>;
    }

    return (
        <div className="grid gap-2 rounded-xl border bg-card p-3 shadow-sm md:grid-cols-2">
            {events.map((e) => {
                const ic = EVENT_ICON[e.type] ?? { icon: Activity, cls: 'bg-muted text-muted-foreground' };

                return (
                    <div key={e.id} className={cn('flex items-center gap-3 rounded-lg px-2 py-1.5', e.is_ours && 'bg-indigo-500/10')}>
                        <span className={cn('flex size-7 shrink-0 items-center justify-center rounded-md', ic.cls)}>
                            <ic.icon className="size-3.5" />
                        </span>
                        <div className="min-w-0 flex-1">
                            <div className="truncate text-sm">{describe(e)}</div>
                            <a href={somaUrl(competition, e.hotkey)} target="_blank" rel="noopener noreferrer" className="font-mono text-[11px] text-muted-foreground hover:underline">
                                {shortKey(e.hotkey)}
                            </a>
                        </div>
                        <span className="shrink-0 text-xs text-muted-foreground">{ago(e.at, now)}</span>
                    </div>
                );
            })}
        </div>
    );
}

// ------------------------------------------------------------------------------------------------ hotkeys

const emissionText = (v: number | null) => (v === null ? '—' : v >= 1e6 ? `${(v / 1e6).toFixed(2)}M` : v >= 1e3 ? `${(v / 1e3).toFixed(1)}k` : String(v));
const placeTone = (r: number | null) => (r === null ? 'bg-muted text-muted-foreground' : r <= 10 ? 'bg-rose-600 text-white' : r <= 30 ? 'bg-amber-500 text-white' : 'bg-muted text-foreground');

/** Our registered SN114 hotkeys, nearest to deregistration first: the next registration removes place 1. */
function HotkeyTable({ rows, competition }: { rows: Props['hotkeys']['ours']; competition: number }) {
    if (rows.length === 0) {
        return <div className="rounded-xl border bg-card p-6 text-sm text-muted-foreground">no chain snapshot yet</div>;
    }

    return (
        <div className="overflow-x-auto rounded-xl border bg-card shadow-sm">
            <table className="w-full text-sm">
                <thead className="bg-muted/50 text-xs text-muted-foreground">
                    <tr>
                        <th className="px-3 py-2 text-left font-medium">hotkey</th>
                        <th className="px-3 py-2 text-left font-medium">entry</th>
                        <th className="px-3 py-2 text-right font-medium">uid</th>
                        <th className="px-3 py-2 text-right font-medium">emission</th>
                        <th className="px-3 py-2 text-right font-medium">deregistration place</th>
                        <th className="px-3 py-2 text-right font-medium">vs 24 h ago</th>
                        <th className="px-3 py-2 text-right font-medium">time left (24 h · 7 d pace)</th>
                        <th className="px-3 py-2 text-right font-medium">registered</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((h) => {
                        const moved = h.rank !== null && h.rank_day_ago !== null ? h.rank_day_ago - h.rank : null;

                        return (
                            <tr key={h.hotkey} className="border-t hover:bg-muted/40">
                                <td className="px-3 py-1.5">
                                    <a href={`https://taostats.io/hotkey/${h.hotkey}`} target="_blank" rel="noopener noreferrer" className="hover:underline" title="open on Taostats">
                                        <span className="font-medium">{h.name}</span> <span className="font-mono text-xs text-muted-foreground">{shortKey(h.hotkey)}</span>
                                    </a>
                                </td>
                                <td className="px-3 py-1.5">
                                    {h.label ? (
                                        <a href={somaUrl(competition, h.hotkey)} target="_blank" rel="noopener noreferrer" className="rounded bg-indigo-600 px-1.5 text-xs font-semibold text-white">
                                            {h.label}
                                        </a>
                                    ) : (
                                        <span className="text-xs text-muted-foreground">—</span>
                                    )}
                                </td>
                                <td className="px-3 py-1.5 text-right font-mono tabular-nums">{h.uid ?? '—'}</td>
                                <td className={cn('px-3 py-1.5 text-right font-mono tabular-nums', !h.emission && 'text-rose-600')}>{emissionText(h.emission)}</td>
                                <td className="px-3 py-1.5 text-right">
                                    {h.immune ? (
                                        <Pill tone="sky">immune {h.immune_hours_left ?? '?'} h</Pill>
                                    ) : (
                                        <span className={cn('inline-flex min-w-9 justify-center rounded px-1.5 font-mono text-xs font-semibold tabular-nums', placeTone(h.rank))}>{h.rank ?? '—'}</span>
                                    )}
                                </td>
                                <td className={cn('px-3 py-1.5 text-right font-mono text-xs tabular-nums', moved !== null && moved > 0 && 'text-rose-600', moved !== null && moved < 0 && 'text-emerald-600')}>
                                    {moved === null ? '—' : moved === 0 ? '0' : moved > 0 ? `▲${moved}` : `▼${-moved}`}
                                </td>
                                <td className="px-3 py-1.5 text-right font-mono text-xs text-muted-foreground tabular-nums">
                                    {h.eta_days_24h !== null ? `${h.eta_days_24h} d` : '—'} · {h.eta_days_7d !== null ? `${h.eta_days_7d} d` : '—'}
                                </td>
                                <td className="px-3 py-1.5 text-right text-xs text-muted-foreground tabular-nums">{h.age_days !== null ? `${h.age_days} d ago` : '—'}</td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}
