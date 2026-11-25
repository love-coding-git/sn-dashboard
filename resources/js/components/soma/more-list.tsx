import type { ReactNode } from 'react';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';

/** Shows the first `limit` items and folds the rest behind a "+N" button that opens them all. */
export function MoreList<T>({ items, limit = 4, render, title, empty = '—' }: { items: T[]; limit?: number; render: (item: T) => ReactNode; title?: string; empty?: ReactNode }) {
    if (items.length === 0) {
        return <span className="text-muted-foreground">{empty}</span>;
    }

    const shown = items.slice(0, limit);
    const rest = items.length - shown.length;

    return (
        <div className="flex flex-wrap items-center gap-1">
            {shown.map((item, i) => (
                <span key={i}>{render(item)}</span>
            ))}
            {rest > 0 && (
                <Popover>
                    <PopoverTrigger asChild>
                        <button type="button" className="rounded border px-1.5 py-0.5 text-xs text-muted-foreground hover:bg-muted">
                            +{rest} more
                        </button>
                    </PopoverTrigger>
                    <PopoverContent className="max-h-80 w-96 overflow-auto">
                        {title && <div className="mb-2 text-sm font-medium">{title}</div>}
                        <div className="flex flex-wrap gap-1">
                            {items.map((item, i) => (
                                <span key={i}>{render(item)}</span>
                            ))}
                        </div>
                    </PopoverContent>
                </Popover>
            )}
        </div>
    );
}
