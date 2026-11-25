import { useState } from 'react';

/** The open tab, kept in the address bar (?tab=) so a reload or a copied link opens the same table. */
export function useTab(defaultTab: string, allowed: string[]): [string, (tab: string) => void] {
    const initial = typeof window === 'undefined' ? null : new URLSearchParams(window.location.search).get('tab');
    const [tab, setTab] = useState(initial && allowed.includes(initial) ? initial : defaultTab);

    const change = (next: string) => {
        setTab(next);
        const url = new URL(window.location.href);
        url.searchParams.set('tab', next);
        window.history.replaceState(window.history.state, '', url);
    };

    return [tab, change];
}
