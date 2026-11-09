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
    /** Standorte mit der Aufgabe „Rückgabe“. */
    readonly returns: readonly LoanPlace[];
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
    const returns = parsePlaces(json['returns']);
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
    return {
        today: json['today'],
        open: json['summary']['open'],
        overdue: json['summary']['overdue'],
        loans,
        borrowers,
        returns,
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
