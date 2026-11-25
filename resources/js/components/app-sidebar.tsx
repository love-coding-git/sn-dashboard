import { Link, usePage } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { Activity, ChevronRight, Crown, ExternalLink, History, LayoutDashboard, Sparkles } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavUser } from '@/components/nav-user';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
} from '@/components/ui/sidebar';
import { home as hubRoute } from '@/routes';
import { overview as sn34Overview } from '@/routes/sn34';
import { home as somaHome, hotkeys, overview as board } from '@/routes/soma';
import type { NavItem } from '@/types';

/**
 * The sidebar (2026-11-25; user: "overview … 옆에 Yugos와 SOMA sidebar가 있고 그아래 해당 sub sidebar 가 차림표로 열리도록"):
 * the cross-subnet Overview first, then one menu per subnet that opens to its pages. The menu of the page you are on
 * starts open. Earlier SOMA rounds sit in their own sub-menu.
 */
type Sub = { title: string; href: string };

const SOMA_ITEMS: Sub[] = [
    { title: 'Round 14 overview', href: somaHome.url() },
    { title: 'Leaderboard', href: board.url() },
    { title: 'Our hotkeys', href: hotkeys.url() },
];

const SOMA_EARLIER: Sub[] = [
    [150, 13],
    [149, 12],
    [148, 11],
    [147, 10],
    [146, 9],
    [145, 8],
    [112, 7],
].map(([comp, round]) => ({ title: `Round ${round} (${comp})`, href: board.url({ query: { comp } }) }));

// the SN34 page's tabs, in the order the NPA/34 session chose (2026-11-25)
const SN34_ITEMS: Sub[] = [
    ['now', 'Runs now'],
    ['decisions', 'Decisions'],
    ['runs', 'All runs'],
    ['families', 'Families'],
    ['seats', 'Seat history'],
    ['datasets', 'Datasets'],
    ['card', 'Card (our routes)'],
    ['events', 'Events'],
    ['watch', 'Watch log'],
    ['discord', 'Discord'],
].map(([tab, title]) => ({ title, href: tab === 'now' ? sn34Overview.url() : sn34Overview.url({ query: { tab } }) }));

const footerNavItems: NavItem[] = [
    { title: 'thesoma.ai dashboard', href: 'https://thesoma.ai/dashboard', icon: ExternalLink },
    { title: 'competition.yugos.ai', href: 'https://competition.yugos.ai', icon: ExternalLink },
    { title: 'Taostats SN114', href: 'https://taostats.io/subnets/114', icon: Activity },
    { title: 'Taostats SN34', href: 'https://taostats.io/subnets/34', icon: Activity },
];

/** Is this link the page you are on? Path and, when the link has one, its ?tab= / ?comp= must match. */
function useActive(): (href: string) => boolean {
    const { url } = usePage();
    const here = new URL(url, 'http://local');

    return (href: string) => {
        const to = new URL(href, 'http://local');
        if (to.pathname !== here.pathname) {
            return false;
        }

        return ['tab', 'comp'].every((k) => (to.searchParams.get(k) ?? '') === (here.searchParams.get(k) ?? '') || (k === 'tab' && !to.searchParams.get(k) && here.searchParams.get(k) === 'now'));
    };
}

function SubLinks({ items, active }: { items: Sub[]; active: (href: string) => boolean }) {
    return (
        <>
            {items.map((it) => (
                <SidebarMenuSubItem key={it.href}>
                    <SidebarMenuSubButton asChild isActive={active(it.href)}>
                        <Link href={it.href} prefetch>
                            <span>{it.title}</span>
                        </Link>
                    </SidebarMenuSubButton>
                </SidebarMenuSubItem>
            ))}
        </>
    );
}

function SubnetMenu({ title, icon: Icon, open, children }: { title: string; icon: LucideIcon; open: boolean; children: React.ReactNode }) {
    return (
        <Collapsible asChild defaultOpen={open} className="group/collapsible">
            <SidebarMenuItem>
                <CollapsibleTrigger asChild>
                    <SidebarMenuButton tooltip={{ children: title }}>
                        <Icon />
                        <span className="font-medium">{title}</span>
                        <ChevronRight className="ml-auto transition-transform duration-200 group-data-[state=open]/collapsible:rotate-90" />
                    </SidebarMenuButton>
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <SidebarMenuSub>{children}</SidebarMenuSub>
                </CollapsibleContent>
            </SidebarMenuItem>
        </Collapsible>
    );
}

export function AppSidebar() {
    const page = usePage<{ auth?: { user?: unknown } }>();
    const active = useActive();
    const path = new URL(page.url, 'http://local').pathname;
    const onEarlier = path === board.url() && new URL(page.url, 'http://local').searchParams.has('comp');

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={hubRoute()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <SidebarGroup className="px-2 py-0">
                    <SidebarMenu>
                        <SidebarMenuItem>
                            <SidebarMenuButton asChild isActive={path === hubRoute.url()} tooltip={{ children: 'Overview' }}>
                                <Link href={hubRoute()} prefetch>
                                    <LayoutDashboard />
                                    <span>Overview</span>
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    </SidebarMenu>
                </SidebarGroup>

                <SidebarGroup className="px-2 py-0">
                    <SidebarGroupLabel>Subnets</SidebarGroupLabel>
                    <SidebarMenu>
                        <SubnetMenu title="SOMA · SN114" icon={Sparkles} open={path.startsWith('/soma')}>
                            <SubLinks items={SOMA_ITEMS} active={active} />
                            <Collapsible asChild defaultOpen={onEarlier} className="group/earlier">
                                <SidebarMenuSubItem>
                                    <CollapsibleTrigger asChild>
                                        <SidebarMenuSubButton className="cursor-pointer">
                                            <History className="size-3.5" />
                                            <span>Earlier rounds</span>
                                            <ChevronRight className="ml-auto size-3.5 transition-transform duration-200 group-data-[state=open]/earlier:rotate-90" />
                                        </SidebarMenuSubButton>
                                    </CollapsibleTrigger>
                                    <CollapsibleContent>
                                        <SidebarMenuSub className="mr-0 pr-0">
                                            <SubLinks items={SOMA_EARLIER} active={active} />
                                        </SidebarMenuSub>
                                    </CollapsibleContent>
                                </SidebarMenuSubItem>
                            </Collapsible>
                        </SubnetMenu>
                        <SubnetMenu title="Yugos · SN34" icon={Crown} open={path.startsWith('/sn34')}>
                            <SubLinks items={SN34_ITEMS} active={active} />
                        </SubnetMenu>
                    </SidebarMenu>
                </SidebarGroup>
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                {page.props.auth?.user ? <NavUser /> : null}
            </SidebarFooter>
        </Sidebar>
    );
}
