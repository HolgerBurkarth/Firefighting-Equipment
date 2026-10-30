import type { TenantEventEntry } from '../api/deviceEvents';
import type { DeviceSummaryCounts } from '../api/deviceSummary';
import { eventLines } from './eventDisplay';

/** Texte der Kacheln auf dem Dashboard (Mockup „Main“). */
export interface DashboardTile {
    readonly value: string;
    readonly detail: string;
}

function count(n: number, singular: string, plural: string): string {
    return `${String(n)} ${n === 1 ? singular : plural}`;
}

/** „42 von 45“, Anteil 0–100 für den Balken (ohne Geräte 0). */
export function readinessTile(summary: DeviceSummaryCounts): DashboardTile & { percent: number } {
    const ready = summary.byStatus.ready;
    return {
        value: `${String(ready)} von ${String(summary.total)}`,
        detail: summary.total === 0 ? 'Noch keine Geräte erfasst' : 'Geräte einsatzbereit',
        percent: summary.total === 0 ? 0 : Math.round((ready / summary.total) * 100),
    };
}

/** „3“ mit „2 defekt · 1 in Wartung“ (Teile mit 0 entfallen). */
export function notReadyTile(summary: DeviceSummaryCounts): DashboardTile {
    const { defective, maintenance } = summary.byStatus;
    const parts = [
        ...(defective > 0 ? [`${String(defective)} defekt`] : []),
        ...(maintenance > 0 ? [`${String(maintenance)} in Wartung`] : []),
    ];
    return {
        value: String(defective + maintenance),
        detail: parts.length === 0 ? 'Alle Geräte einsatzbereit' : parts.join(' · '),
    };
}

/** „2 überfällig“ mit „4 in den nächsten 30 Tagen“. */
export function inspectionTile(summary: DeviceSummaryCounts): DashboardTile {
    return {
        value: `${String(summary.overdue)} überfällig`,
        detail: `${String(summary.dueSoon)} in den nächsten ${String(summary.dueSoonDays)} Tagen`,
    };
}

/** Offene Hinweise im Protokoll und wartende Freischaltungen zusammen. */
export function openItemsTile(openNotices: number, pendingUsers: number): DashboardTile {
    const parts = [
        ...(openNotices > 0 ? [count(openNotices, 'Hinweis', 'Hinweise')] : []),
        ...(pendingUsers > 0 ? [count(pendingUsers, 'Freischaltung', 'Freischaltungen')] : []),
    ];
    return {
        value: String(openNotices + pendingUsers),
        detail: parts.length === 0 ? 'Nichts zu erledigen' : parts.join(' · '),
    };
}

/** Was ein Eintrag geändert hat, in einer Zeile („Standort: HLF 20 / G1 → Lager · …“). */
export function activityDetail(event: TenantEventEntry, now: Date = new Date()): string {
    return eventLines(event, now)
        .map((line) => `${line.label}: ${line.value}`)
        .join(' · ');
}
