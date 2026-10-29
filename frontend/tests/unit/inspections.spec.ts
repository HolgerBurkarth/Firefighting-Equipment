import { describe, expect, it } from 'vitest';
import { parseInspectionOverview } from '../../src/api/inspections';
import { daysBetween, localDateKey } from '../../src/data/changeTime';
import { inspectionOverview, inspectionText } from '../../src/data/inspections';
import { device, deviceJson } from './support/devices';

const TODAY = '2026-10-29';

describe('daysBetween und localDateKey', () => {
    it('zählt Kalendertage ohne Zeitzone, auch über die Zeitumstellung', () => {
        expect(daysBetween('2026-10-29', '2026-10-29')).toBe(0);
        expect(daysBetween('2026-10-24', '2026-10-26')).toBe(2);
        expect(daysBetween('2026-10-29', '2026-10-01')).toBe(-28);
        expect(daysBetween('2026-12-31', '2027-01-01')).toBe(1);
        expect(daysBetween('2026-03-28', '2026-03-30')).toBe(2);
    });

    it('localDateKey in der Ortszeit', () => {
        expect(localDateKey(new Date(2026, 0, 5, 23, 59))).toBe('2026-01-05');
    });
});

describe('inspectionOverview', () => {
    const devices = [
        device(1, { identifier: 'M1', next_inspection_due: '2026-10-28' }),
        device(2, { identifier: 'M2', next_inspection_due: null }),
        device(3, { identifier: 'M3', next_inspection_due: '2026-10-01' }),
        device(4, { identifier: 'M4', next_inspection_due: '2026-10-29' }),
        device(5, { identifier: 'M5', next_inspection_due: '2026-11-28' }),
        device(6, { identifier: 'M6', next_inspection_due: '2026-11-29' }),
        device(7, { identifier: 'M7', next_inspection_due: '2026-10-01' }),
        device(8, {
            identifier: 'M8',
            next_inspection_due: '2026-10-01',
            status: 'retired',
            status_label: 'ausgesondert',
        }),
        device(9, { identifier: 'M9', next_inspection_due: '2026-10-30' }),
    ];

    it('Grenzen wie GET inspection-due, am stärksten überfällig zuerst, dann wie die Liste', () => {
        const overview = inspectionOverview(devices, TODAY);
        expect(overview.overdue.entries.map((e) => [e.device.identifier, e.days])).toEqual([
            ['M3', 28],
            ['M7', 28],
            ['M1', 1],
        ]);
        // Heute (0) bis heute + 30 einschließlich; M6 liegt 31 Tage entfernt.
        expect(overview.dueSoon.entries.map((e) => [e.device.identifier, e.days])).toEqual([
            ['M4', 0],
            ['M9', 1],
            ['M5', 30],
        ]);
        expect([overview.overdue.total, overview.dueSoon.total, overview.days]).toEqual([3, 3, 30]);
    });

    it('Texte', () => {
        const overview = inspectionOverview(devices, TODAY);
        expect(overview.overdue.entries.map((e) => inspectionText(e, true))).toEqual([
            'Prüfung: 01.10.2026 · seit 28 Tagen überfällig',
            'Prüfung: 01.10.2026 · seit 28 Tagen überfällig',
            'Prüfung: 28.10.2026 · seit 1 Tag überfällig',
        ]);
        expect(overview.dueSoon.entries.map((e) => inspectionText(e, false))).toEqual([
            'Prüfung: 29.10.2026 · heute fällig',
            'Prüfung: 30.10.2026 · morgen fällig',
            'Prüfung: 28.11.2026 · in 30 Tagen fällig',
        ]);
    });
});

describe('parseInspectionOverview', () => {
    const body = (overrides: Record<string, unknown> = {}) => ({
        today: TODAY,
        days: 30,
        until: '2026-11-28',
        overdue: { devices: [{ ...deviceJson(3), days_overdue: 28 }], total: 5 },
        due_soon: { devices: [{ ...deviceJson(4), days_until_due: 0 }], total: 1 },
        ...overrides,
    });

    it('liest Abschnitte mit Tagen und Gesamtzahl', () => {
        const parsed = parseInspectionOverview(body());
        expect(parsed?.overdue.entries.map((e) => [e.device.id, e.days])).toEqual([[3, 28]]);
        expect([parsed?.overdue.total, parsed?.dueSoon.total, parsed?.days]).toEqual([5, 1, 30]);
    });

    it('Kurzform genügt (ohne Felder der Liste)', () => {
        const summary = { ...deviceJson(3), days_overdue: 2 } as Record<string, unknown>;
        delete summary['serial_number'];
        delete summary['state_changed_at'];
        expect(
            parseInspectionOverview(body({ overdue: { devices: [summary], total: 1 } }))?.overdue
                .entries[0]?.device.id,
        ).toBe(3);
    });

    it.each([
        [
            'überfällig mit 0 Tagen',
            { overdue: { devices: [{ ...deviceJson(3), days_overdue: 0 }], total: 1 } },
        ],
        ['ohne Tage', { due_soon: { devices: [deviceJson(4)], total: 1 } }],
        ['ohne Abschnitt', { due_soon: undefined }],
        ['ohne heute', { today: null }],
    ])('lehnt ab: %s', (_label, overrides) => {
        expect(parseInspectionOverview(body(overrides))).toBeNull();
    });
});
