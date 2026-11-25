import { Check, Copy } from 'lucide-react';
import { useState } from 'react';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { shortKey } from '@/lib/soma';
import { cn } from '@/lib/utils';

/** An ss58 address shown short; hover shows it whole, click copies it. */
export function KeyLabel({ value, className, n = 8 }: { value: string | null; className?: string; n?: number }) {
    const [copied, setCopied] = useState(false);

    if (!value) {
        return <span className="text-muted-foreground">—</span>;
    }

    const copy = () => {
        void navigator.clipboard?.writeText(value);
        setCopied(true);
        window.setTimeout(() => setCopied(false), 1200);
    };

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <button type="button" onClick={copy} className={cn('inline-flex items-center gap-1 font-mono text-xs hover:underline', className)}>
                    {shortKey(value, n)}
                    {copied ? <Check className="size-3" /> : <Copy className="size-3 opacity-0 group-hover:opacity-60" />}
                </button>
            </TooltipTrigger>
            <TooltipContent className="font-mono">{value}</TooltipContent>
        </Tooltip>
    );
}
