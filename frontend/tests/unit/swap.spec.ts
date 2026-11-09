import { describe, expect, it } from 'vitest';
import { parseSwapResponse, type DeviceListItem } from '../../src/api/devices';
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

    it('nur einsatzbereit und nie dieselbe Ausrüstung, gleicher Typ zuerst', () => {
        expect(swapCandidates(all, out, '').map((entry) => entry.identifier)).toEqual([
            'M3',
            'M5',
            'F2',
        ]);
    });

    it('die Typen der verletzten Regel gehen vor', () => {
        expect(swapCandidates(all, out, '', [2]).map((entry) => entry.identifier)).toEqual([
            'F2',
            'M3',
            'M5',
        ]);
    });

    it('der Suchtext filtert wie die Suche', () => {
        expect(swapCandidates(all, out, 'm5').map((entry) => entry.identifier)).toEqual(['M5']);
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
