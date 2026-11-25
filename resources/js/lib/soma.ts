import type { TaskCell } from '@/types';

export const PROBLEM_SHORT: Record<string, string> = {
    'django__django-13821': 'dj-13821',
    'django__django-14373': 'dj-14373',
    'sympy__sympy-19637': 'sy-19637',
    'sympy__sympy-15875': 'sy-15875',
    'django__django-12419': 'dj-12419',
};

export function shortKey(key: string | null | undefined, n = 8): string {
    return key ? `${key.slice(0, n)}…` : '—';
}

export function num(value: number | null | undefined, digits = 3): string {
    return value === null || value === undefined || Number.isNaN(value) ? '—' : value.toFixed(digits);
}

export function times(value: number | null | undefined, digits = 2): string {
    return value === null || value === undefined ? '—' : `×${value.toFixed(digits)}`;
}

export function utc(iso: string | null | undefined, withSeconds = false): string {
    if (!iso) {
        return '—';
    }

    const d = new Date(iso);
    const pad = (x: number) => String(x).padStart(2, '0');
    const time = `${pad(d.getUTCHours())}:${pad(d.getUTCMinutes())}${withSeconds ? `:${pad(d.getUTCSeconds())}` : ''}`;

    return `${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())} ${time}`;
}

export function ago(iso: string | null | undefined, now: number = Date.now()): string {
    if (!iso) {
        return '—';
    }

    const s = Math.round((now - new Date(iso).getTime()) / 1000);

    if (s < 0) {
        return 'in ' + left(-s);
    }

    if (s < 90) {
        return `${s}s ago`;
    }

    if (s < 5400) {
        return `${Math.round(s / 60)}m ago`;
    }

    if (s < 48 * 3600) {
        return `${Math.round(s / 3600)}h ago`;
    }

    return `${Math.round(s / 86400)}d ago`;
}

export function left(seconds: number): string {
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);

    return h > 0 ? `${h}h ${String(m).padStart(2, '0')}m` : `${m}m`;
}

/** Quality as the platform scores it: compressed passes over baseline passes (0.8 is the line). */
export function quality(cell: TaskCell | undefined): number | null {
    if (!cell || cell.passed === null || !cell.baseline_passed) {
        return null;
    }

    return cell.passed / cell.baseline_passed;
}

export function cellTone(cell: TaskCell | undefined): string {
    if (!cell) {
        return 'text-muted-foreground';
    }

    if (cell.total && cell.finished < cell.total) {
        return 'bg-sky-500/10 text-sky-700 dark:text-sky-300';
    }

    const q = quality(cell);

    if (q === null) {
        return '';
    }

    if (q >= 1) {
        return 'bg-emerald-500/15 text-emerald-800 dark:text-emerald-300';
    }

    if (q >= 0.8) {
        return 'bg-emerald-500/5 text-emerald-700 dark:text-emerald-400';
    }

    if (q >= 0.5) {
        return 'bg-amber-500/15 text-amber-800 dark:text-amber-300';
    }

    return 'bg-red-500/15 text-red-800 dark:text-red-300';
}

/** Small status dots for the summary card. */
export const STATUS_DOT: Record<string, string> = {
    qualified: 'bg-emerald-500',
    evaluating: 'bg-amber-500',
    qualifying: 'bg-violet-500',
    'no api key': 'bg-red-400',
    scored: 'bg-emerald-600',
    screening: 'bg-sky-500',
    'in queue': 'bg-sky-300',
    'not qualified': 'bg-zinc-400',
    'failed review': 'bg-red-500',
};

export const STATUS_TONE: Record<string, string> = {
    qualified: 'bg-emerald-600 text-white',
    evaluating: 'bg-amber-500 text-white',
    qualifying: 'bg-violet-600 text-white',
    'no api key': 'bg-red-500 text-white',
    scored: 'bg-emerald-700 text-white',
    screening: 'bg-sky-600 text-white',
    'in queue': 'bg-sky-400 text-white',
    'not qualified': 'bg-zinc-500 text-white',
    'failed review': 'bg-red-600 text-white',
};

/** "agronholm__apscheduler-898" -> "apscheduler-898" for narrow column heads. */
export function shortTask(name: string): string {
    const tail = name.includes('__') ? name.split('__')[1] : name;

    return tail.length > 18 ? `${tail.slice(0, 17)}…` : tail;
}

export const BAND_TONE: Record<string, string> = {
    short: 'text-sky-700 dark:text-sky-300',
    medium: 'text-violet-700 dark:text-violet-300',
    long: 'text-amber-700 dark:text-amber-300',
};

export const EVENT_TONE: Record<string, string> = {
    upload: 'bg-sky-600 text-white',
    stage1_done: 'bg-emerald-600 text-white',
    status: 'bg-amber-600 text-white',
    registration: 'bg-violet-600 text-white',
};

export const CLASS_TONE: Record<string, string> = {
    'deep-all': 'border-red-500 text-red-700 dark:text-red-300',
    'short-only': 'border-orange-500 text-orange-700 dark:text-orange-300',
    'notice-like': 'border-amber-500 text-amber-700 dark:text-amber-300',
    light: 'border-emerald-500 text-emerald-700 dark:text-emerald-300',
    'failed-runs': 'border-zinc-400 text-zinc-500',
};
