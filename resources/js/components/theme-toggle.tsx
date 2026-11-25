import type { LucideIcon } from 'lucide-react';
import { Monitor, Moon, Sun } from 'lucide-react';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import type { Appearance } from '@/hooks/use-appearance';
import { useAppearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';

const MODES: { value: Appearance; icon: LucideIcon; label: string }[] = [
    { value: 'light', icon: Sun, label: 'Light' },
    { value: 'dark', icon: Moon, label: 'Dark' },
    { value: 'system', icon: Monitor, label: 'System' },
];

/** Light / dark / system switch for the header. The choice is kept in a cookie and local storage (use-appearance). */
export function ThemeToggle({ className }: { className?: string }) {
    const { appearance, updateAppearance } = useAppearance();

    return (
        <div role="radiogroup" aria-label="Colour theme" className={cn('inline-flex items-center gap-0.5 rounded-lg border bg-muted/50 p-0.5', className)}>
            {MODES.map(({ value, icon: Icon, label }) => (
                <Tooltip key={value}>
                    <TooltipTrigger asChild>
                        <button
                            type="button"
                            role="radio"
                            aria-checked={appearance === value}
                            aria-label={label}
                            onClick={() => updateAppearance(value)}
                            className={cn(
                                'flex h-7 w-8 items-center justify-center rounded-md transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                appearance === value ? 'bg-background text-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            <Icon className="size-4" />
                        </button>
                    </TooltipTrigger>
                    <TooltipContent>{label}</TooltipContent>
                </Tooltip>
            ))}
        </div>
    );
}
