import { describe, expect, it } from 'vitest';
import type { ApiResult } from '../../src/api/client';
import {
    parseDeviceEvent,
    parseDeviceEventPage,
    type DeviceEventEntry,
    type DeviceEventPage,
} from '../../src/api/deviceEvents';
import { parseDeviceDetail } from '../../src/api/devices';
import { eventTimeText, germanDate } from '../../src/data/changeTime';
import { DeviceHistory, EVENT_PAGE_LIMIT } from '../../src/data/deviceHistory';
import { eventLines, scanCodeDisplay } from '../../src/data/eventDisplay';
import { deviceJson } from './support/devices';

/** Eintrag wie DeviceEventJson::event (Standard: Umbuchung, übernommen). */
function eventJson(id: number, overrides: Record<string, unknown> = {}): Record<string, unknown> {
    return {
        id,
        type: 'state_changed',
        type_label: 'Umgebucht',
        created_at: '2026-10-29T12:00:00Z',
        occurred_at: '2026-10-29T12:00:00Z',
        received_at: '2026-10-29T12:00:00Z',
        applied: true,
        not_applied_reason: null,
        not_applied_label: null,
        client_ref: null,
        user: { id: 7, name: 'Anna' },
        comment: null,
        location: {
            from: { id: 11, name: 'G1', path: 'HLF 20 / G1' },
            to: { id: 20, name: 'LF 10', path: 'LF 10' },
        },
        status: null,
        inspection_due: null,
        field: null,
        ...overrides,
    };
}

function event(id: number, overrides: Record<string, unknown> = {}): DeviceEventEntry {
    const parsed = parseDeviceEvent(eventJson(id, overrides));
    if (parsed === null) {
        throw new Error(`Ungültiger Testeintrag ${String(id)}`);
    }
    return parsed;
}

const NETWORK = {
    ok: false,
    error: {
        code: 'network',
        message: 'Keine Verbindung',
        status: 0,
        retryAfterSeconds: null,
        details: null,
    },
} as const;

describe('parseDeviceDetail', () => {
    it('Liste plus Beschaffungsdatum und Notiz', () => {
        const parsed = parseDeviceDetail(
            deviceJson(5, { procured_on: '2021-04-15', note: 'a\nb', created_at: 'x' }),
        );
        expect([parsed?.id, parsed?.procuredOn, parsed?.note]).toEqual([5, '2021-04-15', 'a\nb']);
    });

    it.each([
        ['Notiz fehlt', { procured_on: null }],
        ['Beschaffungsdatum als Zahl', { procured_on: 2021, note: null }],
    ])('lehnt ab: %s', (_label, overrides) => {
        expect(parseDeviceDetail(deviceJson(5, overrides))).toBeNull();
    });
});

describe('parseDeviceEvent', () => {
    it('liest alle Angaben', () => {
        expect(event(3, { comment: 'gereinigt' })).toEqual({
            id: 3,
            type: 'state_changed',
            typeLabel: 'Umgebucht',
            occurredAt: '2026-10-29T12:00:00Z',
            receivedAt: '2026-10-29T12:00:00Z',
            applied: true,
            notAppliedLabel: null,
            userName: 'Anna',
            comment: 'gereinigt',
            location: {
                from: { id: 11, name: 'G1', path: 'HLF 20 / G1' },
                to: { id: 20, name: 'LF 10', path: 'LF 10' },
            },
            status: null,
            inspectionDue: null,
            field: null,
        });
    });

    it.each([
        ['ohne Benutzer', { user: null }],
        ['Zeit ohne Zone', { occurred_at: '2026-10-29T12:00:00' }],
        ['applied als Zahl', { applied: 1 }],
        ['Standort ohne Pfad', { location: { from: null, to: { id: 1, name: 'X' } } }],
        ['Status als Text', { status: 'ready' }],
        ['Feld ohne Bezeichnung', { field: { name: 'model', old: null, new: 'X' } }],
        ['Prüfdatum als Zahl', { inspection_due: { from: null, to: 5 } }],
    ])('lehnt ab: %s', (_label, overrides) => {
        expect(parseDeviceEvent(eventJson(1, overrides))).toBeNull();
    });

    it('Seite: ein ungültiger Eintrag macht alles ungültig', () => {
        expect(parseDeviceEventPage({ events: [eventJson(1)], total: 1 })?.total).toBe(1);
        expect(
            parseDeviceEventPage({ events: [eventJson(1), eventJson(2, { id: 0 })], total: 2 }),
        ).toBeNull();
        expect(parseDeviceEventPage({ events: [], total: -1 })).toBeNull();
    });
});

