import type { DeviceListParams, DeviceSort, DeviceStatus } from '../api/devices';
import { daysBetween } from './changeTime';
import { INSPECTION_WINDOW_DAYS } from './inspections';

/** Status, nach denen die Geräteliste filtert (ausgesonderte über `showRetired`). */
export type ActiveStatus = Exclude<DeviceStatus, 'retired'>;
export const ACTIVE_STATUSES: readonly ActiveStatus[] = ['ready', 'maintenance', 'defective'];

export const DEVICE_SORTS: readonly DeviceSort[] = [
    'identifier',
    'device_type',
    'location',
    'status',
    'next_inspection_due',
];

/** Höchstlänge von `q` wie DeviceListHandler (100 Zeichen). */
export const DEVICE_QUERY_MAX_LENGTH = 100;

/** Filter und Sortierung der Geräteliste der Admin-Konsole. */
export interface DeviceListFilter {
    /** Suchtext wie eingegeben (Server: Kennung, Scan-Code, Seriennummer, Hersteller, Modell). */
    readonly q: string;
    readonly deviceTypeId: number | null;
    /** Standort mit seinen Fächern. */
    readonly locationId: number | null;
    /** Leer = alle; sonst nur diese. */
    readonly statuses: readonly ActiveStatus[];
    /** Ausgesonderte zusätzlich zeigen (KONZEPT.md, "Admin-Konsole – Entscheidungen", 33). */
    readonly showRetired: boolean;
    readonly sort: DeviceSort;
    readonly dir: 'asc' | 'desc';
}

export const DEFAULT_DEVICE_FILTER: DeviceListFilter = {
    q: '',
    deviceTypeId: null,
    locationId: null,
    statuses: [],
    showRetired: false,
    sort: 'identifier',
    dir: 'asc',
};

/** Parameter der Adresse `geraete?…` (deutsch, wie die Seiten; Werte der API). */
export const DEVICE_LIST_QUERY = {
    q: 'suche',
    deviceTypeId: 'typ',
    locationId: 'standort',
    statuses: 'status',
    showRetired: 'ausgesonderte',
    sort: 'sortierung',
    dir: 'richtung',
} as const;

type QueryValue = string | null | readonly (string | null)[] | undefined;

function single(value: QueryValue): string | null {
    if (Array.isArray(value)) {
        return single(value[0]);
    }
    return typeof value === 'string' ? value : null;
}

function id(value: QueryValue): number | null {
    const text = single(value);
    // Wie die Platzhalter im Backend: positive Ganzzahl ohne führende Null.
    return text !== null && /^[1-9]\d{0,14}$/.test(text) ? Number(text) : null;
}

function isActiveStatus(value: string): value is ActiveStatus {
    return (ACTIVE_STATUSES as readonly string[]).includes(value);
}

function isSort(value: string | null): value is DeviceSort {
    return value !== null && (DEVICE_SORTS as readonly string[]).includes(value);
}

/**
 * Filter aus der Adresse (z. B. vom Dashboard `geraete?status=maintenance,defective`); Unbekanntes
 * fällt still auf den Standard zurück (eine alte oder von Hand geänderte Adresse soll die Liste
 * nicht leer machen).
 */
export function filterFromQuery(query: Readonly<Record<string, QueryValue>>): DeviceListFilter {
    const statuses = (single(query[DEVICE_LIST_QUERY.statuses]) ?? '')
        .split(',')
        .filter(isActiveStatus);
    const sort = single(query[DEVICE_LIST_QUERY.sort]);
    const dir = single(query[DEVICE_LIST_QUERY.dir]);
    return {
        q: (single(query[DEVICE_LIST_QUERY.q]) ?? '').slice(0, DEVICE_QUERY_MAX_LENGTH),
        deviceTypeId: id(query[DEVICE_LIST_QUERY.deviceTypeId]),
        locationId: id(query[DEVICE_LIST_QUERY.locationId]),
        // Doppelte entfernt, in fester Reihenfolge (gleiche Filter = gleiche Adresse).
        statuses: ACTIVE_STATUSES.filter((status) => statuses.includes(status)),
        showRetired: single(query[DEVICE_LIST_QUERY.showRetired]) === '1',
        sort: isSort(sort) ? sort : DEFAULT_DEVICE_FILTER.sort,
        dir: dir === 'desc' ? 'desc' : 'asc',
    };
}

