import { Head, Link, usePoll } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { ArrowRight, ChartColumn, Crown, ExternalLink, Gauge, Gavel, Hourglass, KeyRound, LoaderCircle, Radar, Scale, Sparkles, Timer, Trophy } from 'lucide-react';
import type { ReactNode } from 'react';
import { useMemo } from 'react';
import { HeroChip, HeroStat, PAGE, PageHero, Pill, scoreTone, signed, StackBar } from '@/components/soma/kit';
import { ago, shortKey, utc } from '@/lib/soma';
import type { EntryState } from '@/lib/soma-state';
import { entryState, somaUrl, stage2Progress } from '@/lib/soma-state';
import { cn } from '@/lib/utils';
import { home as hubRoute } from '@/routes';
import { overview as sn34Route } from '@/routes/sn34';
import { home as somaRoute, hotkeys as hotkeysRoute, miner as minerRoute, overview as boardRoute } from '@/routes/soma';
import type { CompetitionInfo, MinerRow, Phase, TaskRef } from '@/types';

/**
 * The cross-subnet overview at "/" (user 2026-11-25: "overview를 click하면 soma와 SN34의 현재 정보들을 보여주는 페지"):
 * the current SOMA round and the Yugos competition side by side, each with its own colour (indigo, amber) and a link to its
 * full pages. Entries open on their official site in a new tab.
 *
 * SN34 wording follows the NPA/34 watch rules: a seat's NV is the value at its crowning (against the king before it); a
 * decision's NV is against the current king and promotion needs LB > 0.2; "failed" / "error" is a state now, not the end
 * (runs here have turned completed hours later); family labels are coldkey prefixes, not operator names.
 */
type LightMiner = Pick<MinerRow, 'hotkey' | 'is_ours' | 'our_label' | 'status' | 'stage1_score' | 'stage2_score' | 'family' | 'family_name' | 'coldkey' | 'stage2'>;

type Props = {
    soma: {
        competition: CompetitionInfo;
        phase: Phase;
        stage2_problems: TaskRef[];
        watcher: { leaderboard_synced_at: string | null; metagraph_synced_at: string | null };
        miners: LightMiner[];
        built_at: string;
        hotkeys: { registered: number; total: number; taken_at: string | null; front: { name: string; rank: number; eta_days_7d: number | null; eta_days_24h: number | null } | null };
    };
    sn34: {
        confirmed_at: string | null;
        policy: string | null;
        phase: string | null;
        kings: { seat: string | null; hotkey: string | null; run: string; share: number | null; nv: number | null; lb: number | null; coldkey: string | null; ours: boolean; incentive: number | null }[];
        our_weight: number;
        runs_now: { run: string; hotkey: string; coldkey: string | null; ours: boolean; state: string; executed: number | null; assigned: number | null; ungraded: number | null; started_at: string | null }[];
        decisions: { run: string; hotkey: string; coldkey: string | null; ours: boolean; eligible: boolean | null; promoted: boolean | null; nv: number | null; lb: number | null; near_miss: boolean; reasons: string[]; finished_at: string | null }[];
        our_hotkeys?: { registered: number; nearest_place: number | null; non_immune: number | null };
    };
};

const YUGOS = 'https://competition.yugos.ai';
const taoHotkey = (h: string) => `https://taostats.io/hotkey/${h}`;
const taoAccount = (c: string) => `https://taostats.io/account/${c}`;

function countdown(ms: number): string {
    if (ms <= 0) {
        return 'ended';
    }
    const d = Math.floor(ms / 86_400_000);
    const h = Math.floor((ms % 86_400_000) / 3_600_000);
    const m = Math.floor((ms % 3_600_000) / 60_000);

    return d > 0 ? `${d}d ${h}h` : h > 0 ? `${h}h ${m}m` : `${m}m`;
}

