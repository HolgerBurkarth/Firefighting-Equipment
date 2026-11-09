import type { DeviceListItem } from '../api/devices';
import type { Location } from '../api/locations';
import type { StockState } from '../api/stock';
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

/** Höchstzahl der angebotenen Ersatzgeräte je Liste. */
export const SWAP_CANDIDATE_LIMIT = 30;

/** Ersatz mit dem Standort der Ebene 1, an dem er steht. */
export interface SwapCandidate {
    readonly device: DeviceListItem;
    readonly top: Location | null;
    /** Der Sollbestand dort würde durch die Entnahme unterschritten (nur mit geladenem Sollbestand). */
    readonly belowMinimum: boolean;
}

export interface SwapCandidates {
    /** Passender Typ, aus Lagern bzw. Räumen (Lager mit Aufgabe zuerst). */
    readonly suggested: readonly SwapCandidate[];
    /** Passender Typ, von anderen Fahrzeugen (hinter „Andere Standorte anzeigen“). */
    readonly others: readonly SwapCandidate[];
}

/** Standort der Ebene 1 zu einer Standort-ID (auch eines Fachs). */
export function topLocationOf(
    locations: readonly Location[] | null,
    locationId: number,
): Location | null {
    for (const location of locations ?? []) {
        if (location.id === locationId || location.children.some((c) => c.id === locationId)) {
            return location;
        }
    }
    return null;
}

/** Würde die Entnahme einer Ausrüstung dieses Typs an diesem Standort eine Regel unterschreiten? */
export function wouldBreakStock(
    stock: StockState | null,
    top: Location | null,
    typeId: number,
): boolean {
    if (stock === null || top === null) {
        return false;
    }
    return stock.entries.some(
        (entry) =>
            entry.location.id === top.id &&
            entry.rules.some(
                (rule) =>
                    rule.types.some((type) => type.id === typeId) && rule.have - 1 < rule.minCount,
            ),
    );
}

/**
 * Ersatz für einen Tausch: einsatzbereit, nur der Typ der Ausrüstung bzw. die gleichwertigen Typen der
 * verletzten Regel (`typeIds`), nie dieselbe Ausrüstung und nichts vom Standort der Ausrüstung selbst.
 * Vorschläge kommen aus Lagern und Räumen (Standort mit Aufgabe Lager zuerst), Ausrüstung von anderen
 * Fahrzeugen steht getrennt in `others`. Mit Suchtext gefiltert, sonst nach Nummer wie die Liste.
 */
export function swapCandidates(
    devices: readonly DeviceListItem[],
    device: DeviceListItem,
    query: string,
    typeIds: readonly number[] = [],
    locations: readonly Location[] | null = null,
    stock: StockState | null = null,
): SwapCandidates {
    const wanted = typeIds.length > 0 ? typeIds : [device.deviceType.id];
    const ownTop = topLocationOf(locations, device.location.id);
    const ready = devices.filter(
        (entry) =>
            entry.id !== device.id &&
            entry.status === 'ready' &&
            wanted.includes(entry.deviceType.id),
    );
    const found = query.trim() === '' ? ready : searchDevices(ready, query, ready.length).hits;
    const suggested: SwapCandidate[] = [];
    const others: SwapCandidate[] = [];
    for (const entry of found) {
        const top = topLocationOf(locations, entry.location.id);
        if (ownTop !== null && top !== null && top.id === ownTop.id) {
            continue;
        }
        const isVehicle = top?.kind === 'vehicle' && top.task !== 'store';
        (isVehicle ? others : suggested).push({
            device: entry,
            top,
            belowMinimum: isVehicle && wouldBreakStock(stock, top, entry.deviceType.id),
        });
    }
    const stores = suggested.filter((c) => c.top?.task === 'store');
    const rest = suggested.filter((c) => c.top?.task !== 'store');
    return {
        suggested: [...stores, ...rest].slice(0, SWAP_CANDIDATE_LIMIT),
        others: others.slice(0, SWAP_CANDIDATE_LIMIT),
    };
}
