import { describe, expect, it } from 'vitest';
import {
    fetchMaintenance,
    parseMaintenance,
    postBack,
    postToWorkshop,
} from '../../src/api/maintenance';
import { parseDeviceListItem } from '../../src/api/devices';
import { sinceText, transportLine, transportText } from '../../src/data/maintenance';
import { deviceJson } from './support/devices';
import { respond } from './support/fetch';

function stageJson(
    label: string,
    devices: Record<string, unknown>[] = [],
    places = [{ id: 5, name: 'Raum' }],
) {
    return { label, locations: places, total: devices.length, shown: devices.length, devices };
}

const STATE = {
    collection: stageJson('Sammelstelle', [
        deviceJson(1, { status: 'defective', status_label: 'defekt' }),
    ]),
    workshop: stageJson('Werkstatt', []),
    default_store: { id: 9, name: 'Hauptlager' },
};

describe('Seite Wartung: API', () => {
    it('liest die Stufen und das Standard-Lager', () => {
        const state = parseMaintenance(STATE);
        expect(state?.collection.label).toBe('Sammelstelle');
        expect(state?.collection.devices.map((device) => device.identifier)).toEqual(['M1']);
        expect(state?.workshop.devices).toEqual([]);
        expect(state?.defaultStore).toEqual({ id: 9, name: 'Hauptlager' });
        expect(parseMaintenance({ ...STATE, default_store: null })?.defaultStore).toBeNull();
    });

    it.each([
        ['Stufe fehlt', { ...STATE, workshop: undefined }],
        ['Gerät unbrauchbar', { ...STATE, collection: stageJson('x', [{ id: 1 }]) }],
        ['Lager unbrauchbar', { ...STATE, default_store: { id: 'x' } }],
        ['kein Objekt', 'x'],
    ])('lehnt ab: %s', (_label, json) => {
        expect(parseMaintenance(json)).toBeNull();
    });

    it('GET maintenance', async () => {
        const { fetch, requests } = respond(200, STATE);
        const result = await fetchMaintenance(fetch);
        expect(requests[0]?.url).toMatch(/\/api\/v1\/maintenance$/);
        expect(result.ok && result.data.collection.total).toBe(1);
    });

    it('Sammelaktionen senden nur Nummer und Version', async () => {
        const summary = { total: 1, applied: 1, unchanged: 0, errors: 0 };
        const answer = {
            results: [
                {
                    id: 1,
                    outcome: 'applied',
                    replayed: false,
                    device: {
                        ...deviceJson(1),
                        procured_on: null,
                        note: null,
                        created_at: '2026-01-05T09:00:00Z',
                        updated_at: '2026-10-01T08:00:00Z',
                    },
                    error: null,
                },
            ],
            summary,
            target: { id: 5, name: 'Raum' },
        };
        const to = respond(200, answer);
        await postToWorkshop([{ id: 1, version: 3 }], 'Montag', to.fetch);
        expect(to.requests[0]?.url).toMatch(/\/api\/v1\/maintenance\/to-workshop$/);
        expect(to.requests[0]?.body).toEqual({
            devices: [{ id: 1, version: 3 }],
            comment: 'Montag',
        });
        const back = respond(200, answer);
        const result = await postBack([{ id: 1, version: 3 }], undefined, back.fetch);
        expect(back.requests[0]?.url).toMatch(/\/api\/v1\/maintenance\/back$/);
        expect(back.requests[0]?.body).toEqual({ devices: [{ id: 1, version: 3 }] });
        expect(result.ok && result.data.summary).toEqual(summary);
    });
});

describe('Seite Wartung: seit und Transportliste', () => {
    const NOW = new Date('2026-11-09T12:00:00');

    it.each([
        ['2026-11-09T11:30:00', 'seit gerade eben'],
        ['2026-11-09T09:00:00', 'seit 3 Std.'],
        ['2026-11-08T09:00:00', 'seit 1 Tag'],
        ['2026-11-06T09:00:00', 'seit 3 Tagen'],
    ])('%s → %s', (time, text) => {
        expect(sinceText(new Date(time).toISOString(), NOW)).toBe(text);
    });

    it('ohne Zeit ein Strich', () => {
        expect(sinceText(null, NOW)).toBe('–');
    });

    it('Zeilen und Titel', () => {
        const device = parseDeviceListItem(
            deviceJson(1, { identifier: 'M1', status: 'defective', status_label: 'defekt' }),
        );
        if (device === null) {
            throw new Error('Gerät');
        }
        expect(transportLine(device)).toBe('M1 · Atemschutzmaske · defekt · HLF 20 / G1');
        expect(transportText('Sammelstelle', [device, device])).toBe(
            'Sammelstelle (2)\nM1 · Atemschutzmaske · defekt · HLF 20 / G1\nM1 · Atemschutzmaske · defekt · HLF 20 / G1',
        );
    });
});
