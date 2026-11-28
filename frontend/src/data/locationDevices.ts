import type { DeviceListItem } from '../api/devices';

const COLLATOR = new Intl.Collator('de', { numeric: true, sensitivity: 'base' });

/**
 * Ausrüstung je Standort für „Ausrüstung anzeigen“ auf der Seite Standorte: Schlüssel ist der
 * Standort, an dem die Ausrüstung direkt steht (Ebene 1 ohne Fach bzw. das Fach), sortiert nach
 * Nummer wie die Listen (natürlich, „M2“ vor „M10“). Ausgesonderte enthält die Liste ohnehin nicht.
 */
export function devicesByLocation(
    devices: readonly DeviceListItem[],
): ReadonlyMap<number, readonly DeviceListItem[]> {
    const map = new Map<number, DeviceListItem[]>();
    for (const device of devices) {
        const list = map.get(device.location.id);
        if (list === undefined) {
            map.set(device.location.id, [device]);
        } else {
            list.push(device);
        }
    }
    for (const list of map.values()) {
        list.sort((a, b) => COLLATOR.compare(a.identifier, b.identifier) || a.id - b.id);
    }
    return map;
}

/** Name der Option in der Adresse (`standorte?ausruestung=1`). */
export const SHOW_DEVICES_QUERY = 'ausruestung';
