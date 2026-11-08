import { describe, expect, it } from 'vitest';
import { parseLocation, parseTaskSettings } from '../../src/api/locations';

const BASE = {
    id: 1,
    name: 'Gerätehaus',
    kind: 'room',
    kind_label: 'Raum/Lager',
    level: 1,
    parent_id: null,
    active: true,
    device_count: 0,
    device_count_total: 0,
    children: [],
};

describe('Aufgaben der Standorte (P2.2)', () => {
    it('liest Aufgabe, Standard-Lager und Annahme; fehlende Felder gelten als keine', () => {
        expect(parseLocation(BASE)).toMatchObject({
            task: null,
            isDefaultStore: false,
            collectionAccepts: null,
        });
        expect(parseLocation({ ...BASE, task: 'store', is_default_store: true })).toMatchObject({
            task: 'store',
            isDefaultStore: true,
        });
        expect(
            parseLocation({ ...BASE, task: 'collection', collection_accepts: 'used' }),
        ).toMatchObject({ task: 'collection', collectionAccepts: 'used' });
        // Eine unbekannte Aufgabe (neuere Version des Servers) gilt als keine.
        expect(parseLocation({ ...BASE, task: 'garage' })).toMatchObject({ task: null });
    });

    it('liest die Einstellungen der Stadt und lehnt Ungültiges ab', () => {
        const entry = {
            task: 'store',
            label: 'Depot',
            default_label: 'Lager',
            enabled: true,
            locations: 2,
        };
        expect(parseTaskSettings({ tasks: [entry] })).toEqual([
            { task: 'store', label: 'Depot', defaultLabel: 'Lager', enabled: true, locations: 2 },
        ]);
        expect(parseTaskSettings({ tasks: [{ ...entry, task: 'garage' }] })).toBeNull();
        expect(parseTaskSettings({ tasks: [{ ...entry, enabled: 'yes' }] })).toBeNull();
        expect(parseTaskSettings({})).toBeNull();
    });
});
