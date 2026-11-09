import { describe, expect, it } from 'vitest';
import { parseSwapResponse, type DeviceListItem } from '../../src/api/devices';
import type { Location } from '../../src/api/locations';
import type { StockState } from '../../src/api/stock';
import { parseStockBlock, stockBlockText, swapCandidates } from '../../src/data/swap';

function device(id: number, identifier: string, typeId: number, status = 'ready'): DeviceListItem {
    return {
        id,
        identifier,
        scanCode: null,
        deviceType: { id: typeId, name: typeId === 1 ? 'Atemschutzmaske' : 'Funkgerät' },
        location: { id: 1, name: 'Lager', path: 'Lager' },
        status,
        statusLabel: status,
        nextInspectionDue: null,
        version: 1,
        serialNumber: null,
        manufacturer: null,
        model: null,
        stateChangedAt: null,
    } as unknown as DeviceListItem;
}

const DETAILS = {
    stock: {
        location: { id: 10, name: 'HLF 20', path: 'HLF 20' },
        rule: { id: 1, min_count: 2, types: [{ id: 1, name: 'Atemschutzmaske' }] },
        have: 1,
        missing: 1,
        can_override: true,
    },
};

describe('Sollbestand-Hinweis des Servers', () => {
    it('liest details.stock', () => {
        const block = parseStockBlock(DETAILS);
        expect(block).toMatchObject({
            locationId: 10,
            minCount: 2,
            have: 1,
            missing: 1,
            typeIds: [1],
            canOverride: true,
        });
        expect(block !== null && stockBlockText(block)).toBe(
            'Am Standort „HLF 20“ blieben nach dieser Buchung nur 1 von mindestens 2 (Atemschutzmaske) einsatzbereit.',
        );
    });

    it('andere Regelverstöße und kaputte Angaben sind kein Sollbestand', () => {
        expect(parseStockBlock(null)).toBeNull();
        expect(parseStockBlock({ devices: 2 })).toBeNull();
        expect(parseStockBlock({ stock: { have: 1 } })).toBeNull();
        expect(
            parseStockBlock({ stock: { ...DETAILS.stock, rule: { min_count: 'x', types: [] } } }),
        ).toBeNull();
    });

    it('ohne can_override darf die Rolle nicht trotzdem buchen', () => {
        const block = parseStockBlock({ stock: { ...DETAILS.stock, can_override: false } });
        expect(block?.canOverride).toBe(false);
    });
});

describe('Ersatz für einen Tausch', () => {
    const out = device(1, 'M1', 1);
    const all = [
        out,
        device(2, 'F2', 2),
        device(3, 'M3', 1),
        device(4, 'M4', 1, 'defective'),
        device(5, 'M5', 1),
    ];

    const ids = (list: readonly { device: DeviceListItem }[]): string[] =>
        list.map((entry) => entry.device.identifier);

    it('nur einsatzbereit, nie dieselbe Ausrüstung und nur der gleiche Typ', () => {
        expect(ids(swapCandidates(all, out, '').suggested)).toEqual(['M3', 'M5']);
    });

    it('bei einer verletzten Regel zählen deren gleichwertige Typen', () => {
        expect(ids(swapCandidates(all, out, '', [1, 2]).suggested)).toEqual(['F2', 'M3', 'M5']);
        expect(ids(swapCandidates(all, out, '', [2]).suggested)).toEqual(['F2']);
    });

    it('der Suchtext filtert wie die Suche', () => {
        expect(ids(swapCandidates(all, out, 'm5').suggested)).toEqual(['M5']);
    });

    describe('mit Standorten', () => {
        const place = (id: number, kind: 'vehicle' | 'room', task: string | null) =>
            ({ id, name: `S${String(id)}`, kind, task, children: [] }) as unknown as Location;
        const locations = [
            place(1, 'vehicle', null),
            place(2, 'vehicle', null),
            place(3, 'room', null),
            place(4, 'room', 'store'),
        ];
        const at = (entry: DeviceListItem, locationId: number): DeviceListItem => ({
            ...entry,
            location: { id: locationId, name: 'x', path: 'x' },
        });
        const ownDevice = at(out, 1);
        const fleet = [
            ownDevice,
            at(device(3, 'M3', 1), 1),
            at(device(5, 'M5', 1), 2),
            at(device(6, 'M6', 1), 3),
            at(device(7, 'M7', 1), 4),
        ];

        it('Lager zuerst, Fahrzeuge getrennt, nichts vom eigenen Standort', () => {
            const found = swapCandidates(fleet, ownDevice, '', [], locations);
            expect(ids(found.suggested)).toEqual(['M7', 'M6']);
            expect(ids(found.others)).toEqual(['M5']);
        });

        it('warnt, wenn der Sollbestand beim anderen Fahrzeug unterschritten würde', () => {
            const stock = {
                entries: [
                    {
                        location: { id: 2, name: 'S2', path: 'S2', level: 1 },
                        rules: [
                            {
                                id: 1,
                                minCount: 2,
                                types: [{ id: 1, name: 'A' }],
                                have: 2,
                                missing: 0,
                            },
                        ],
                        outOfService: false,
                    },
                ],
                outOfService: 0,
            } as unknown as StockState;
            const found = swapCandidates(fleet, ownDevice, '', [], locations, stock);
            expect(found.others.map((entry) => entry.belowMinimum)).toEqual([true]);
            expect(found.suggested.some((entry) => entry.belowMinimum)).toBe(false);
        });
    });
});

describe('Antwort des Tauschs', () => {
    it('liest beide Geräte und die Kennung', () => {
        const json = {
            out: {
                id: 1,
                identifier: 'M1',
                scan_code: null,
                device_type: { id: 1, name: 'Atemschutzmaske' },
                location: { id: 1, name: 'Lager', path: 'Lager' },
                status: 'used',
                status_label: 'benutzt',
                next_inspection_due: null,
                version: 2,
                serial_number: null,
                manufacturer: null,
                model: null,
            },
            swap_id: 'a'.repeat(32),
        };
        expect(parseSwapResponse(json)).toBeNull();
        expect(parseSwapResponse({ ...json, in: json.out })?.swapId).toBe('a'.repeat(32));
        expect(parseSwapResponse(null)).toBeNull();
    });
});
