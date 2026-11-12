import { apiRequest, isRecord, type ApiResult } from './client';
import { parseBulkResponse, type BulkResponse } from './deviceBulk';
import { isCount, isId, parseDeviceListItem, type DeviceListItem } from './devices';

/**
 * Verleih (Phase 2, P2.5): GET loans liefert die offenen Verleihe, POST loans verleiht mehrere
 * Ausrüstungen an einen Standort mit der Aufgabe „Ausgeliehen an“ (LoanListHandler, LoanLendHandler).
 */

export interface LoanPlace {
    readonly id: number;
    readonly name: string;
}

/** Standort mit der Aufgabe „Rückgabe“; `linkCreatedAt` = wann der gültige Schlüssel entstand (null = keiner). */
export interface ReturnPlace extends LoanPlace {
    readonly linkCreatedAt: string | null;
}

export interface Loan {
    readonly id: number;
    readonly device: DeviceListItem;
    readonly borrower: LoanPlace;
    readonly contact: string | null;
    /** „Rückgabe bis“ als `JJJJ-MM-TT`. */
    readonly dueBackOn: string | null;
    readonly overdue: boolean;
    readonly daysOverdue: number;
    readonly comment: string | null;
    readonly lentAt: string;
    readonly lentBy: string;
}

export interface LoanState {
    readonly today: string;
    readonly open: number;
    readonly overdue: number;
    readonly loans: readonly Loan[];
    /** Ziele von „Verleihen“. */
    readonly borrowers: readonly LoanPlace[];
    /** Standorte mit der Aufgabe „Rückgabe“ und der Stand ihres Aushangs. */
    readonly returns: readonly ReturnPlace[];
    /** Ausrüstung in der Rückgabe, am längsten wartende zuerst (P2.6). */
    readonly inReturns: readonly DeviceListItem[];
    /** Anzeigenamen der Aufgaben in der Stadt. */
    readonly borrowerLabel: string;
    readonly returnsLabel: string;
}

function parsePlace(json: unknown): LoanPlace | null {
    return isRecord(json) && isId(json['id']) && typeof json['name'] === 'string'
        ? { id: json['id'], name: json['name'] }
        : null;
}

function parsePlaces(json: unknown): LoanPlace[] | null {
    if (!Array.isArray(json)) {
        return null;
    }
    const places: LoanPlace[] = [];
    for (const entry of json) {
        const place = parsePlace(entry);
        if (place === null) {
            return null;
        }
        places.push(place);
    }
    return places;
}

function parseReturnPlaces(json: unknown): ReturnPlace[] | null {
    if (!Array.isArray(json)) {
        return null;
    }
    const places: ReturnPlace[] = [];
    for (const entry of json) {
        const place = parsePlace(entry);
        const created = isRecord(entry) ? entry['link_created_at'] : undefined;
        if (
            place === null ||
            (created !== null && created !== undefined && typeof created !== 'string')
        ) {
            return null;
        }
        places.push({ ...place, linkCreatedAt: created ?? null });
    }
    return places;
}

function optionalText(value: unknown): string | null | undefined {
    return value === null || typeof value === 'string' ? value : undefined;
}

function parseLoan(json: unknown): Loan | null {
    if (!isRecord(json)) {
        return null;
    }
    const device = parseDeviceListItem(json['device']);
    const borrower = parsePlace(json['borrower']);
    const lentBy = isRecord(json['lent_by']) ? json['lent_by']['name'] : undefined;
    const contact = optionalText(json['contact']);
    const due = optionalText(json['due_back_on']);
    const comment = optionalText(json['comment']);
    if (
        !isId(json['id']) ||
        device === null ||
        borrower === null ||
        contact === undefined ||
        due === undefined ||
        comment === undefined ||
        typeof json['overdue'] !== 'boolean' ||
        !isCount(json['days_overdue']) ||
        typeof json['lent_at'] !== 'string' ||
        typeof lentBy !== 'string'
    ) {
        return null;
    }
    return {
        id: json['id'],
        device,
        borrower,
        contact,
        dueBackOn: due,
        overdue: json['overdue'],
        daysOverdue: json['days_overdue'],
        comment,
        lentAt: json['lent_at'],
        lentBy,
    };
}

export function parseLoans(json: unknown): LoanState | null {
    if (!isRecord(json) || !isRecord(json['summary']) || !isRecord(json['labels'])) {
        return null;
    }
    const borrowers = parsePlaces(json['borrowers']);
    const returns = parseReturnPlaces(json['returns']);
    const labels = json['labels'];
    if (
        typeof json['today'] !== 'string' ||
        !isCount(json['summary']['open']) ||
        !isCount(json['summary']['overdue']) ||
        !Array.isArray(json['loans']) ||
        borrowers === null ||
        returns === null ||
        typeof labels['borrower'] !== 'string' ||
        typeof labels['returns'] !== 'string'
    ) {
        return null;
    }
    const loans: Loan[] = [];
    for (const entry of json['loans']) {
        const loan = parseLoan(entry);
        if (loan === null) {
            return null;
        }
        loans.push(loan);
    }
    const inReturns: DeviceListItem[] = [];
    for (const entry of Array.isArray(json['in_returns']) ? json['in_returns'] : []) {
        const device = parseDeviceListItem(entry);
        if (device === null) {
            return null;
        }
        inReturns.push(device);
    }
    return {
        today: json['today'],
        open: json['summary']['open'],
        overdue: json['summary']['overdue'],
        loans,
        borrowers,
        returns,
        inReturns,
        borrowerLabel: labels['borrower'],
        returnsLabel: labels['returns'],
    };
}

