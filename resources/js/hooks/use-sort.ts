import { useMemo, useState } from 'react';

export type SortDir = 'asc' | 'desc';
export type SortValue = string | number | null | undefined;
export type SortState = { key: string; dir: SortDir } | null;

/** A sortable column: how to read its value, and which way the first click sorts (scores high first, ranks low first). */
export type SortColumn<T> = { value: (row: T) => SortValue; first?: SortDir };

const empty = (v: SortValue) => v === null || v === undefined || v === '' || (typeof v === 'number' && Number.isNaN(v));

function compare(a: SortValue, b: SortValue): number {
    if (typeof a === 'number' && typeof b === 'number') {
        return a - b;
    }

    return String(a).localeCompare(String(b), undefined, { numeric: true, sensitivity: 'base' });
}

function read<T>(param: string, columns: Record<string, SortColumn<T>>): SortState {
    if (typeof window === 'undefined') {
        return null;
    }

    const [key, dir] = (new URLSearchParams(window.location.search).get(param) ?? '').split('.');

    return key in columns && (dir === 'asc' || dir === 'desc') ? { key, dir } : null;
}

function write(param: string, state: SortState): void {
    const url = new URL(window.location.href);

    if (state) {
        url.searchParams.set(param, `${state.key}.${state.dir}`);
    } else {
        url.searchParams.delete(param);
    }

    window.history.replaceState(window.history.state, '', url);
}

/**
 * Click-to-sort for one table. The order is kept in the address bar (?s_<id>=key.dir), so a reload, a copied link or
 * the minute poll keeps it. Empty values always go last; ties keep the server's order. Clicking a column sorts it in its
 * natural direction, again reverses it, a third time returns to the server's order.
 */
export function useSort<T>(id: string, rows: T[], columns: Record<string, SortColumn<T>>) {
    const param = `s_${id}`;
    const [sort, setSort] = useState<SortState>(() => read(param, columns));

    const sorted = useMemo(() => {
        const column = sort ? columns[sort.key] : undefined;

        if (!sort || !column) {
            return rows;
        }

        const sign = sort.dir === 'asc' ? 1 : -1;

        return rows
            .map((row, index) => ({ row, index, value: column.value(row) }))
            .sort((a, b) => {
                const ea = empty(a.value);
                const eb = empty(b.value);

                if (ea || eb) {
                    return ea === eb ? a.index - b.index : ea ? 1 : -1;
                }

                return sign * compare(a.value, b.value) || a.index - b.index;
            })
            .map((x) => x.row);
    }, [rows, sort, columns]);

    const toggle = (key: string) => {
        const first = columns[key]?.first ?? 'asc';
        const next: SortState = sort?.key !== key ? { key, dir: first } : sort.dir === first ? { key, dir: first === 'asc' ? 'desc' : 'asc' } : null;
        setSort(next);
        write(param, next);
    };

    return { sorted, sort, toggle };
}
