import type { DeviceEventEntry } from '../api/deviceEvents';
import { eventTimeText, germanDate } from './changeTime';

/** Trennzeichen eines Scan-Codes lesbar wie ScanCode::display in PHP. */
const SCAN_CODE_SEPARATORS: Readonly<Record<string, string>> = {
    '\u0004': '[EOT]',
    '\u001d': '[GS]',
    '\u001e': '[RS]',
};

export function scanCodeDisplay(code: string): string {
    return code.replace(/[\u0004\u001d\u001e]/g, (char) => SCAN_CODE_SEPARATORS[char] ?? char);
}

/** Zeile eines Eintrags im Verlauf, z. B. „Standort: HLF 20 / G1 → LF 10“. */
export interface EventLine {
    readonly label: string;
    readonly value: string;
}

/** Eingang und Aktionszeit gelten ab dieser Abweichung als verschieden (Offline-Buchung). */
const RECEIVED_LATER_MS = 60_000;

function change(from: string | null, to: string | null, empty: string): string {
    return from === null ? (to ?? empty) : `${from} → ${to ?? empty}`;
}

function fieldValue(name: string, value: string | null): string | null {
    if (value === null) {
        return null;
    }
    if (name === 'procured_on') {
        return germanDate(value);
    }
    return name === 'scan_code' ? scanCodeDisplay(value) : value;
}

/**
 * Was ein Eintrag geändert hat, in lesbaren Zeilen. Nicht übernommene Buchungen zeigen das
 * Gewünschte und den Grund. Kam eine Offline-Buchung später an, steht der Eingang dabei.
 */
export function eventLines(event: DeviceEventEntry, now: Date = new Date()): EventLine[] {
    const lines: EventLine[] = [];
    const wished = event.applied ? '' : ' (gewünscht)';
    if (event.location !== null) {
        lines.push({
            label: `Standort${wished}`,
            value: change(
                event.applied ? (event.location.from?.path ?? null) : null,
                event.location.to?.path ?? null,
                '–',
            ),
        });
    }
    if (event.status !== null) {
        lines.push({
            label: `Status${wished}`,
            value: change(event.applied ? event.status.fromLabel : null, event.status.toLabel, '–'),
        });
    }
    if (!event.applied && event.notAppliedLabel !== null) {
        lines.push({ label: 'Grund', value: event.notAppliedLabel });
    }
    if (event.inspectionDue !== null) {
        const { from, to } = event.inspectionDue;
        lines.push({
            label: 'Prüfdatum',
            value: `${from === null ? 'keins' : germanDate(from)} → ${to === null ? 'keins' : germanDate(to)}`,
        });
    }
    if (event.field !== null) {
        const { name, label } = event.field;
        lines.push({
            label,
            value: `${fieldValue(name, event.field.old) ?? 'leer'} → ${fieldValue(name, event.field.new) ?? 'leer'}`,
        });
    }
    const delay = Date.parse(event.receivedAt) - Date.parse(event.occurredAt);
    if (delay >= RECEIVED_LATER_MS) {
        lines.push({ label: 'Eingang beim Server', value: eventTimeText(event.receivedAt, now) });
    }
    return lines;
}
