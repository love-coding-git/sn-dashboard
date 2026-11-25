import { useState } from 'react';

/** A value kept in the address bar (?<name>=), so a reload or a copied link opens the same view; null removes it. */
export function useQueryParam(name: string): [string | null, (value: string | null) => void] {
    const [value, setValue] = useState<string | null>(() => (typeof window === 'undefined' ? null : new URLSearchParams(window.location.search).get(name)));

    const change = (next: string | null) => {
        setValue(next);
        const url = new URL(window.location.href);

        if (next) {
            url.searchParams.set(name, next);
        } else {
            url.searchParams.delete(name);
        }

        window.history.replaceState(window.history.state, '', url);
    };

    return [value, change];
}
