import type { SVGAttributes } from 'react';

/**
 * The Subnet Watch mark (2026-11-25 redesign): a pulse line over two subnet nodes on a gradient tile - indigo for SOMA,
 * amber for Yugos - so the one dashboard that watches both reads at a glance. Self-contained colours: it needs no wrapper.
 */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
            <defs>
                <linearGradient id="sw-tile" x1="0" y1="0" x2="32" y2="32" gradientUnits="userSpaceOnUse">
                    <stop offset="0" stopColor="#4f46e5" />
                    <stop offset="0.55" stopColor="#7c3aed" />
                    <stop offset="1" stopColor="#f59e0b" />
                </linearGradient>
            </defs>
            <rect width="32" height="32" rx="8" fill="url(#sw-tile)" />
            <path d="M5 18h5l2.5-6 4 11 3.5-8 2 3H27" fill="none" stroke="#fff" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" />
            <circle cx="10" cy="9" r="2.2" fill="#c7d2fe" />
            <circle cx="22" cy="9" r="2.2" fill="#fde68a" />
        </svg>
    );
}
