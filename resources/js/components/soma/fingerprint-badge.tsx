import { Badge } from '@/components/ui/badge';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { CLASS_TONE, times } from '@/lib/soma';
import { cn } from '@/lib/utils';
import type { Fingerprint } from '@/types';

/**
 * Stage-1 fingerprint. The numbers are against the untouched agent: tokens per run from the platform's published
 * baseline (exact), steps estimated (≈). The class comes from steps against the reference miner 5GTwFUTB of the same
 * competition (exact, same platform): deep-all ≤ 0.75 everywhere, short-only ≤ 0.75 on the short problems,
 * notice-like ≤ 0.92, light above.
 */
export function FingerprintBadge({ fp, compact = false }: { fp: Fingerprint | null | undefined; compact?: boolean }) {
    if (!fp) {
        return <span className="text-xs text-muted-foreground">—</span>;
    }

    const untouched = fp.u_tokens_x !== undefined && fp.u_tokens_x !== null;

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span className="inline-flex items-center gap-1.5">
                    <Badge variant="outline" className={cn('font-normal', CLASS_TONE[fp.class])}>
                        {fp.class}
                    </Badge>
                    {!compact && (
                        <span className="font-mono text-xs text-muted-foreground" title={untouched ? 'steps (≈) · output, vs untouched' : 'steps · output per step, vs 5GTwFUTB'}>
                            {untouched ? `≈${times(fp.u_steps_x)} · ${times(fp.u_output_x)}` : `${times(fp.steps_x)} · ${times(fp.out_step_x)}`}
                        </span>
                    )}
                </span>
            </TooltipTrigger>
            <TooltipContent className="max-w-[340px]">
                {untouched && (
                    <>
                        <div className="mb-1 font-sans text-[11px] font-semibold">vs untouched (platform baseline, per run)</div>
                        <div className="grid grid-cols-2 gap-x-3 font-mono text-xs">
                            <span>tokens (weighted)</span>
                            <span>{times(fp.u_tokens_x, 3)}</span>
                            <span>output</span>
                            <span>{times(fp.u_output_x, 3)}</span>
                            <span>uncached input</span>
                            <span>{times(fp.u_uncached_x, 3)}</span>
                            <span>cached input</span>
                            <span>{times(fp.u_cached_x, 3)}</span>
                            <span>steps ≈</span>
                            <span>{times(fp.u_steps_x, 3)}</span>
                            <span>short problems ≈</span>
                            <span>{times(fp.u_short_x, 3)}</span>
                        </div>
                        <div className="mt-1 font-sans text-[10px] opacity-75">≈ steps: the baseline's output per run ÷ the untouched output per step</div>
                    </>
                )}
                <div className={cn('mb-1 font-sans text-[11px] font-semibold', untouched && 'mt-2')}>vs 5GTwFUTB, same round (sets the class)</div>
                <div className="grid grid-cols-2 gap-x-3 font-mono text-xs">
                    <span>steps</span>
                    <span>{times(fp.steps_x, 3)}</span>
                    <span>short problems</span>
                    <span>{times(fp.short_x, 3)}</span>
                    <span>output / step</span>
                    <span>{times(fp.out_step_x, 3)}</span>
                    <span>context @15</span>
                    <span>{times(fp.ctx_x, 3)}</span>
                </div>
                <div className="mt-2 grid grid-cols-2 gap-x-3 font-mono text-xs">
                    <span>re-bill slope</span>
                    <span>{fp.rebill_slope ?? '—'}</span>
                </div>
            </TooltipContent>
        </Tooltip>
    );
}
