import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { cellTone, num, quality, times } from '@/lib/soma';
import { cn } from '@/lib/utils';
import type { TaskCell } from '@/types';

/** One stage-1 problem of one miner: passes / runs, coloured by quality against the baseline (0.8 is the line). */
export function ProblemCell({ cell }: { cell: TaskCell | undefined }) {
    if (!cell) {
        return <span className="text-muted-foreground">·</span>;
    }

    const running = cell.total !== null && cell.finished < cell.total;
    const q = quality(cell);
    if (cell.finished === 0) {
        // nothing graded yet: either queued, or runs recorded and waiting for the grader
        return (
            <div className="rounded px-1.5 py-0.5 text-center font-mono text-[10px] leading-tight text-muted-foreground" title={cell.run ? `${cell.run} runs recorded, not graded yet` : 'not started'}>
                {cell.run ? `${cell.run} run` : '·'}
            </div>
        );
    }

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <div className={cn('rounded px-1.5 py-0.5 text-center font-mono text-xs leading-tight', cellTone(cell))}>
                    <div>
                        {cell.passed ?? 0}/{running ? cell.finished : (cell.total ?? '?')}
                    </div>
                    <div className="text-[10px] opacity-75">{running ? 'running' : num(cell.score, 2)}</div>
                </div>
            </TooltipTrigger>
            <TooltipContent>
                <div className="grid grid-cols-2 gap-x-3 font-mono text-xs">
                    <span>passed</span>
                    <span>
                        {cell.passed ?? 0} of {cell.finished} finished
                    </span>
                    <span>baseline</span>
                    <span>
                        {cell.baseline_passed}/{cell.baseline_total}
                    </span>
                    <span>quality</span>
                    <span>{q === null ? '—' : q.toFixed(2)}</span>
                    <span>score</span>
                    <span>{num(cell.score)}</span>
                    <span>tokens</span>
                    <span>{times(cell.ratio)} of baseline</span>
                    <span>steps / run</span>
                    <span>{num(cell.steps, 1)}</span>
                </div>
            </TooltipContent>
        </Tooltip>
    );
}
