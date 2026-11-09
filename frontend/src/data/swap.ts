import type { DeviceListItem } from '../api/devices';
import { searchDevices } from './deviceSearch';

/** Ausrüstung, die herausgeht: Status danach (POST devices/swap `out_status`). */
export type SwapOutStatus = 'used' | 'defective' | 'maintenance';

export const SWAP_OUT_STATUSES: readonly { value: SwapOutStatus; label: string }[] = [
    { value: 'used', label: 'benutzt' },
    { value: 'defective', label: 'defekt' },
    { value: 'maintenance', label: 'in Wartung' },
];

/** Die Angaben des Servers, wenn eine Buchung den Sollbestand unterschreiten würde (`details.stock`). */
export interface StockBlock {
    readonly locationId: number;
    readonly locationPath: string;
    readonly minCount: number;
    readonly have: number;
    readonly missing: number;
    readonly typeIds: readonly number[];
    readonly typeNames: readonly string[];
    /** Die Rolle darf nach Rückfrage trotzdem buchen (`stock.override`). */
    readonly canOverride: boolean;
}

function record(value: unknown): Record<string, unknown> | null {
    return typeof value === 'object' && value !== null && !Array.isArray(value)
        ? (value as Record<string, unknown>)
        : null;
}

/** `details` einer 409 `rule_violation` → Sollbestand, sonst null (andere Regelverstöße). */
export function parseStockBlock(
    details: Readonly<Record<string, unknown>> | null,
): StockBlock | null {
    const stock = record(details?.['stock']);
    const location = record(stock?.['location']);
    const rule = record(stock?.['rule']);
    if (stock === null || location === null || rule === null) {
        return null;
    }
    const types = Array.isArray(rule['types']) ? rule['types'].map(record) : [];
    const locationId = location['id'];
    const path = location['path'];
    const minCount = rule['min_count'];
    const have = stock['have'];
    const missing = stock['missing'];
    if (
        typeof locationId !== 'number' ||
        typeof path !== 'string' ||
        typeof minCount !== 'number' ||
        typeof have !== 'number' ||
        typeof missing !== 'number' ||
        types.some((type) => type === null)
    ) {
        return null;
    }
    const known = types.filter((type) => type !== null);
    return {
        locationId,
        locationPath: path,
        minCount,
        have,
        missing,
        typeIds: known.map((type) => Number(type['id'])),
        typeNames: known.map((type) => String(type['name'])),
        canOverride: stock['can_override'] === true,
    };
}

/** Hinweis über den Knöpfen: „Am Standort „HLF 20“ blieben nur 1 von mindestens 2 …“. */
export function stockBlockText(block: StockBlock): string {
    const types = block.typeNames.join(' oder ');
    return `Am Standort „${block.locationPath}“ blieben nach dieser Buchung nur ${String(block.have)} von mindestens ${String(block.minCount)} (${types}) einsatzbereit.`;
}

/** Höchstzahl der angebotenen Ersatzgeräte. */
export const SWAP_CANDIDATE_LIMIT = 30;

/**
 * Ersatz für einen Tausch: einsatzbereit, nicht dieselbe Ausrüstung. Zuerst Typen der verletzten Regel
 * (`typeIds`, sonst der gleiche Typ wie das Gerät), dann nach Nummer wie die Liste; mit Suchtext gefiltert.
 */
export function swapCandidates(
    devices: readonly DeviceListItem[],
    device: DeviceListItem,
    query: string,
    typeIds: readonly number[] = [],
): readonly DeviceListItem[] {
    const wanted = typeIds.length > 0 ? typeIds : [device.deviceType.id];
    const ready = devices.filter((entry) => entry.id !== device.id && entry.status === 'ready');
    const found = query.trim() === '' ? ready : searchDevices(ready, query, ready.length).hits;
    const fits = found.filter((entry) => wanted.includes(entry.deviceType.id));
    const rest = found.filter((entry) => !wanted.includes(entry.deviceType.id));
    return [...fits, ...rest].slice(0, SWAP_CANDIDATE_LIMIT);
}
