import { shallowRef } from 'vue';
import type { ApiResult } from '../api/client';
import type {
    BookableStatus,
    BookingOutcome,
    BookingResponse,
    DeviceListItem,
    StateChangeRequest,
} from '../api/devices';
import type { LocalDb } from '../runtime/localDb';

/**
 * Warteschlange der Offline-Buchungen (KONZEPT.md, "Offline-Modus – Entscheidungen", 52–55): je
 * Stadt und Konto in IndexedDB (Tabelle `queue`), gesendet der Reihe nach, älteste zuerst, mit
 * `client_ref` und Aktionszeit (`occurred_at`).
 */

/** Zustand einer Buchung: wartet, wird gesendet, angekommen, braucht eine Entscheidung, Fehler. */
export type QueuedState = 'waiting' | 'sending' | 'done' | 'stale' | 'failed';

export interface QueuedBooking {
    /** `client_ref` (UUID v4), zugleich Schlüssel in der Liste. */
    readonly clientRef: string;
    readonly deviceId: number;
    /** Für die Anzeige: Kennung und Gerätetyp beim Erfassen. */
    readonly identifier: string;
    readonly deviceTypeName: string;
    /** Version des Geräts beim Erfassen. */
    readonly version: number;
    readonly locationId?: number;
    readonly locationPath?: string;
    readonly status?: BookableStatus;
    readonly statusLabel?: string;
    readonly comment?: string;
    /** Aktionszeit auf dem Handy, `JJJJ-MM-TTTHH:MM:SSZ`. */
    readonly occurredAt: string;
    readonly state: QueuedState;
    /** Nach Rückfrage trotzdem senden (alte Buchung). */
    readonly confirmedStale?: boolean;
    /** Ergebnis des Servers (state `done`). */
    readonly result?: {
        readonly outcome: BookingOutcome;
        readonly outcomeLabel: string;
        readonly message: string;
    };
    /** Fehler (state `failed`) bzw. Grund der Rückfrage (state `stale`). */
    readonly problem?: string | undefined;
}

/** Grenzen aus GET client-config; Standard wie in der Konfiguration des Servers (7 Tage). */
export interface QueueLimits {
    readonly maxAgeDays: number;
    readonly queueMaxAgeDays: number;
}

export const DEFAULT_QUEUE_LIMITS: QueueLimits = { maxAgeDays: 7, queueMaxAgeDays: 7 };

const DAY_MS = 86_400_000;

/** Aktionszeit wie der Server sie annimmt: UTC, auf Sekunden gekürzt. */
export function actionTime(now: Date): string {
    return `${now.toISOString().slice(0, 19)}Z`;
}

/** true, wenn die Buchung länger als `queueMaxAgeDays` wartet (genau auf der Grenze noch nicht). */
export function isStale(booking: QueuedBooking, limits: QueueLimits, now: Date): boolean {
    return now.getTime() - Date.parse(booking.occurredAt) > limits.queueMaxAgeDays * DAY_MS;
}

/**
 * Darf offline gebucht werden? Nur mit einer Kopie der Daten, die höchstens `maxAgeDays` alt ist
 * (Entscheidung 54). null = ja, sonst die Meldung.
 */
export function offlineBookingProblem(
    syncedAt: string | null,
    limits: QueueLimits,
    now: Date,
): string | null {
    if (syncedAt === null) {
        return 'Ohne Verbindung kann erst gebucht werden, wenn die Geräte einmal geladen wurden. Bitte mit dem Internet verbinden.';
    }
    return now.getTime() - Date.parse(syncedAt) > limits.maxAgeDays * DAY_MS
        ? 'Daten zu alt, bitte mit dem Internet verbinden.'
        : null;
}

/** Text einer Buchung: „M1 → HLF 20 / G2 · defekt“ (nur Geändertes). */
export function queuedText(booking: QueuedBooking): string {
    const parts = [booking.locationPath, booking.statusLabel].filter(
        (part): part is string => part !== undefined,
    );
    return `${booking.identifier} → ${parts.join(' · ')}`;
}