/** GET loans (Bereich Verleih, Ansehen). */
export function fetchLoans(fetchFn?: typeof fetch): Promise<ApiResult<LoanState>> {
    return apiRequest('loans', parseLoans, { ...(fetchFn && { fetchFn }) });
}

export interface LendEntry {
    readonly id: number;
    readonly version: number;
}

export interface LendRequest {
    readonly borrowerLocationId: number;
    readonly contact?: string;
    readonly dueBackOn?: string;
    readonly comment?: string;
}

/** POST loans (Bereich Verleih, Ändern): Antwort wie die Sammelaktionen der Liste. */
export function postLend(
    entries: readonly LendEntry[],
    request: LendRequest,
    fetchFn?: typeof fetch,
): Promise<ApiResult<BulkResponse>> {
    return apiRequest('loans', parseBulkResponse, {
        method: 'POST',
        body: {
            devices: entries.map((entry) => ({ id: entry.id, version: entry.version })),
            borrower_location_id: request.borrowerLocationId,
            ...(request.contact !== undefined && { contact: request.contact }),
            ...(request.dueBackOn !== undefined && { due_back_on: request.dueBackOn }),
            ...(request.comment !== undefined && { comment: request.comment }),
        },
        ...(fetchFn && { fetchFn }),
    });
}

/** POST loans/to-collection bzw. loans/to-store (Rückgaben bearbeiten, P2.6). */
export function postProcessReturns(
    kind: 'to-collection' | 'to-store',
    entries: readonly LendEntry[],
    comment?: string,
    fetchFn?: typeof fetch,
): Promise<ApiResult<BulkResponse>> {
    return apiRequest(`loans/${kind}`, parseBulkResponse, {
        method: 'POST',
        body: {
            devices: entries.map((entry) => ({ id: entry.id, version: entry.version })),
            ...(comment !== undefined && { comment }),
        },
        ...(fetchFn && { fetchFn }),
    });
}

function parseKey(json: unknown): string | null {
    return isRecord(json) && typeof json['key'] === 'string' ? json['key'] : null;
}

/** POST return-points/{id}/renew: neuer Schlüssel (der Klartext steht nur in dieser Antwort). */
export function renewReturnKey(
    locationId: number,
    fetchFn?: typeof fetch,
): Promise<ApiResult<string>> {
    return apiRequest(`return-points/${String(locationId)}/renew`, parseKey, {
        method: 'POST',
        ...(fetchFn && { fetchFn }),
    });
}

/** POST return-points/{id}/revoke: Aushang ungültig machen. */
export function revokeReturnKey(
    locationId: number,
    fetchFn?: typeof fetch,
): Promise<ApiResult<boolean>> {
    return apiRequest(
        `return-points/${String(locationId)}/revoke`,
        (json) => (isRecord(json) && json['revoked'] === true ? true : null),
        { method: 'POST', ...(fetchFn && { fetchFn }) },
    );
}

/** Zustand der zurückgegebenen Ausrüstung (Rückgabe ohne Anmeldung). */
export type ReturnCondition = 'ok' | 'used' | 'defective';

export interface ReturnFound {
    readonly identifier: string;
    readonly deviceType: string;
    /** Name der Rückgabestelle. */
    readonly location: string;
}

function parseReturnFound(json: unknown): ReturnFound | null {
    return isRecord(json) &&
        typeof json['identifier'] === 'string' &&
        typeof json['device_type'] === 'string' &&
        typeof json['location'] === 'string'
        ? {
              identifier: json['identifier'],
              deviceType: json['device_type'],
              location: json['location'],
          }
        : null;
}

/** POST returns/check (öffentlich, ohne Anmeldung): Nummer und Typ einer verliehenen Ausrüstung. */
export function checkReturn(
    key: string,
    code: string,
    fetchFn?: typeof fetch,
): Promise<ApiResult<ReturnFound>> {
    return apiRequest('returns/check', parseReturnFound, {
        method: 'POST',
        body: { key, code },
        public: true,
        ...(fetchFn && { fetchFn }),
    });
}

export interface ReturnDone extends ReturnFound {
    readonly statusLabel: string;
    /** Zeit der Rückgabe laut Server (UTC, `…Z`); fehlt bei einem älteren Server. */
    readonly returnedAt: string | null;
}

function parseReturnDone(json: unknown): ReturnDone | null {
    const found = parseReturnFound(json);
    return found !== null && isRecord(json) && typeof json['status_label'] === 'string'
        ? {
              ...found,
              statusLabel: json['status_label'],
              returnedAt: typeof json['returned_at'] === 'string' ? json['returned_at'] : null,
          }
        : null;
}

/** POST returns (öffentlich, ohne Anmeldung): bucht die Ausrüstung in die Rückgabe. */
export function postReturn(
    request: {
        readonly key: string;
        readonly code: string;
        readonly name: string;
        readonly condition: ReturnCondition;
        readonly comment?: string;
    },
    fetchFn?: typeof fetch,
): Promise<ApiResult<ReturnDone>> {
    return apiRequest('returns', parseReturnDone, {
        method: 'POST',
        body: {
            key: request.key,
            code: request.code,
            name: request.name,
            condition: request.condition,
            ...(request.comment !== undefined && { comment: request.comment }),
        },
        public: true,
        ...(fetchFn && { fetchFn }),
    });
}