describe('DeviceHistory', () => {
    function source(all: readonly DeviceEventEntry[]) {
        const calls: { limit: number; offset: number }[] = [];
        let items = [...all];
        return {
            calls,
            set(next: readonly DeviceEventEntry[]): void {
                items = [...next];
            },
            fetch: (
                _id: number,
                query: { limit: number; offset: number },
            ): Promise<ApiResult<DeviceEventPage>> => {
                calls.push(query);
                return Promise.resolve({
                    ok: true,
                    status: 200,
                    data: {
                        events: items.slice(query.offset, query.offset + query.limit),
                        total: items.length,
                    },
                });
            },
        };
    }

    const thirty = Array.from({ length: 30 }, (_, i) => event(100 - i));

    it('lädt seitenweise zu 20', async () => {
        const data = source(thirty);
        const history = new DeviceHistory(1, data.fetch);
        expect(history.hasMore).toBe(false);
        await history.reload();
        expect(EVENT_PAGE_LIMIT).toBe(20);
        expect(history.state.events).toHaveLength(20);
        expect(history.hasMore).toBe(true);
        await history.loadMore();
        expect(history.state.events.map((e) => e.id)).toEqual(thirty.map((e) => e.id));
        expect(history.hasMore).toBe(false);
        expect(data.calls).toEqual([
            { limit: 20, offset: 0 },
            { limit: 20, offset: 20 },
        ]);
    });

    it('neue Einträge zwischen zwei Abrufen: keine doppelten, keiner fehlt', async () => {
        const data = source(thirty);
        const history = new DeviceHistory(1, data.fetch);
        await history.reload();
        data.set([event(102), event(101), ...thirty]);
        await history.loadMore();
        const ids = history.state.events.map((e) => e.id);
        expect(new Set(ids).size).toBe(ids.length);
        expect(ids).toEqual(thirty.map((e) => e.id));
        expect(history.state.total).toBe(32);
    });

    it('Fehler behält die geladenen Einträge', async () => {
        const data = source(thirty);
        let fail = false;
        const history = new DeviceHistory(1, (id, query) =>
            fail ? Promise.resolve(NETWORK) : data.fetch(id, query),
        );
        await history.reload();
        fail = true;
        await history.loadMore();
        expect(history.state.events).toHaveLength(20);
        expect(history.state.error?.code).toBe('network');
        fail = false;
        await history.loadMore();
        expect(history.state.events).toHaveLength(30);
        expect(history.state.error).toBeNull();
    });
});

describe('Anzeige', () => {
    const now = new Date(2026, 9, 29, 14, 30);

    it('eventTimeText und germanDate', () => {
        const local = (d: number, h: number, m: number) =>
            new Date(2026, 9, d, h, m).toISOString().replace('.000Z', 'Z');
        expect(eventTimeText(local(29, 14, 25), now)).toBe('heute 14:25');
        expect(eventTimeText(local(28, 18, 30), now)).toBe('gestern 18:30');
        expect(eventTimeText(local(12, 9, 5), now)).toBe('12.10.2026, 09:05');
        expect(germanDate('2021-04-15')).toBe('15.04.2021');
    });

    it('scanCodeDisplay wie ScanCode::display', () => {
        expect(scanCodeDisplay('01\u001d21\u001eX\u0004')).toBe('01[GS]21[RS]X[EOT]');
    });

    it('Umbuchung und Status, angelegt', () => {
        expect(
            eventLines(
                event(1, {
                    status: {
                        from: 'ready',
                        from_label: 'einsatzbereit',
                        to: 'defective',
                        to_label: 'defekt',
                    },
                }),
                now,
            ),
        ).toEqual([
            { label: 'Standort', value: 'HLF 20 / G1 → LF 10' },
            { label: 'Status', value: 'einsatzbereit → defekt' },
        ]);
        expect(
            eventLines(
                event(1, {
                    type: 'created',
                    location: { from: null, to: { id: 11, name: 'G1', path: 'HLF 20 / G1' } },
                    status: {
                        from: null,
                        from_label: null,
                        to: 'ready',
                        to_label: 'einsatzbereit',
                    },
                }),
                now,
            ),
        ).toEqual([
            { label: 'Standort', value: 'HLF 20 / G1' },
            { label: 'Status', value: 'einsatzbereit' },
        ]);
    });

    it('nicht übernommen: Gewünschtes, Grund, späterer Eingang', () => {
        expect(
            eventLines(
                event(1, {
                    applied: false,
                    not_applied_label: 'Standort gesperrt',
                    occurred_at: '2026-10-29T10:00:00Z',
                    received_at: '2026-10-29T10:05:00Z',
                    location: { from: null, to: { id: 30, name: 'Lager', path: 'Lager' } },
                    status: { from: null, from_label: null, to: 'defective', to_label: 'defekt' },
                }),
                new Date('2026-10-29T13:00:00Z'),
            ),
        ).toEqual([
            { label: 'Standort (gewünscht)', value: 'Lager' },
            { label: 'Status (gewünscht)', value: 'defekt' },
            { label: 'Grund', value: 'Standort gesperrt' },
            {
                label: 'Eingang beim Server',
                value: eventTimeText('2026-10-29T10:05:00Z', new Date('2026-10-29T13:00:00Z')),
            },
        ]);
    });

    it('Eingang unter einer Minute nach der Aktion: keine Zeile', () => {
        const lines = eventLines(
            event(1, { occurred_at: '2026-10-29T10:00:00Z', received_at: '2026-10-29T10:00:59Z' }),
            now,
        );
        expect(lines.map((line) => line.label)).toEqual(['Standort']);
    });

    it('Prüfdatum und Stammdaten (Datum, Scan-Code, leer)', () => {
        expect(
            eventLines(
                event(1, { location: null, inspection_due: { from: '2026-03-01', to: null } }),
                now,
            ),
        ).toEqual([{ label: 'Prüfdatum', value: '01.03.2026 → keins' }]);
        const field = (name: string, label: string, old: string | null, next: string | null) =>
            eventLines(event(1, { location: null, field: { name, label, old, new: next } }), now);
        expect(field('procured_on', 'Beschaffungsdatum', null, '2021-04-15')).toEqual([
            { label: 'Beschaffungsdatum', value: 'leer → 15.04.2021' },
        ]);
        expect(field('scan_code', 'Scan-Code', 'A\u001dB', null)).toEqual([
            { label: 'Scan-Code', value: 'A[GS]B → leer' },
        ]);
        expect(field('model', 'Modell', 'FPS', 'FPS 7000')).toEqual([
            { label: 'Modell', value: 'FPS → FPS 7000' },
        ]);
    });
});
