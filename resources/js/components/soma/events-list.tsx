import { Link } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { EVENT_TONE, num, shortKey, utc } from '@/lib/soma';
import { cn } from '@/lib/utils';
import { miner as minerRoute } from '@/routes/soma';
import type { WatchEvent } from '@/types';

/** Watcher events, newest first. */
export function EventsList({ events, competition, known }: { events: WatchEvent[]; competition: number; known?: Set<string> }) {
    if (events.length === 0) {
        return <p className="text-sm text-muted-foreground">No events yet.</p>;
    }

    return (
        <ul className="divide-y text-sm">
            {events.map((e) => (
                <li key={e.id} className="flex items-start gap-2 py-1.5">
                    <span className="w-[74px] shrink-0 font-mono text-xs text-muted-foreground">{utc(e.at)}</span>
                    <Badge className={cn('shrink-0 border-transparent', EVENT_TONE[e.type] ?? 'bg-zinc-500 text-white')}>{e.type.replace('_', ' ')}</Badge>
                    <div className="min-w-0 flex-1">
                        {!known || known.has(e.hotkey) ? (
                            <Link href={minerRoute({ competition, hotkey: e.hotkey })} className="font-mono text-xs hover:underline">
                                {shortKey(e.hotkey)}
                            </Link>
                        ) : (
                            <span className="font-mono text-xs">{shortKey(e.hotkey)}</span>
                        )}
                        {e.is_ours && <Badge className="ml-1 border-transparent bg-indigo-600 text-white">ours</Badge>}
                        <span className="ml-1 text-xs text-muted-foreground">
                            {e.type === 'registration'
                                ? `uid ${e.uid ?? '?'} · owner ${shortKey(e.coldkey)}`
                                : [e.data?.from && e.data?.status && e.data.from !== e.data.status ? `${e.data.from} → ${e.data.status}` : e.data?.status, e.data?.stage1 !== undefined && e.data?.stage1 !== null ? `stage 1 ${num(Number(e.data.stage1))}` : null]
                                      .filter(Boolean)
                                      .join(' · ')}
                        </span>
                    </div>
                </li>
            ))}
        </ul>
    );
}
