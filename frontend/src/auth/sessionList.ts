import type { AccountSession } from '../api/auth';
import { changeTimeText } from '../data/changeTime';

/** Dieses Gerät zuerst, die anderen wie vom Server (zuletzt benutzte zuerst). */
export function sortSessions(sessions: readonly AccountSession[]): AccountSession[] {
    return [
        ...sessions.filter((session) => session.current),
        ...sessions.filter((session) => !session.current),
    ];
}

export function sessionLabel(session: AccountSession): string {
    return session.deviceLabel ?? 'Unbekanntes Gerät';
}

/**
 * Zeiten in der Ortszeit, z. B. „Zuletzt aktiv vor 5 Min. · angemeldet 12.10.2026“. Beim eigenen
 * Gerät nur die Anmeldung (der Server schreibt `last_seen_at` höchstens alle 5 Minuten).
 */
export function sessionTimeText(session: AccountSession, now: Date = new Date()): string {
    const since = changeTimeText(session.createdAt, now);
    if (session.current) {
        return `Angemeldet ${since}`;
    }
    return `Zuletzt aktiv ${changeTimeText(session.lastSeenAt, now)} · angemeldet ${since}`;
}

/** Bestätigung nach „Alle anderen Geräte abmelden“. */
export function endedSessionsText(count: number): string {
    if (count === 0) {
        return 'Es war kein anderes Gerät angemeldet.';
    }
    return count === 1 ? '1 Gerät wurde abgemeldet.' : `${String(count)} Geräte wurden abgemeldet.`;
}
