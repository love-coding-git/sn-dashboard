import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import type { AppLayoutProps } from '@/types';
import { cn } from '@/lib/utils';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
    fill = false,
}: AppLayoutProps) {
    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent
                variant="sidebar"
                className={cn(
                    'min-w-0 overflow-x-clip',
                    // a window-high frame: only the page's own tables scroll, never the page
                    fill && 'h-svh max-h-svh overflow-hidden md:peer-data-[variant=inset]:h-[calc(100svh-1rem)] md:peer-data-[variant=inset]:max-h-[calc(100svh-1rem)]',
                )}
            >
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                {fill ? <div className="flex min-h-0 flex-1 flex-col">{children}</div> : children}
            </AppContent>
        </AppShell>
    );
}
