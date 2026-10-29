import { apiRequest, isRecord, type ApiResult } from './client';
import { isId } from './devices';

/** Art wie LocationKind in PHP. */
export type LocationKind = 'vehicle' | 'room' | 'external';

const KINDS: readonly LocationKind[] = ['vehicle', 'room', 'external'];

/** Unterstandort (Ebene 2, z. B. Fach) aus GET locations. */
export interface SubLocation {
    readonly id: number;
    readonly name: string;
    readonly kind: LocationKind;
    readonly active: boolean;
    /** Nicht ausgesonderte Geräte direkt hier. */
    readonly deviceCount: number;
}

/** Standort der Ebene 1 mit seinen Unterstandorten (LocationJson). */
export interface Location extends SubLocation {
    readonly kindLabel: string;
    /** Einschließlich aller Unterstandorte, auch nicht aufgeführter. */
    readonly deviceCountTotal: number;
    readonly children: readonly SubLocation[];
}

function isKind(value: unknown): value is LocationKind {
    return typeof value === 'string' && (KINDS as readonly string[]).includes(value);
}

function isCount(value: unknown): value is number {
    return typeof value === 'number' && Number.isSafeInteger(value) && value >= 0;
}

function parseSubLocation(json: unknown, parent: number): SubLocation | null {
    if (!isRecord(json)) {
        return null;
    }
    const { id, name, kind, level, active } = json;
    const parentId = json['parent_id'];
    const deviceCount = json['device_count'];
    if (
        !isId(id) ||
        typeof name !== 'string' ||
        !isKind(kind) ||
        level !== 2 ||
        parentId !== parent ||
        typeof active !== 'boolean' ||
        !isCount(deviceCount)
    ) {
        return null;
    }
    return { id, name, kind, active, deviceCount };
}

export function parseLocation(json: unknown): Location | null {
    if (!isRecord(json) || !Array.isArray(json['children'])) {
        return null;
    }
    const { id, name, kind, level, active } = json;
    const kindLabel = json['kind_label'];
    const parentId = json['parent_id'];
    const deviceCount = json['device_count'];
    const deviceCountTotal = json['device_count_total'];
    if (
        !isId(id) ||
        typeof name !== 'string' ||
        !isKind(kind) ||
        typeof kindLabel !== 'string' ||
        level !== 1 ||
        parentId !== null ||
        typeof active !== 'boolean' ||
        !isCount(deviceCount) ||
        !isCount(deviceCountTotal)
    ) {
        return null;
    }
    const children: SubLocation[] = [];
    for (const entry of json['children']) {
        const child = parseSubLocation(entry, id);
        // Ungültig (null) oder andere Art: Ein Unterstandort hat immer die Art seines Standorts
        // (Fremdschlüssel im Backend).
        if (child?.kind !== kind) {
            return null;
        }
        children.push(child);
    }
    return { id, name, kind, kindLabel, active, deviceCount, deviceCountTotal, children };
}

export function parseLocationTree(json: unknown): readonly Location[] | null {
    if (!isRecord(json) || !Array.isArray(json['locations'])) {
        return null;
    }
    const locations: Location[] = [];
    for (const entry of json['locations']) {
        const location = parseLocation(entry);
        if (location === null) {
            return null;
        }
        locations.push(location);
    }
    return locations;
}

/** GET locations: Baum ohne gesperrte Standorte (Ebene 1 und Unterstandorte nach Name). */
export function fetchLocations(fetchFn?: typeof fetch): Promise<ApiResult<readonly Location[]>> {
    return apiRequest('locations', parseLocationTree, { ...(fetchFn && { fetchFn }) });
}
