/**
 * Zeitangaben in der Ortszeit des Handys (Server liefert UTC).
 *
 * changeTimeText: Zeitpunkt der letzten Änderung eines Geräts (Umbuchung/Status, `state_changed_at` in UTC) für
 * „Zuletzt benutzt“, in der Ortszeit des Handys: unter 1 Stunde „vor 5 Min.“ (unter 1 Minute
 * „gerade eben“), sonst „heute 14:02“, „gestern 18:30“, ältere „12.10.2026“.
 */

const MINUTE_MS = 60_000;
const HOUR_MS = 60 * MINUTE_MS;

function twoDigits(value: number): string {
    return String(value).padStart(2, '0');
}

function sameDay(a: Date, b: Date): boolean {
    return (
        a.getFullYear() === b.getFullYear() &&
        a.getMonth() === b.getMonth() &&
        a.getDate() === b.getDate()
    );
}

export function changeTimeText(utc: string, now: Date = new Date()): string {
    const time = new Date(utc);
    const diff = now.getTime() - time.getTime();
    // Etwas in der Zukunft (Uhr des Handys geht nach) zählt wie gerade eben.
    if (diff < MINUTE_MS && diff > -HOUR_MS) {
        return 'gerade eben';
    }
    if (diff > 0 && diff < HOUR_MS) {
        return `vor ${String(Math.floor(diff / MINUTE_MS))} Min.`;
    }
    return recentDayText(time, now) ?? localDate(time);
}

function clockText(time: Date): string {
    return `${twoDigits(time.getHours())}:${twoDigits(time.getMinutes())}`;
}

/** „heute 14:02“ bzw. „gestern 18:30“, sonst null. */
function recentDayText(time: Date, now: Date): string | null {
    if (sameDay(time, now)) {
        return `heute ${clockText(time)}`;
    }
    const yesterday = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1);
    return sameDay(time, yesterday) ? `gestern ${clockText(time)}` : null;
}

/** Datum `TT.MM.JJJJ` in der Ortszeit. */
function localDate(time: Date): string {
    return `${twoDigits(time.getDate())}.${twoDigits(time.getMonth() + 1)}.${String(time.getFullYear())}`;
}

/** Zeitpunkt im Verlauf: „heute 14:02“, „gestern 18:30“, sonst „12.10.2026, 09:15“. */
export function eventTimeText(utc: string, now: Date = new Date()): string {
    const time = new Date(utc);
    return recentDayText(time, now) ?? `${localDate(time)}, ${clockText(time)}`;
}

/** Kalenderdatum des Servers `JJJJ-MM-TT` als `TT.MM.JJJJ` (ohne Zeitzone). */
export function germanDate(date: string): string {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(date);
    return match === null ? date : `${match[3] ?? ''}.${match[2] ?? ''}.${match[1] ?? ''}`;
}
