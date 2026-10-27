/** Standard-Akzentfarbe (Feuerwehrrot), wie AccentColor::DEFAULT in PHP und --accent-default. */
export const DEFAULT_ACCENT = '#b71c1c';

const ACCENT_PATTERN = /^#[0-9a-f]{6}$/;

/**
 * Setzt die Stadtfarbe als --accent und in <meta name="theme-color">. Der Server liefert sie
 * geprüft als #rrggbb; alles andere fällt auf die Standardfarbe zurück.
 */
export function applyAccent(root: HTMLElement, color: string | null): string {
    const accent = color !== null && ACCENT_PATTERN.test(color) ? color : DEFAULT_ACCENT;
    if (accent === DEFAULT_ACCENT) {
        root.style.removeProperty('--accent');
    } else {
        root.style.setProperty('--accent', accent);
    }
    root.ownerDocument.querySelector('meta[name="theme-color"]')?.setAttribute('content', accent);
    return accent;
}
