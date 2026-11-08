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
    /** Aufgabe (Phase 2, P2.2); fehlt bei älteren Antworten und Offline-Kopien = keine. */
    readonly task?: LocationTaskId | null;
    /** Standard-Lager der Stadt (nur bei task `store`). */
    readonly isDefaultStore?: boolean;
    /** Nur bei der Sammelstelle: was sie annimmt. */
    readonly collectionAccepts?: CollectionAccepts | null;
    readonly kindLabel: string;
    /**
     * false: Die Rolle sieht nur Fächer dieses Standorts (Datenfilter, Schritt 1.4); er selbst ist
     * kein Ziel. Fehlt bei älteren Antworten und Offline-Kopien, daher nur über `isSelectable` lesen.
     */
    readonly selectable?: boolean;
    /** Einschließlich aller Unterstandorte, auch nicht aufgeführter. */
    readonly deviceCountTotal: number;
    readonly children: readonly SubLocation[];
}

/** Aufgabe wie LocationTask in PHP. */
export type LocationTaskId = 'store' | 'collection' | 'workshop' | 'returns' | 'borrower';

export const LOCATION_TASK_IDS: readonly LocationTaskId[] = [
    'store',
    'collection',
    'workshop',
    'returns',
    'borrower',
];

/** Was eine Sammelstelle annimmt (collection_accepts). */
export type CollectionAccepts = 'used' | 'defective' | 'both';

export const COLLECTION_ACCEPTS: readonly { value: CollectionAccepts; label: string }[] = [
    { value: 'both', label: 'benutzte und defekte' },
    { value: 'used', label: 'nur benutzte' },
    { value: 'defective', label: 'nur defekte' },
];

export function isLocationTask(value: unknown): value is LocationTaskId {
    return typeof value === 'string' && (LOCATION_TASK_IDS as readonly string[]).includes(value);
}

function isKind(value: unknown): value is LocationKind {
    return typeof value === 'string' && (KINDS as readonly string[]).includes(value);
}

function isCount(value: unknown): value is number {
    return typeof value === 'number' && Number.isSafeInteger(value) && value >= 0;
}

/**
 * Gerätezahl direkt am Standort; ein Standort, von dem die Rolle nur Fächer sieht (Datenfilter,
 * `selectable` false), kommt ohne Zahl (null) und zählt hier mit 0; Ziel ist er dann nicht
 * (`isSelectable`). Ungültig: null.
 */
function directCount(json: Record<string, unknown>): number | null {
    const count = json['device_count'];
    if (count === null && json['selectable'] === false) {
        return 0;
    }
    return isCount(count) ? count : null;
}

function parseSubLocation(json: unknown, parent: number): SubLocation | null {
    if (!isRecord(json)) {
        return null;
    }
    const { id, name, kind, level, active } = json;
    const parentId = json['parent_id'];
    const deviceCount = directCount(json);
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
    const deviceCount = directCount(json);
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
    const selectable = json['selectable'] !== false;
    const task = isLocationTask(json['task']) ? json['task'] : null;
    const accepts = json['collection_accepts'];
    return {
        task,
        isDefaultStore: json['is_default_store'] === true,
        collectionAccepts:
            accepts === 'used' || accepts === 'defective' || accepts === 'both' ? accepts : null,
        id,
        name,
        kind,
        kindLabel,
        active,
        selectable,
        deviceCount,
        deviceCountTotal,
        children,
    };
}

/** Darf der Standort der Ebene 1 selbst Ziel sein (nicht nur seine Fächer, Datenfilter)? */
export function isSelectable(location: Location): boolean {
    return location.selectable !== false;
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

/** Aufgabe einer Stadt (GET location-tasks bzw. tenants/{id}/location-tasks). */
export interface TaskSetting {
    readonly task: LocationTaskId;
    /** Name in der Oberfläche (eigener oder Standardname). */
    readonly label: string;
    readonly defaultLabel: string;
    /** Vom Super-Admin freigeschaltet. */
    readonly enabled: boolean;
    /** Standorte, die die Aufgabe tragen. */
    readonly locations: number;
}

export function parseTaskSettings(json: unknown): readonly TaskSetting[] | null {
    if (!isRecord(json) || !Array.isArray(json['tasks'])) {
        return null;
    }
    const tasks: TaskSetting[] = [];
    for (const entry of json['tasks']) {
        if (!isRecord(entry)) {
            return null;
        }
        const { task, label, enabled, locations } = entry;
        const defaultLabel = entry['default_label'];
        if (
            !isLocationTask(task) ||
            typeof label !== 'string' ||
            typeof defaultLabel !== 'string' ||
            typeof enabled !== 'boolean' ||
            !isCount(locations)
        ) {
            return null;
        }
        tasks.push({ task, label, defaultLabel, enabled, locations });
    }
    return tasks;
}

/** GET location-tasks: die Aufgaben der Stadt (der Admin wählt nur freigeschaltete). */
export function fetchLocationTasks(
    fetchFn?: typeof fetch,
): Promise<ApiResult<readonly TaskSetting[]>> {
    return apiRequest('location-tasks', parseTaskSettings, { ...(fetchFn && { fetchFn }) });
}

/** POST locations/{id}/task (Admin): Aufgabe setzen oder mit null entfernen. */
export function postLocationTask(
    id: number,
    task: LocationTaskId | null,
    options: {
        readonly defaultStore?: boolean;
        readonly collectionAccepts?: CollectionAccepts;
    } = {},
    fetchFn?: typeof fetch,
): Promise<ApiResult<SavedLocation>> {
    return apiRequest(`locations/${String(id)}/task`, parseLocationResponse, {
        method: 'POST',
        body: {
            task,
            ...(options.defaultStore !== undefined && { is_default_store: options.defaultStore }),
            ...(options.collectionAccepts !== undefined && {
                collection_accepts: options.collectionAccepts,
            }),
        },
        ...(fetchFn && { fetchFn }),
    });
}

/** GET tenants/{id}/location-tasks (Super-Admin). */
export function fetchTenantLocationTasks(
    tenantId: number,
    fetchFn?: typeof fetch,
): Promise<ApiResult<readonly TaskSetting[]>> {
    return apiRequest(`tenants/${String(tenantId)}/location-tasks`, parseTaskSettings, {
        ...(fetchFn && { fetchFn }),
    });
}

/** POST tenants/{id}/location-tasks (Super-Admin): freischalten und umbenennen. */
export function saveTenantLocationTasks(
    tenantId: number,
    entries: readonly { task: LocationTaskId; enabled: boolean; label: string | null }[],
    fetchFn?: typeof fetch,
): Promise<ApiResult<readonly TaskSetting[]>> {
    return apiRequest(`tenants/${String(tenantId)}/location-tasks`, parseTaskSettings, {
        method: 'POST',
        body: { tasks: entries },
        ...(fetchFn && { fetchFn }),
    });
}
