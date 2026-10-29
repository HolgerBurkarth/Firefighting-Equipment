/**
 * Zeitpunkt der letzten Änderung eines Geräts (Umbuchung/Status, `state_changed_at` in UTC) für
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
    const clock = `${twoDigits(time.getHours())}:${twoDigits(time.getMinutes())}`;
    if (sameDay(time, now)) {
        return `heute ${clock}`;
    }
    const yesterday = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1);
    if (sameDay(time, yesterday)) {
        return `gestern ${clock}`;
    }
    return `${twoDigits(time.getDate())}.${twoDigits(time.getMonth() + 1)}.${String(time.getFullYear())}`;
}
