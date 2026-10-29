import type { DeviceSummary } from '../api/devices';
import type { InspectionEntry, InspectionOverview } from '../api/inspections';
import { daysBetween, germanDate } from './changeTime';

/** Fenster „bald fällig“ wie InspectionWindow::DEFAULT_DAYS (KONZEPT.md: „in 30 Tagen fällig“). */
export const INSPECTION_WINDOW_DAYS = 30;

/** Höchstens so viele Geräte je Abschnitt vom Server (Modus `server`, InspectionDueHandler). */
export const SERVER_INSPECTION_LIMIT = 200;

/**
 * Prüffristen aus der lokalen Geräteliste, Grenzen wie GET inspection-due: überfällig = Prüfdatum
 * vor heute, bald fällig = heute bis heute + `days`. Sortiert nach Prüfdatum (am stärksten
 * überfällig zuerst), bei Gleichstand in der Reihenfolge der Liste (nach Kennung).
 */
export function inspectionOverview(
    devices: readonly DeviceSummary[],
    today: string,
    days: number = INSPECTION_WINDOW_DAYS,
): InspectionOverview {
    const overdue: (InspectionEntry & { due: string })[] = [];
    const dueSoon: (InspectionEntry & { due: string })[] = [];
    for (const device of devices) {
        const due = device.nextInspectionDue;
        if (due === null || device.status === 'retired') {
            continue;
        }
        const until = daysBetween(today, due);
        if (until < 0) {
            overdue.push({ device, days: -until, due });
        } else if (until <= days) {
            dueSoon.push({ device, days: until, due });
        }
    }
    const byDue = (a: { due: string }, b: { due: string }): number =>
        a.due < b.due ? -1 : a.due > b.due ? 1 : 0;
    const strip = (entries: (InspectionEntry & { due: string })[]): InspectionEntry[] =>
        entries.sort(byDue).map(({ device, days: entryDays }) => ({ device, days: entryDays }));
    return {
        today,
        days,
        overdue: { entries: strip(overdue), total: overdue.length },
        dueSoon: { entries: strip(dueSoon), total: dueSoon.length },
    };
}

/** „Prüfung: 01.10.2026 · seit 28 Tagen überfällig“ bzw. „… · in 4 Tagen fällig“. */
export function inspectionText(entry: InspectionEntry, overdue: boolean): string {
    const date =
        entry.device.nextInspectionDue === null ? '' : germanDate(entry.device.nextInspectionDue);
    const { days } = entry;
    let when: string;
    if (overdue) {
        when = days === 1 ? 'seit 1 Tag überfällig' : `seit ${String(days)} Tagen überfällig`;
    } else if (days === 0) {
        when = 'heute fällig';
    } else if (days === 1) {
        when = 'morgen fällig';
    } else {
        when = `in ${String(days)} Tagen fällig`;
    }
    return `Prüfung: ${date} · ${when}`;
}
