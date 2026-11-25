import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react';
import type { ReactNode } from 'react';
import { TableHead } from '@/components/ui/table';
import type { SortState } from '@/hooks/use-sort';
import { cn } from '@/lib/utils';

type SortProps = { sort: SortState; onSort: (key: string) => void };

/** A label that sorts its column on click; the arrow shows the current order. */
export function SortButton({ id, label, sort, onSort }: { id: string; label: ReactNode } & SortProps) {
    const active = sort?.key === id;
    const Icon = !active ? ArrowUpDown : sort.dir === 'asc' ? ArrowUp : ArrowDown;

    return (
        <button
            type="button"
            onClick={() => onSort(id)}
            title={active ? `sorted ${sort.dir === 'asc' ? 'ascending' : 'descending'} - click to ${sort.dir === 'asc' ? 'reverse' : 'change'}` : 'click to sort'}
            className="inline-flex items-center gap-1 rounded-sm whitespace-nowrap hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
        >
            {label}
            <Icon className={cn('size-3.5 shrink-0', active ? 'text-foreground' : 'text-muted-foreground/60')} />
        </button>
    );
}

/** A table header cell for one sortable column. */
export function SortHead({ id, label, sort, onSort, className }: { id: string; label: ReactNode; className?: string } & SortProps) {
    const active = sort?.key === id;

    return (
        <TableHead className={className} aria-sort={active ? (sort.dir === 'asc' ? 'ascending' : 'descending') : 'none'}>
            <SortButton id={id} label={label} sort={sort} onSort={onSort} />
        </TableHead>
    );
}
