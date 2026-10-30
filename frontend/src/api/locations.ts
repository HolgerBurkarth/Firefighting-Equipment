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

/** GET locations?include_inactive=1: Baum mit gesperrten Standorten (Verwaltung des Admins). */
export function fetchAllLocations(fetchFn?: typeof fetch): Promise<ApiResult<readonly Location[]>> {
    return apiRequest('locations?include_inactive=1', parseLocationTree, {
        ...(fetchFn && { fetchFn }),
    });
}

/** Antwort der ändernden Routen `{"location": …}`: Standort der Ebene 1 oder ein Fach. */
export interface SavedLocation {
    readonly id: number;
    readonly name: string;
    readonly level: 1 | 2;
    readonly active: boolean;
}

export function parseLocationResponse(json: unknown): SavedLocation | null {
    if (!isRecord(json) || !isRecord(json['location'])) {
        return null;
    }
    const entry = json['location'];
    const parentId = entry['parent_id'];
    const location =
        entry['level'] === 1
            ? parseLocation(entry)
            : isId(parentId)
              ? parseSubLocation(entry, parentId)
              : null;
    if (location === null) {
        return null;
    }
    const level = entry['level'] === 1 ? 1 : 2;
    return { id: location.id, name: location.name, level, active: location.active };
}

/** Neuer Standort: Ebene 1 mit Art oder ein Fach unter `parentId` (übernimmt dessen Art). */
export type NewLocation =
    | { readonly name: string; readonly kind: LocationKind }
    | { readonly name: string; readonly parentId: number };

/** POST locations (Admin). Der Name geht unverändert, der Server entfernt Leerraum am Rand. */
export function createLocation(
    location: NewLocation,
    fetchFn?: typeof fetch,
): Promise<ApiResult<SavedLocation>> {
    return apiRequest('locations', parseLocationResponse, {
        method: 'POST',
        body:
            'kind' in location
                ? { name: location.name, kind: location.kind }
                : { name: location.name, parent_id: location.parentId },
        ...(fetchFn && { fetchFn }),
    });
}

/** POST locations/{id}/rename (Admin). */
export function renameLocation(
    id: number,
    name: string,
    fetchFn?: typeof fetch,
): Promise<ApiResult<SavedLocation>> {
    return apiRequest(`locations/${String(id)}/rename`, parseLocationResponse, {
        method: 'POST',
        body: { name },
        ...(fetchFn && { fetchFn }),
    });
}

/** POST locations/{id}/lock bzw. unlock (Admin, wiederholbar; Sperren nur, wenn leer). */
export function setLocationActive(
    id: number,
    active: boolean,
    fetchFn?: typeof fetch,
): Promise<ApiResult<SavedLocation>> {
    return apiRequest(
        `locations/${String(id)}/${active ? 'unlock' : 'lock'}`,
        parseLocationResponse,
        { method: 'POST', ...(fetchFn && { fetchFn }) },
    );
}
