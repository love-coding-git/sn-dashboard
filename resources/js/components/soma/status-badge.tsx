import { Badge } from '@/components/ui/badge';
import { STATUS_TONE } from '@/lib/soma';
import { cn } from '@/lib/utils';

export function StatusBadge({ status }: { status: string | null }) {
    if (!status) {
        return <span className="text-muted-foreground">—</span>;
    }

    return <Badge className={cn('border-transparent capitalize', STATUS_TONE[status] ?? 'bg-zinc-400 text-white')}>{status}</Badge>;
}
