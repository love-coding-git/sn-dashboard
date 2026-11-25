import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import type { BreadcrumbItem } from '@/types';

export default function AppLayout({
    breadcrumbs = [],
    fill = false,
    children,
}: {
    breadcrumbs?: BreadcrumbItem[];
    fill?: boolean;
    children: React.ReactNode;
}) {
    return (
        <AppLayoutTemplate breadcrumbs={breadcrumbs} fill={fill}>
            {children}
        </AppLayoutTemplate>
    );
}
