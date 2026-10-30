import type { LocationKind } from '../api/locations';

/** Pfade (SVG, 24 × 24, Strich) der Symbole je Art eines Standorts der Ebene 1. */
export const KIND_ICONS: Readonly<Record<LocationKind, readonly string[]>> = {
    vehicle: [
        'M2 16V7h12v9',
        'M14 10h4l3 3v3h-7',
        'M4.5 17a2 2 0 1 0 4 0a2 2 0 1 0 -4 0',
        'M15.5 17a2 2 0 1 0 4 0a2 2 0 1 0 -4 0',
    ],
    room: ['M3 21h18', 'M5 21V8l7-4 7 4v13', 'M10 21v-5h4v5'],
    external: ['M14 4h6v6', 'M20 4l-9 9', 'M18 14v6H4V6h6'],
};

/** Fach (Unterstandort). */
export const SUB_ICON: readonly string[] = [
    'M4 7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z',
    'M4 11h16',
];