/** Gerät mit dem gewünschten Stand (sofort in der lokalen Liste, Entscheidung 52). */
export function pendingDevice(device: DeviceListItem, booking: QueuedBooking): DeviceListItem {
    const path = booking.locationPath;
    return {
        ...device,
        ...(booking.locationId !== undefined &&
            path !== undefined && {
                location: {
                    id: booking.locationId,
                    name: path.split(' / ').at(-1) ?? path,
                    path,
                },
            }),
        ...(booking.status !== undefined && {
            status: booking.status,
            statusLabel: booking.statusLabel ?? device.statusLabel,
        }),
        stateChangedAt: booking.occurredAt,
    };
}

/** Meldungen zu Fehlern der Warteschlange (Alltagssprache, Code nur für den Support). */
function problemText(code: string, message: string | null): string {
    switch (code) {
        case 'clock_implausible':
            return 'Die Uhr dieses Geräts geht vor. Bitte Datum und Uhrzeit des Handys prüfen; diese Buchung lässt sich nicht senden.';
        case 'stale_action':
            return 'Diese Buchung ist älter als erlaubt.';
        case 'client_ref_conflict':
            return 'Diese Buchung passt nicht mehr zu einer schon gesendeten.';
        default:
            return message ?? 'Der Server hat die Buchung abgelehnt.';
    }
}

export interface BookingQueueOptions {
    readonly db: LocalDb;
    readonly post: (id: number, request: StateChangeRequest) => Promise<ApiResult<BookingResponse>>;
    readonly now?: () => Date;
    /** Angekommene Buchung: Stand des Servers übernehmen (Liste, Kopie). */
    readonly onSent?: (response: BookingResponse) => void;
}

export interface QueueState {
    readonly key: string | null;
    readonly bookings: readonly QueuedBooking[];
    readonly sending: boolean;
}

/** Warteschlange eines Kontos; `use(key)` wechselt das Konto (null = keins). */
export class BookingQueue {
    private readonly current = shallowRef<QueueState>({ key: null, bookings: [], sending: false });
    private limits: QueueLimits = DEFAULT_QUEUE_LIMITS;
    private running: Promise<void> | null = null;
    private generation = 0;
    private writes = Promise.resolve();

    constructor(private readonly options: BookingQueueOptions) {}

    get state(): QueueState {
        return this.current.value;
    }

    /** Noch nicht angekommene Buchungen (für die Warnung beim Abmelden). */
    get unsent(): readonly QueuedBooking[] {
        return this.current.value.bookings.filter((booking) => booking.state !== 'done');
    }

    setLimits(limits: QueueLimits): void {
        this.limits = limits;
    }

    get currentLimits(): QueueLimits {
        return this.limits;
    }

    private now(): Date {
        return this.options.now?.() ?? new Date();
    }

    /** Konto wechseln: lädt dessen Warteschlange (oder leert sie bei null). */
    async use(key: string | null): Promise<void> {
        if (key === this.current.value.key) {
            return;
        }
        this.generation += 1;
        const generation = this.generation;
        this.current.value = { key, bookings: [], sending: false };
        if (key === null) {
            return;
        }
        const stored = await this.options.db.get('queue', key);
        if (generation !== this.generation) {
            return;
        }
        const bookings = Array.isArray(stored) ? (stored as QueuedBooking[]) : [];
        // Eine beim Schließen unterbrochene Sendung gilt wieder als wartend (client_ref schützt).
        this.current.value = {
            key,
            bookings: bookings.map((booking) =>
                booking.state === 'sending' ? { ...booking, state: 'waiting' } : booking,
            ),
            sending: false,
        };
    }

    /** Löscht die Warteschlange des Kontos `key` (Abmelden). */
    async remove(key: string): Promise<void> {
        if (this.current.value.key === key) {
            this.generation += 1;
            this.current.value = { key: null, bookings: [], sending: false };
        }
        this.writes = this.writes.then(async () => {
            await this.options.db.delete('queue', key);
        });
        await this.writes;
    }

    private update(change: (bookings: readonly QueuedBooking[]) => QueuedBooking[]): void {
        const state = this.current.value;
        if (state.key === null) {
            return;
        }
        const bookings = change(state.bookings);
        this.current.value = { ...state, bookings };
        const key = state.key;
        this.writes = this.writes.then(async () => {
            await this.options.db.put('queue', key, bookings);
        });
    }

