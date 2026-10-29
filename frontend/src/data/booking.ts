import type { BookingResponse, DeviceListItem } from '../api/devices';

/**
 * Buchung (Umbuchen/Status) aus der Umbuchen-Ansicht: Kennung gegen doppelte Wirkung, Prüfung des
 * Kommentars und die Bestätigung auf „Suchen“ (KONZEPT.md, "User am Handy – Entscheidungen", 13,
 * 18 und 22).
 */

/** Pflichtkommentar bei „defekt“: mindestens so viele Zeichen (prüft nur die Oberfläche). */
export const MIN_DEFECT_COMMENT_LENGTH = 5;

/** Höchstlänge des Kommentars wie DeviceText::COMMENT_MAX_LENGTH in PHP. */
export const MAX_COMMENT_LENGTH = 500;

/**
 * Kommentar wie gesendet: Der Server erlaubt nur eine Zeile ohne Steuerzeichen; Zeilenumbrüche und
 * Tabulatoren aus dem Textfeld werden zu Leerzeichen, Leerraum am Rand entfällt.
 */
export function normalizeComment(text: string): string {
    return text.replace(/\s*[\u0000-\u001f\u007f-\u009f]+\s*/g, ' ').trim();
}

/** Länge in Unicode-Zeichen (wie mb_strlen), nicht in UTF-16-Einheiten. */
export function commentLength(text: string): number {
    return Array.from(normalizeComment(text)).length;
}

/** UUID v4 für `client_ref`; ohne `randomUUID` (ältere Browser) aus Zufallsbytes. */
export function newClientRef(
    source: Pick<Crypto, 'getRandomValues'> & Partial<Pick<Crypto, 'randomUUID'>> = crypto,
): string {
    if (source.randomUUID !== undefined) {
        return source.randomUUID();
    }
    const bytes = source.getRandomValues(new Uint8Array(16));
    bytes[6] = ((bytes[6] ?? 0) & 0x0f) | 0x40;
    bytes[8] = ((bytes[8] ?? 0) & 0x3f) | 0x80;
    const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

/**
 * Hält die `client_ref` einer Buchung: Wird derselbe Inhalt erneut gesendet (z. B. nach einem
 * Netzwerkfehler, bei dem offen ist, ob der Server gespeichert hat), bleibt die Kennung gleich und
 * der Server wiederholt nur das Ergebnis. Anderer Inhalt (andere Auswahl, neue Version nach einem
 * Konflikt) ist eine neue Buchung mit neuer Kennung; sonst antwortete der Server mit
 * `client_ref_conflict`.
 */
export class ClientRefKeeper {
    private last: { readonly key: string; readonly ref: string } | null = null;

    constructor(private readonly create: () => string = () => newClientRef()) {}

    refFor(content: unknown): string {
        const key = JSON.stringify(content);
        if (this.last?.key !== key) {
            this.last = { key, ref: this.create() };
        }
        return this.last.ref;
    }
}

/** Grüne Bestätigung nach dem Speichern, z. B. „M1 → HLF 20 / G2 · einsatzbereit“. */
export function confirmationText(device: DeviceListItem): string {
    return `${device.identifier} → ${device.location.path} · ${device.statusLabel}`;
}

/** Hinweis für „Suchen“ nach dem Speichern: `success` grün, sonst die Meldung des Servers. */
export interface BookingNotice {
    readonly kind: 'success' | 'info';
    readonly text: string;
}

export function bookingNotice(response: BookingResponse): BookingNotice {
    return response.result.outcome === 'applied'
        ? { kind: 'success', text: confirmationText(response.device) }
        : { kind: 'info', text: response.result.message };
}

/**
 * Der Hinweis steht in sessionStorage: Beim Seitenwechsel nach dem Speichern kann die App eine neue
 * Version laden (runtime/appUpdate.ts), er muss das Neuladen überstehen.
 */
const NOTICE_STORAGE_KEY = 'ff-booking-notice';
let noticeFallback: BookingNotice | null = null;

type SessionStorage = Pick<Storage, 'getItem' | 'setItem' | 'removeItem'>;

function sessionStore(): SessionStorage | null {
    try {
        return window.sessionStorage;
    } catch {
        return null;
    }
}

export function setBookingNotice(
    notice: BookingNotice | null,
    storage: SessionStorage | null = sessionStore(),
): void {
    noticeFallback = notice;
    try {
        if (notice === null) {
            storage?.removeItem(NOTICE_STORAGE_KEY);
        } else {
            storage?.setItem(NOTICE_STORAGE_KEY, JSON.stringify(notice));
        }
    } catch {
        // Ohne Speicher gilt der Hinweis nur bis zum nächsten Neuladen.
    }
}

function parseNotice(text: string | null): BookingNotice | null {
    if (text === null) {
        return null;
    }
    try {
        const value: unknown = JSON.parse(text);
        if (typeof value !== 'object' || value === null) {
            return null;
        }
        const { kind, text: noticeText } = value as Record<string, unknown>;
        return (kind === 'success' || kind === 'info') && typeof noticeText === 'string'
            ? { kind, text: noticeText }
            : null;
    } catch {
        return null;
    }
}

/** Hinweis einmal abholen („Suchen“ zeigt ihn, danach ist er weg). */
export function takeBookingNotice(
    storage: SessionStorage | null = sessionStore(),
): BookingNotice | null {
    let notice = noticeFallback;
    if (storage !== null) {
        try {
            notice = parseNotice(storage.getItem(NOTICE_STORAGE_KEY));
        } catch {
            // Ersatzwert verwenden.
        }
    }
    setBookingNotice(null, storage);
    return notice;
}
