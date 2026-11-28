import { describe, expect, it } from 'vitest';
import type { DeviceListItem } from '../../src/api/devices';
import { devicesByLocation } from '../../src/data/locationDevices';

function device(id: number, identifier: string, locationId: number): DeviceListItem {
    return {
        id,
        identifier,
        scanCode: null,
        deviceType: { id: 1, name: 'Atemschutzmaske' },
        location: { id: locationId, name: 'G1', path: 'HLF 20 / G1' },
        status: 'ready',
        statusLabel: 'einsatzbereit',
        nextInspectionDue: null,
        version: 1,
        serialNumber: null,
        manufacturer: null,
        model: null,
        stateChangedAt: null,
    };
}

describe('devicesByLocation', () => {
    it('ordnet je Standort und sortiert die Nummern natürlich', () => {
        const map = devicesByLocation([
            device(1, 'M10', 11),
            device(2, 'M2', 11),
            device(3, 'HRT 1', 20),
            device(4, 'm1', 11),
        ]);
        expect(map.get(11)?.map((d) => d.identifier)).toEqual(['m1', 'M2', 'M10']);
        expect(map.get(20)?.map((d) => d.identifier)).toEqual(['HRT 1']);
        expect(map.get(10)).toBeUndefined();
    });

    it('leere Liste ergibt keine Einträge', () => {
        expect(devicesByLocation([]).size).toBe(0);
    });
});