/** Adresse zum Filter, ohne Standardwerte (`geraete` ohne Parameter = alles). */
export function filterToQuery(filter: DeviceListFilter): Record<string, string> {
    const query: Record<string, string> = {};
    if (filter.q.trim() !== '') {
        query[DEVICE_LIST_QUERY.q] = filter.q;
    }
    if (filter.deviceTypeId !== null) {
        query[DEVICE_LIST_QUERY.deviceTypeId] = String(filter.deviceTypeId);
    }
    if (filter.locationId !== null) {
        query[DEVICE_LIST_QUERY.locationId] = String(filter.locationId);
    }
    if (filter.statuses.length > 0) {
        query[DEVICE_LIST_QUERY.statuses] = filter.statuses.join(',');
    }
    if (filter.showRetired) {
        query[DEVICE_LIST_QUERY.showRetired] = '1';
    }
    if (filter.sort !== DEFAULT_DEVICE_FILTER.sort) {
        query[DEVICE_LIST_QUERY.sort] = filter.sort;
    }
    if (filter.dir !== DEFAULT_DEVICE_FILTER.dir) {
        query[DEVICE_LIST_QUERY.dir] = filter.dir;
    }
    return query;
}

/** Gleiche Filter (für „schon geladen“), Suchtext ohne Leerraum am Rand. */
export function filterKey(filter: DeviceListFilter): string {
    return JSON.stringify(filterToQuery({ ...filter, q: filter.q.trim() }));
}

/** Sind außer der Sortierung Filter gesetzt (für „Filter zurücksetzen“ und die Anzahl)? */
export function hasFilters(filter: DeviceListFilter): boolean {
    return (
        filter.q.trim() !== '' ||
        filter.deviceTypeId !== null ||
        filter.locationId !== null ||
        filter.statuses.length > 0 ||
        filter.showRetired
    );
}

/**
 * Parameter für GET devices und GET devices/export. Ausgesonderte: mit Statusfilter als weiterer
 * Status, sonst `include_retired` (DeviceListHandler). Der Standort gilt immer mit seinen Fächern
 * (bei einem Fach ändert das nichts).
 */
export function deviceListParams(filter: DeviceListFilter): DeviceListParams {
    const q = filter.q.trim();
    const status: DeviceStatus[] = [...filter.statuses];
    if (status.length > 0 && filter.showRetired) {
        status.push('retired');
    }
    return {
        ...(q !== '' && { q }),
        ...(status.length > 0 && { status }),
        ...(status.length === 0 && filter.showRetired && { includeRetired: true }),
        ...(filter.deviceTypeId !== null && { deviceTypeId: filter.deviceTypeId }),
        ...(filter.locationId !== null && {
            locationId: filter.locationId,
            includeSublocations: true,
        }),
        sort: filter.sort,
        dir: filter.dir,
    };
}

/** Prüfdatum im Vergleich zu heute: überfällig, in den nächsten 30 Tagen fällig oder nichts. */
export function dueState(
    due: string | null,
    today: string,
    status: DeviceStatus,
): 'overdue' | 'soon' | null {
    if (due === null || status === 'retired') {
        return null;
    }
    const days = daysBetween(today, due);
    if (days < 0) {
        return 'overdue';
    }
    return days <= INSPECTION_WINDOW_DAYS ? 'soon' : null;
}

/**
 * „45 Geräte“, mit Filter „12 Geräte gefunden“ (die Gesamtzahl ohne Filter fragt die Liste nicht
 * ab).
 */
export function deviceCountLabel(total: number, filtered: boolean): string {
    const count = total === 1 ? '1 Gerät' : `${String(total)} Geräte`;
    return filtered ? `${count} gefunden` : count;
}
