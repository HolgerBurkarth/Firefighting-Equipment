import { apiRequest, isRecord, type ApiResult } from './client';
import { isCount, isId } from './devices';

/**
 * Sollbestand (Phase 2, P2.7): GET stock liefert die Regeln mit dem Ist-Bestand, POST
 * locations/{id}/stock-rules ersetzt alle Regeln eines Standorts (StockHandler).
 */

export interface StockType {
    readonly id: number;
    readonly name: string;
}

export interface StockRule {
    readonly id: number;
    /** Soll: mindestens so viele einsatzbereite Ausrüstungen dieser Typen (zusammengezählt). */
    readonly minCount: number;
    readonly types: readonly StockType[];
    /** Ist: einsatzbereite Ausrüstungen dieser Typen am Standort (bei der Ebene 1 samt Fächern). */
    readonly have: number;
    readonly missing: number;
}

export interface StockEntry {
    readonly location: {
        readonly id: number;
        readonly name: string;
        /** „HLF 20 / G1“ bei einem Fach. */
        readonly path: string;
        readonly level: 1 | 2;
    };
    readonly rules: readonly StockRule[];
    /** Mindestens eine Regel ist unterschritten. */
    readonly outOfService: boolean;
}

export interface StockState {
    readonly entries: readonly StockEntry[];
    readonly outOfService: number;
}

function parseType(json: unknown): StockType | null {
    return isRecord(json) && isId(json['id']) && typeof json['name'] === 'string'
        ? { id: json['id'], name: json['name'] }
        : null;
}

function parseRule(json: unknown): StockRule | null {
    if (
        !isRecord(json) ||
        !isId(json['id']) ||
        !isCount(json['min_count']) ||
        !isCount(json['have']) ||
        !isCount(json['missing']) ||
        !Array.isArray(json['types'])
    ) {
        return null;
    }
    const types: StockType[] = [];
    for (const entry of json['types']) {
        const type = parseType(entry);
        if (type === null) {
            return null;
        }
        types.push(type);
    }
    return {
        id: json['id'],
        minCount: json['min_count'],
        types,
        have: json['have'],
        missing: json['missing'],
    };
}

function parseRules(json: unknown): StockRule[] | null {
    if (!Array.isArray(json)) {
        return null;
    }
    const rules: StockRule[] = [];
    for (const entry of json) {
        const rule = parseRule(entry);
        if (rule === null) {
            return null;
        }
        rules.push(rule);
    }
    return rules;
}

function parseEntry(json: unknown): StockEntry | null {
    if (!isRecord(json) || !isRecord(json['location'])) {
        return null;
    }
    const location = json['location'];
    const rules = parseRules(json['rules']);
    if (
        !isId(location['id']) ||
        typeof location['name'] !== 'string' ||
        typeof location['path'] !== 'string' ||
        (location['level'] !== 1 && location['level'] !== 2) ||
        rules === null ||
        typeof json['out_of_service'] !== 'boolean'
    ) {
        return null;
    }
    return {
        location: {
            id: location['id'],
            name: location['name'],
            path: location['path'],
            level: location['level'],
        },
        rules,
        outOfService: json['out_of_service'],
    };
}

export function parseStock(json: unknown): StockState | null {
    if (!isRecord(json) || !Array.isArray(json['locations']) || !isRecord(json['summary'])) {
        return null;
    }
    const entries: StockEntry[] = [];
    for (const item of json['locations']) {
        const entry = parseEntry(item);
        if (entry === null) {
            return null;
        }
        entries.push(entry);
    }
    if (!isCount(json['summary']['out_of_service'])) {
        return null;
    }
    return { entries, outOfService: json['summary']['out_of_service'] };
}

/** GET stock (Bereich Standorte, Ansehen). */
export function fetchStock(fetchFn?: typeof fetch): Promise<ApiResult<StockState>> {
    return apiRequest('stock', parseStock, { ...(fetchFn && { fetchFn }) });
}

/** Eine Regel zum Speichern. */
export interface StockRuleInput {
    readonly minCount: number;
    readonly deviceTypeIds: readonly number[];
}

/** Stand eines Standorts nach dem Speichern; `stock` null = keine Regeln mehr. */
export interface StockSaved {
    readonly stock: { readonly rules: readonly StockRule[]; readonly outOfService: boolean } | null;
}

function parseSaved(json: unknown): StockSaved | null {
    if (!isRecord(json) || !('stock' in json)) {
        return null;
    }
    const stock = json['stock'];
    if (stock === null) {
        return { stock: null };
    }
    const rules = isRecord(stock) ? parseRules(stock['rules']) : null;
    return isRecord(stock) && rules !== null && typeof stock['out_of_service'] === 'boolean'
        ? { stock: { rules, outOfService: stock['out_of_service'] } }
        : null;
}

/** POST locations/{id}/stock-rules: ersetzt alle Regeln (leer = keine). */
export function postStockRules(
    locationId: number,
    rules: readonly StockRuleInput[],
    fetchFn?: typeof fetch,
): Promise<ApiResult<StockSaved>> {
    return apiRequest(`locations/${String(locationId)}/stock-rules`, parseSaved, {
        method: 'POST',
        body: {
            rules: rules.map((rule) => ({
                min_count: rule.minCount,
                device_type_ids: [...rule.deviceTypeIds],
            })),
        },
        ...(fetchFn && { fetchFn }),
    });
}