    private set(clientRef: string, change: Partial<QueuedBooking>): void {
        this.update((bookings) =>
            bookings.map((booking) =>
                booking.clientRef === clientRef ? { ...booking, ...change } : booking,
            ),
        );
    }

    /** Neue Buchung anhängen (gespeichert, bevor die Seite weitergeht). */
    async add(booking: Omit<QueuedBooking, 'state'>): Promise<boolean> {
        if (this.current.value.key === null) {
            return false;
        }
        this.update((bookings) => [...bookings, { ...booking, state: 'waiting' }]);
        await this.writes;
        return true;
    }

    /** Alte Buchung trotzdem senden (Entscheidung 54). */
    confirmStale(clientRef: string): Promise<void> {
        this.set(clientRef, { state: 'waiting', confirmedStale: true, problem: undefined });
        return this.send();
    }

    discard(clientRef: string): void {
        this.update((bookings) => bookings.filter((booking) => booking.clientRef !== clientRef));
    }

    /** Angekommene ausblenden. */
    dismissDone(): void {
        this.update((bookings) => bookings.filter((booking) => booking.state !== 'done'));
    }

    /**
     * Wartende Buchungen senden, älteste zuerst, eine nach der anderen. Ohne Verbindung, bei 401 und
     * Serverfehlern (5xx) endet der Lauf, die Buchung wartet weiter. Läuft schon einer, gilt er.
     */
    send(): Promise<void> {
        this.running ??= this.run().finally(() => {
            this.running = null;
        });
        return this.running;
    }

    private async run(): Promise<void> {
        const generation = this.generation;
        this.current.value = { ...this.current.value, sending: true };
        try {
            for (;;) {
                if (generation !== this.generation) {
                    return;
                }
                const now = this.now();
                // Alte Buchungen warten auf eine Entscheidung.
                for (const booking of this.current.value.bookings) {
                    if (
                        booking.state === 'waiting' &&
                        booking.confirmedStale !== true &&
                        isStale(booking, this.limits, now)
                    ) {
                        this.set(booking.clientRef, {
                            state: 'stale',
                            problem: `Diese Buchung wartet seit mehr als ${String(this.limits.queueMaxAgeDays)} Tagen.`,
                        });
                    }
                }
                const next = this.current.value.bookings.find(
                    (booking) => booking.state === 'waiting',
                );
                if (next === undefined) {
                    return;
                }
                this.set(next.clientRef, { state: 'sending' });
                const result = await this.options.post(next.deviceId, {
                    version: next.version,
                    clientRef: next.clientRef,
                    occurredAt: next.occurredAt,
                    ...(next.locationId !== undefined && { locationId: next.locationId }),
                    ...(next.status !== undefined && { status: next.status }),
                    ...(next.comment !== undefined && { comment: next.comment }),
                    ...(next.confirmedStale === true && { confirmedStale: true }),
                });
                if (generation !== this.generation) {
                    return;
                }
                if (result.ok) {
                    this.set(next.clientRef, {
                        state: 'done',
                        result: {
                            outcome: result.data.result.outcome,
                            outcomeLabel: result.data.result.outcomeLabel,
                            message: result.data.result.message,
                        },
                        problem: undefined,
                    });
                    this.options.onSent?.(result.data);
                    continue;
                }
                const { code, status, message } = result.error;
                if (
                    status === null ||
                    status >= 500 ||
                    code === 'invalid_response' ||
                    status === 401
                ) {
                    // Keine Verbindung, Server gestört oder abgemeldet: später erneut.
                    this.set(next.clientRef, { state: 'waiting' });
                    return;
                }
                this.set(next.clientRef, {
                    state: code === 'stale_action' ? 'stale' : 'failed',
                    problem: problemText(code, message),
                });
            }
        } finally {
            if (generation === this.generation) {
                this.current.value = { ...this.current.value, sending: false };
            }
            await this.writes;
        }
    }
}

/** Warnung vor dem Abmelden (Entscheidung 55); null = nichts ungesendet. */
export function unsentWarning(count: number): string | null {
    if (count === 0) {
        return null;
    }
    return count === 1
        ? '1 Buchung wurde noch nicht gesendet und geht beim Abmelden verloren.'
        : `${String(count)} Buchungen wurden noch nicht gesendet und gehen beim Abmelden verloren.`;
}
