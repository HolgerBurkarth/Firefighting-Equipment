/**
 * Teilen und Kopieren (Einladungscodes, KONZEPT.md "Admin-Konsole – Entscheidungen", 30): „Link
 * teilen“ über die Web Share API, ohne sie über die Zwischenablage.
 */

/** Was beim Teilen geschah: geteilt, stattdessen kopiert, abgebrochen oder nicht möglich. */
export type ShareOutcome = 'shared' | 'copied' | 'cancelled' | 'failed';

/** Teil von `navigator`, den Teilen und Kopieren brauchen (in Tests ersetzbar). */
export interface ShareNavigator {
    readonly share?: (data: ShareData) => Promise<void>;
    readonly canShare?: (data: ShareData) => boolean;
    readonly clipboard?: { readonly writeText?: (text: string) => Promise<void> };
}

/** Text in die Zwischenablage; false, wenn der Browser es nicht erlaubt oder nicht kann. */
export async function copyText(text: string, nav: ShareNavigator = navigator): Promise<boolean> {
    const writeText = nav.clipboard?.writeText;
    if (writeText === undefined) {
        return false;
    }
    try {
        await writeText.call(nav.clipboard, text);
        return true;
    } catch {
        return false;
    }
}

/**
 * Teilt Titel, Text und Link (Handy: Messenger, Mail …). Ohne Web Share API, oder lehnt der Browser
 * ab (z. B. am PC ohne Teilen-Dienst), kommt der Link in die Zwischenablage. Bricht die Person das
 * Teilen ab, geschieht nichts weiter.
 */
export async function shareLink(
    data: { readonly title: string; readonly text: string; readonly url: string },
    nav: ShareNavigator = navigator,
): Promise<ShareOutcome> {
    const shareData: ShareData = { title: data.title, text: data.text, url: data.url };
    const share = nav.share;
    if (share !== undefined && (nav.canShare?.call(nav, shareData) ?? true)) {
        try {
            await share.call(nav, shareData);
            return 'shared';
        } catch (error) {
            if (error instanceof DOMException && error.name === 'AbortError') {
                return 'cancelled';
            }
        }
    }
    return (await copyText(data.url, nav)) ? 'copied' : 'failed';
}
