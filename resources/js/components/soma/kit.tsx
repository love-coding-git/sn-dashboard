import { Link } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { ArrowRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * The dashboard's shared look (2026-11-25 redesign; user: "다른 페이지들도 첫페지와 비슷한 UI로 좀 멋있게 만들라"): a
 * gradient page hero, KPI tiles, section headings, pills, and the classes for tab bars and table frames. Every page uses
 * these, so the pages read as one product. Each page keeps its own hero tone.
 */

export const HERO_TONE = {
    indigo: 'from-indigo-600 via-violet-600 to-sky-600 dark:from-indigo-950 dark:via-violet-950 dark:to-sky-950',
    violet: 'from-violet-600 via-fuchsia-600 to-indigo-600 dark:from-violet-950 dark:via-fuchsia-950 dark:to-indigo-950',
    emerald: 'from-emerald-600 via-teal-600 to-sky-700 dark:from-emerald-950 dark:via-teal-950 dark:to-sky-950',
    amber: 'from-amber-500 via-orange-600 to-rose-600 dark:from-amber-950 dark:via-orange-950 dark:to-rose-950',
} as const;

export function PageHero({
    tone = 'indigo',
    eyebrow,
    title,
    subtitle,
    right,
    children,
}: {
    tone?: keyof typeof HERO_TONE;
    eyebrow: ReactNode;
    title: ReactNode;
    subtitle?: ReactNode;
    right?: ReactNode;
    children?: ReactNode;
}) {
    return (
        <div className={cn('relative shrink-0 overflow-hidden rounded-2xl border bg-gradient-to-br p-6 text-white shadow-lg', HERO_TONE[tone])}>
            <div className="pointer-events-none absolute -top-24 -right-24 size-72 rounded-full bg-white/10 blur-3xl" />
            <div className="pointer-events-none absolute -bottom-32 left-1/3 size-80 rounded-full bg-white/10 blur-3xl" />
            <div className="relative flex flex-wrap items-start justify-between gap-6">
                <div className="min-w-0">
                    <div className="text-xs font-medium tracking-[0.2em] text-white/70 uppercase">{eyebrow}</div>
                    <h1 className="mt-1 truncate text-3xl font-semibold tracking-tight md:text-4xl">{title}</h1>
                    {subtitle && <div className="mt-1 text-sm text-white/80">{subtitle}</div>}
                </div>
                {right && <div className="flex flex-wrap items-start justify-end gap-3 text-right">{right}</div>}
            </div>
            {children && <div className="relative mt-5 flex flex-wrap gap-2 text-xs">{children}</div>}
        </div>
    );
}

export function HeroChip({ children, warn = false }: { children: ReactNode; warn?: boolean }) {
    return <span className={cn('inline-flex items-center gap-1.5 rounded-full px-3 py-1 backdrop-blur', warn ? 'bg-rose-500/80' : 'bg-white/15')}>{children}</span>;
}

/** A big figure on the hero's right side (countdown, score). */
export function HeroStat({ label, icon: Icon, value, note }: { label: string; icon?: LucideIcon; value: ReactNode; note?: ReactNode }) {
    return (
        <div className="text-right">
            <div className="flex items-center justify-end gap-2 text-xs font-medium tracking-[0.2em] text-white/70 uppercase">
                {Icon && <Icon className="size-3.5" />} {label}
            </div>
            <div className="mt-1 text-3xl font-semibold tabular-nums md:text-4xl">{value}</div>
            {note && <div className="mt-1 text-sm text-white/80">{note}</div>}
        </div>
    );
}

export const KPI_TONE = {
    indigo: ['from-indigo-500/15', 'text-indigo-600 dark:text-indigo-300'],
    violet: ['from-violet-500/15', 'text-violet-600 dark:text-violet-300'],
    sky: ['from-sky-500/15', 'text-sky-600 dark:text-sky-300'],
    emerald: ['from-emerald-500/15', 'text-emerald-600 dark:text-emerald-300'],
    amber: ['from-amber-500/15', 'text-amber-600 dark:text-amber-300'],
    rose: ['from-rose-500/15', 'text-rose-600 dark:text-rose-300'],
} as const;

export type KpiTone = keyof typeof KPI_TONE;

export function Kpi({ icon: Icon, title, tone, children, className }: { icon: LucideIcon; title: ReactNode; tone: KpiTone; children: ReactNode; className?: string }) {
    return (
        <div className={cn('relative shrink-0 overflow-hidden rounded-xl border bg-card p-5 shadow-sm', className)}>
            <div className={cn('pointer-events-none absolute inset-0 bg-gradient-to-br to-transparent opacity-70', KPI_TONE[tone][0])} />
            <div className="relative">
                <div className="flex items-center gap-2 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    <span className={cn('flex size-6 items-center justify-center rounded-md bg-background/70', KPI_TONE[tone][1])}>
                        <Icon className="size-3.5" />
                    </span>
                    {title}
                </div>
                <div className="mt-3">{children}</div>
            </div>
        </div>
    );
}

const sum = (xs: number[]) => xs.reduce((a, b) => a + b, 0);

export function StackBar({ parts }: { parts: { n: number; cls: string; label: string }[] }) {
    const total = sum(parts.map((p) => p.n)) || 1;

    return (
        <div className="mt-3">
            <div className="flex h-2 overflow-hidden rounded-full bg-muted">
                {parts.map((p) => (p.n > 0 ? <div key={p.label} className={p.cls} style={{ width: `${(100 * p.n) / total}%` }} /> : null))}
            </div>
            <div className="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-muted-foreground">
                {parts.map((p) => (
                    <span key={p.label} className="inline-flex items-center gap-1.5">
                        <span className={cn('size-2 rounded-full', p.cls)} />
                        {p.label} <span className="font-medium text-foreground tabular-nums">{p.n}</span>
                    </span>
                ))}
            </div>
        </div>
    );
}

export function Pill({ children, tone = 'zinc', className }: { children: ReactNode; tone?: 'indigo' | 'emerald' | 'sky' | 'zinc' | 'rose' | 'amber'; className?: string }) {
    const cls = {
        indigo: 'bg-indigo-600 text-white',
        emerald: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
        sky: 'bg-sky-500/15 text-sky-700 dark:text-sky-300',
        zinc: 'bg-muted text-muted-foreground',
        rose: 'bg-rose-500/15 text-rose-700 dark:text-rose-300',
        amber: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
    }[tone];

    return <span className={cn('inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium', cls, className)}>{children}</span>;
}

export function Section({ icon: Icon, title, hint, action, className, children }: { icon: LucideIcon; title: ReactNode; hint?: ReactNode; action?: ReactNode; className?: string; children: ReactNode }) {
    return (
        <section className={cn('flex flex-col gap-3', className)}>
            <div className="flex flex-wrap items-end gap-x-3 gap-y-1">
                <h2 className="flex items-center gap-2 text-base font-semibold tracking-tight">
                    <Icon className="size-4 text-muted-foreground" />
                    {title}
                </h2>
                {hint && <span className="text-xs text-muted-foreground">{hint}</span>}
                {action && <span className="ml-auto">{action}</span>}
            </div>
            {children}
        </section>
    );
}

export function MoreLink({ href, children }: { href: string; children: ReactNode }) {
    return (
        <Link href={href} className="inline-flex items-center gap-1 text-xs font-medium text-muted-foreground hover:text-foreground">
            {children} <ArrowRight className="size-3" />
        </Link>
    );
}

/** A tab bar as a row of pills (TabsList className) and its pills (TabsTrigger className). */
export const TABS_LIST = 'h-auto w-full flex-wrap justify-start gap-1 rounded-xl bg-muted/60 p-1';
export const TABS_TRIGGER = 'h-8 flex-none rounded-lg px-3.5 text-sm data-[state=active]:shadow-sm';

/** The frame around a table: a card with rounded corners. */
export const TABLE_FRAME = 'rounded-xl border bg-card shadow-sm';

/** The page body: the same width and spacing on every page. */
export const PAGE = 'mx-auto flex w-full max-w-[1600px] min-h-0 flex-1 flex-col gap-5 p-4 md:p-6';

export const signed = (v: number | null | undefined, d = 3) => (v === null || v === undefined ? '—' : `${v >= 0 ? '+' : ''}${v.toFixed(d)}`);

export const scoreTone = (s: number | null | undefined) =>
    s === null || s === undefined ? 'text-muted-foreground' : s >= 0.15 ? 'text-emerald-600 dark:text-emerald-400' : s >= 0 ? 'text-foreground' : 'text-rose-600 dark:text-rose-400';