export default function Hub({ soma, sn34 }: Props) {
    usePoll(60_000);
    const now = Date.now();

    const s = useMemo(() => {
        const passers = soma.miners.filter((m) => entryState(m) !== 'out');
        const done = soma.miners.filter((m) => entryState(m) === 'done').sort((a, b) => (b.stage2_score ?? -9) - (a.stage2_score ?? -9));
        const rank = new Map(done.map((m, i) => [m.hotkey, i + 1]));
        const count = (st: EntryState) => soma.miners.filter((m) => entryState(m) === st).length;
        const order: Record<EntryState, number> = { done: 0, scoring: 1, running: 2, waiting: 3, out: 4 };
        const label = (m: LightMiner) => Number((m.our_label ?? 'S999').replace(/\D/g, '')) || 999;
        const ours = soma.miners.filter((m) => m.is_ours).sort((a, b) => order[entryState(a)] - order[entryState(b)] || (rank.get(a.hotkey) ?? 0) - (rank.get(b.hotkey) ?? 0) || label(a) - label(b));
        const oursBy = (st: EntryState) => ours.filter((m) => entryState(m) === st).length;

        return { passers: passers.length, done, rank, scoring: count('scoring'), running: count('running'), ours, oursBy };
    }, [soma.miners]);

    const best = s.ours.find((m) => entryState(m) === 'done') ?? null;
    const end = soma.competition.evaluation_end_at ? new Date(soma.competition.evaluation_end_at).getTime() : null;
    const king = sn34.kings.find((k) => k.seat === 'current') ?? sn34.kings[0] ?? null;

    return (
        <>
            <Head title="Overview" />
            <div className={PAGE}>
                <PageHero
                    tone="indigo"
                    eyebrow="Subnet Watch · all subnets"
                    title="Overview"
                    subtitle={`SOMA (subnet 114) and Yugos (subnet 34) at a glance · ${utc(new Date(now).toISOString())} UTC`}
                    right={
                        <>
                            <HeroStat label="SOMA ends in" icon={Timer} value={end ? countdown(end - now) : '—'} note={`round ${soma.competition.round ?? '?'} · ${utc(soma.competition.evaluation_end_at)} UTC`} />
                            <HeroStat label="SN34 king NV" icon={Crown} value={signed(king?.nv)} note={king ? `${shortKey(king.coldkey ?? king.hotkey, 8)} · at crowning` : 'no seat read'} />
                        </>
                    }
                >
                    <HeroChip>SOMA leaderboard {ago(soma.watcher.leaderboard_synced_at, now)}</HeroChip>
                    <HeroChip>SN34 board {ago(sn34.confirmed_at, now)}</HeroChip>
                    <HeroChip>summary built {ago(soma.built_at, now)}</HeroChip>
                </PageHero>

                <div className="grid gap-5 xl:grid-cols-2">
                    {/* ---------------------------------------------------------------- SOMA */}
                    <Panel
                        tone="indigo"
                        icon={Sparkles}
                        title="SOMA · subnet 114"
                        subtitle={`round ${soma.competition.round ?? '?'} · competition ${soma.competition.id} · ${soma.phase === 'stage2' ? 'stage 2' : soma.phase === 'full' ? 'full evaluation' : soma.phase === 'scored' ? 'scored' : 'stage 1'}`}
                        href={somaRoute.url()}
                        links={[
                            { label: 'Round overview', href: somaRoute.url() },
                            { label: 'Leaderboard', href: boardRoute.url() },
                        ]}
                    >
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Stat icon={Trophy} label="our best (fully scored)">
                                {best ? (
                                    <>
                                        <span className={cn('text-2xl font-semibold tabular-nums', scoreTone(best.stage2_score))}>{signed(best.stage2_score)}</span>
                                        <span className="text-xs text-muted-foreground">
                                            {best.our_label} · #{s.rank.get(best.hotkey)} of {s.done.length}
                                        </span>
                                    </>
                                ) : (
                                    <span className="text-sm text-muted-foreground">none fully scored yet</span>
                                )}
                            </Stat>
                            <Stat icon={Gauge} label="stage 2 fully scored">
                                <span className="text-2xl font-semibold tabular-nums">{s.done.length}</span>
                                <span className="text-xs text-muted-foreground">
                                    of {s.passers} · {s.scoring} scoring · {s.running} running
                                </span>
                            </Stat>
                            <Stat icon={Sparkles} label="our entries">
                                <StackBar
                                    parts={[
                                        { n: s.oursBy('done'), cls: 'bg-emerald-500', label: 'done' },
                                        { n: s.oursBy('scoring'), cls: 'bg-amber-400', label: 'scoring' },
                                        { n: s.oursBy('running'), cls: 'bg-sky-500', label: 'running' },
                                        { n: s.oursBy('waiting'), cls: 'bg-zinc-300 dark:bg-zinc-600', label: 'waiting' },
                                    ]}
                                />
                            </Stat>
                            <Stat icon={KeyRound} label="our hotkeys">
                                <span className="text-2xl font-semibold tabular-nums">{soma.hotkeys.registered}</span>
                                <span className="text-xs text-muted-foreground">registered of {soma.hotkeys.total}</span>
                                {soma.hotkeys.front && (
                                    <span className="w-full text-xs text-muted-foreground">
                                        nearest to deregistration{' '}
                                        <Link href={hotkeysRoute.url()} className={cn('font-semibold hover:underline', soma.hotkeys.front.rank <= 10 ? 'text-rose-600' : soma.hotkeys.front.rank <= 30 ? 'text-amber-600' : '')}>
                                            #{soma.hotkeys.front.rank}
                                        </Link>{' '}
                                        {soma.hotkeys.front.name} · ≈{soma.hotkeys.front.eta_days_7d ?? '—'} d
                                    </span>
                                )}
                            </Stat>
                        </div>

                        <div className="overflow-hidden rounded-lg border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-xs text-muted-foreground">
                                    <tr>
                                        <th className="px-3 py-2 text-left font-medium">entry</th>
                                        <th className="px-3 py-2 text-left font-medium">stage 2</th>
                                        <th className="px-3 py-2 text-right font-medium">rank</th>
                                        <th className="px-3 py-2 text-right font-medium">stage 1</th>
                                        <th className="w-8" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {s.ours.map((m) => {
                                        const st = entryState(m);
                                        const pct = stage2Progress(m);

                                        return (
                                            <tr key={m.hotkey} className="border-t hover:bg-muted/40">
                                                <td className="px-3 py-1.5">
                                                    <a href={somaUrl(soma.competition.id, m.hotkey)} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-2 hover:underline" title="open on thesoma.ai">
                                                        <span className="rounded bg-indigo-600 px-1.5 text-xs font-semibold text-white">{m.our_label}</span>
                                                        <span className="font-mono text-xs text-muted-foreground">{shortKey(m.hotkey)}</span>
                                                    </a>
                                                </td>
                                                <td className="px-3 py-1.5">
                                                    {st === 'done' ? (
                                                        <span className={cn('font-mono tabular-nums', scoreTone(m.stage2_score))}>{signed(m.stage2_score)}</span>
                                                    ) : st === 'running' || st === 'scoring' ? (
                                                        <span className="flex items-center gap-2">
                                                            <Pill tone={st === 'running' ? 'sky' : 'amber'}>
                                                                <LoaderCircle className="size-3 animate-spin" /> {st}
                                                            </Pill>
                                                            <span className="h-1.5 w-20 overflow-hidden rounded-full bg-muted">
                                                                <span className={cn('block h-full rounded-full', st === 'running' ? 'bg-sky-500' : 'bg-amber-400')} style={{ width: `${100 * pct}%` }} />
                                                            </span>
                                                            <span className="text-xs text-muted-foreground tabular-nums">{Math.round(100 * pct)}%</span>
                                                            {m.stage2_score !== null && m.stage2_score !== undefined && (
                                                                <span className="text-xs text-muted-foreground" title="current platform score - partial: runs without a result still count against it, so it moves until every run is scored">
                                                                    now <span className={cn('font-mono tabular-nums', scoreTone(m.stage2_score))}>{signed(m.stage2_score)}</span>
                                                                </span>
                                                            )}
                                                        </span>
                                                    ) : (
                                                        <Pill>
                                                            <Hourglass className="size-3" /> {st}
                                                        </Pill>
                                                    )}
                                                </td>
                                                <td className="px-3 py-1.5 text-right text-muted-foreground tabular-nums">{st === 'done' ? `#${s.rank.get(m.hotkey)}` : '—'}</td>
                                                <td className="px-3 py-1.5 text-right font-mono text-xs tabular-nums">{signed(m.stage1_score)}</td>
                                                <td className="px-2 py-1.5 text-right">
                                                    <Link href={minerRoute({ competition: soma.competition.id, hotkey: m.hotkey })} className="inline-flex rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground" title="our analysis of this entry">
                                                        <ChartColumn className="size-3.5" />
                                                    </Link>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                        <p className="text-xs text-muted-foreground">
                            Ranked among the {s.done.length} entries whose stage-2 runs are all scored. An entry still running or still being scored shows its progress; its partial score counts unscored tasks as −1.
                        </p>
                    </Panel>

                    {/* ---------------------------------------------------------------- SN34 */}
                    <Panel
                        tone="amber"
                        icon={Crown}
                        title="Yugos · subnet 34"
                        subtitle={`${sn34.phase ?? 'phase ?'} · policy ${sn34.policy ? sn34.policy.slice(0, 8) : '?'} · board ${utc(sn34.confirmed_at)} UTC`}
                        href={sn34Route.url()}
                        links={[
                            { label: 'Competition', href: sn34Route.url() },
                            { label: 'Decisions', href: sn34Route.url({ query: { tab: 'decisions' } }) },
                        ]}
                    >
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Stat icon={Crown} label="king NV (at crowning)">
                                <span className={cn('text-2xl font-semibold tabular-nums', scoreTone(king?.nv))}>{signed(king?.nv)}</span>
                                <span className="text-xs text-muted-foreground">LB {signed(king?.lb)}</span>
                            </Stat>
                            <Stat icon={Scale} label="our weight">
                                <span className="text-2xl font-semibold tabular-nums">{sn34.our_weight.toFixed(2)}</span>
                                <span className="text-xs text-muted-foreground">{sn34.kings.some((k) => k.ours) ? 'we hold a seat' : 'no seat'}</span>
                            </Stat>
                            <Stat icon={Radar} label="runs now">
                                <span className="text-2xl font-semibold tabular-nums">{sn34.runs_now.length}</span>
                                <span className="text-xs text-muted-foreground">{sn34.runs_now.filter((r) => r.state === 'error').length} with a queue error</span>
                            </Stat>
                            <Stat icon={KeyRound} label="our hotkeys">
                                <span className="text-2xl font-semibold tabular-nums">{sn34.our_hotkeys?.registered ?? '—'}</span>
                                <span className="text-xs text-muted-foreground">registered on subnet 34</span>
                                {sn34.our_hotkeys?.nearest_place ? (
                                    <span className="w-full text-xs text-muted-foreground">
                                        nearest to deregistration{' '}
                                        <span className={cn('font-semibold', sn34.our_hotkeys.nearest_place <= 10 ? 'text-rose-600' : sn34.our_hotkeys.nearest_place <= 30 ? 'text-amber-600' : '')}>#{sn34.our_hotkeys.nearest_place}</span>
                                        {sn34.our_hotkeys.non_immune ? ` of ${sn34.our_hotkeys.non_immune}` : ''}
                                    </span>
                                ) : null}
                            </Stat>
                        </div>

                        <div className="grid gap-3 md:grid-cols-2">
                            <MiniList title="Seats" icon={Crown}>
                                {sn34.kings.map((k) => (
                                    <a key={`${k.seat}-${k.run}`} href={`${YUGOS}/?tab=overview`} target="_blank" rel="noopener noreferrer" className={cn('flex items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-muted/60', k.ours && 'bg-amber-500/10')}>
                                        <span className="w-16 text-xs text-muted-foreground">{k.seat?.replace('_', ' ')}</span>
                                        <span className="font-mono text-xs">{shortKey(k.coldkey ?? k.hotkey, 8)}</span>
                                        <span className="ml-auto text-xs whitespace-nowrap text-muted-foreground tabular-nums">share {k.share ?? '—'}</span>
                                        <span className={cn('w-14 text-right font-mono text-xs tabular-nums', scoreTone(k.nv))}>{signed(k.nv)}</span>
                                    </a>
                                ))}
                            </MiniList>
                            <MiniList title="Runs now" icon={Radar}>
                                {sn34.runs_now.length === 0 && <p className="px-2 py-1.5 text-sm text-muted-foreground">no open runs</p>}
                                {sn34.runs_now.map((r) => (
                                    <a key={r.run} href={`${YUGOS}/?tab=submissions`} target="_blank" rel="noopener noreferrer" className={cn('flex items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-muted/60', r.ours && 'bg-amber-500/10')} title={`run ${r.run} - find it in the official submissions list`}>
                                        <span className="font-mono text-xs">{r.run}</span>
                                        <span className="font-mono text-xs text-muted-foreground">{shortKey(r.coldkey ?? r.hotkey, 6)}</span>
                                        {r.state === 'error' ? (
                                            <Pill tone="rose" className="ml-auto">
                                                error · may still finish
                                            </Pill>
                                        ) : (
                                            <span className="ml-auto flex items-center gap-2">
                                                <span className="h-1.5 w-16 overflow-hidden rounded-full bg-muted">
                                                    <span className="block h-full rounded-full bg-emerald-500" style={{ width: `${r.assigned ? (100 * (r.executed ?? 0)) / r.assigned : 0}%` }} />
                                                </span>
                                                <span className="text-xs text-muted-foreground tabular-nums">
                                                    {r.executed ?? 0}/{r.assigned ?? '?'}
                                                </span>
                                            </span>
                                        )}
                                    </a>
                                ))}
                            </MiniList>
                        </div>

                        <MiniList title="Latest decisions (NV against the current king; promotion needs LB > 0.2)" icon={Gavel}>
                            {sn34.decisions.map((d) => (
                                <a key={d.run} href={`${YUGOS}/?tab=submissions`} target="_blank" rel="noopener noreferrer" className={cn('flex items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-muted/60', d.ours && 'bg-amber-500/10')}>
                                    <span className="font-mono text-xs">{d.run}</span>
                                    <span className="font-mono text-xs text-muted-foreground">{shortKey(d.coldkey ?? d.hotkey, 6)}</span>
                                    <span className="text-xs text-muted-foreground">{d.finished_at ? ago(d.finished_at, now) : ''}</span>
                                    <span className="ml-auto">
                                        {d.promoted ? (
                                            <Pill tone="amber">
                                                <Crown className="size-3" /> king
                                            </Pill>
                                        ) : d.eligible ? (
                                            <Pill tone="emerald">eligible</Pill>
                                        ) : d.near_miss ? (
                                            <Pill tone="rose">near miss</Pill>
                                        ) : (
                                            <Pill>{d.reasons.length} gates</Pill>
                                        )}
                                    </span>
                                    <span className={cn('w-16 text-right font-mono text-xs tabular-nums', scoreTone(d.nv))}>{signed(d.nv)}</span>
                                </a>
                            ))}
                        </MiniList>
                        <p className="text-xs text-muted-foreground">
                            Rows open competition.yugos.ai (it has no page per run: find the run id in its submissions list). Labels are coldkey prefixes, not operator names.
                            <a href={taoAccount(king?.coldkey ?? '')} target="_blank" rel="noopener noreferrer" className={cn('ml-1 hover:underline', !king?.coldkey && 'hidden')}>
                                king on Taostats
                            </a>
                            {king?.hotkey && (
                                <a href={taoHotkey(king.hotkey)} target="_blank" rel="noopener noreferrer" className="ml-2 hover:underline">
                                    king hotkey
                                </a>
                            )}
                        </p>
                    </Panel>
                </div>
            </div>
        </>
    );
}

Hub.layout = {
    breadcrumbs: [{ title: 'Overview', href: hubRoute() }],
};

const PANEL_TONE = {
    indigo: 'from-indigo-600 via-violet-600 to-sky-600 dark:from-indigo-950 dark:via-violet-950 dark:to-sky-950',
    amber: 'from-amber-500 via-orange-600 to-rose-600 dark:from-amber-950 dark:via-orange-950 dark:to-rose-950',
} as const;

function Panel({ tone, icon: Icon, title, subtitle, href, links, children }: { tone: keyof typeof PANEL_TONE; icon: LucideIcon; title: string; subtitle: string; href: string; links: { label: string; href: string }[]; children: ReactNode }) {
    return (
        <section className="flex flex-col overflow-hidden rounded-2xl border bg-card shadow-sm">
            <div className={cn('relative overflow-hidden bg-gradient-to-br px-5 py-4 text-white', PANEL_TONE[tone])}>
                <div className="pointer-events-none absolute -top-16 -right-16 size-48 rounded-full bg-white/10 blur-2xl" />
                <div className="relative flex flex-wrap items-center gap-3">
                    <span className="flex size-9 items-center justify-center rounded-lg bg-white/15">
                        <Icon className="size-5" />
                    </span>
                    <div className="min-w-0">
                        <Link href={href} className="text-lg font-semibold tracking-tight hover:underline">
                            {title}
                        </Link>
                        <div className="truncate text-xs text-white/80">{subtitle}</div>
                    </div>
                    <div className="ml-auto flex flex-wrap gap-2">
                        {links.map((l) => (
                            <Link key={l.label} href={l.href} className="inline-flex items-center gap-1 rounded-full bg-white/15 px-3 py-1 text-xs font-medium backdrop-blur hover:bg-white/25">
                                {l.label} <ArrowRight className="size-3" />
                            </Link>
                        ))}
                    </div>
                </div>
            </div>
            <div className="flex flex-col gap-4 p-5">{children}</div>
        </section>
    );
}

function Stat({ icon: Icon, label, children }: { icon: LucideIcon; label: string; children: ReactNode }) {
    return (
        <div className="rounded-lg border bg-muted/20 p-3">
            <div className="mb-1 flex items-center gap-1.5 text-[11px] font-medium tracking-wide text-muted-foreground uppercase">
                <Icon className="size-3.5" /> {label}
            </div>
            <div className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">{children}</div>
        </div>
    );
}

function MiniList({ title, icon: Icon, children }: { title: string; icon: LucideIcon; children: ReactNode }) {
    return (
        <div className="rounded-lg border">
            <div className="flex items-center gap-1.5 border-b bg-muted/40 px-3 py-2 text-xs font-medium text-muted-foreground">
                <Icon className="size-3.5" /> {title}
                <ExternalLink className="ml-auto size-3 opacity-60" />
            </div>
            <div className="p-1">{children}</div>
        </div>
    );
}
