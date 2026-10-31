import { formatInvitationCode, normalizeInvitationCode } from '../auth/invitationCode';
import { apiRequest, isRecord, type ApiResult } from './client';
import { isCount, isId, isUtcTime, nullableString } from './devices';

/** Zustand eines Codes wie InvitationCodeStatus in PHP (berechnet, Vorrang widerrufen …). */
export type InvitationCodeStatus = 'active' | 'expired' | 'exhausted' | 'revoked';

const STATUSES: readonly InvitationCodeStatus[] = ['active', 'expired', 'exhausted', 'revoked'];

/** Einladungscode der Stadt (InvitationJson::code, nie mit Klartext oder Hash). */
export interface InvitationCode {
    readonly id: number;
    readonly label: string | null;
    readonly status: InvitationCodeStatus;
    readonly statusLabel: string;
    readonly useCount: number;
    readonly maxUses: number;
    /** UTC (`…Z`). */
    readonly expiresAt: string;
    readonly createdAt: string;
    readonly revokedAt: string | null;
    readonly createdBy: { readonly id: number; readonly label: string };
}

export function parseInvitationCode(json: unknown): InvitationCode | null {
    if (!isRecord(json)) {
        return null;
    }
    const { id, status } = json;
    const label = nullableString(json['label']);
    const statusLabel = json['status_label'];
    const useCount = json['use_count'];
    const maxUses = json['max_uses'];
    const expiresAt = json['expires_at'];
    const createdAt = json['created_at'];
    const revokedAt = json['revoked_at'];
    const createdBy = json['created_by'];
    if (
        !isId(id) ||
        label === undefined ||
        typeof status !== 'string' ||
        !(STATUSES as readonly string[]).includes(status) ||
        typeof statusLabel !== 'string' ||
        !isCount(useCount) ||
        !isCount(maxUses) ||
        !isUtcTime(expiresAt) ||
        !isUtcTime(createdAt) ||
        (revokedAt !== null && !isUtcTime(revokedAt)) ||
        !isRecord(createdBy) ||
        !isId(createdBy['id']) ||
        typeof createdBy['label'] !== 'string'
    ) {
        return null;
    }
    return {
        id,
        label,
        status: status as InvitationCodeStatus,
        statusLabel,
        useCount,
        maxUses,
        expiresAt,
        createdAt,
        revokedAt,
        createdBy: { id: createdBy['id'], label: createdBy['label'] },
    };
}

export function parseInvitationCodeList(json: unknown): InvitationCode[] | null {
    if (!isRecord(json) || !Array.isArray(json['invitation_codes'])) {
        return null;
    }
    const codes: InvitationCode[] = [];
    for (const entry of json['invitation_codes']) {
        const code = parseInvitationCode(entry);
        if (code === null) {
            return null;
        }
        codes.push(code);
    }
    return codes;
}

/** Antwort beim Anlegen: der Code im Klartext (`XXXX-XXXX-XXXX`) steht nur hier. */
export interface CreatedInvitationCode {
    readonly invitationCode: InvitationCode;
    readonly code: string;
}

/** Nur die Anzeigeform `XXXX-XXXX-XXXX` eines gültigen Codes (InvitationCodeValue::display). */
function isDisplayCode(value: unknown): value is string {
    if (typeof value !== 'string') {
        return false;
    }
    const normalized = normalizeInvitationCode(value);
    return normalized !== null && formatInvitationCode(normalized) === value;
}

export function parseCreatedInvitationCode(json: unknown): CreatedInvitationCode | null {
    if (!isRecord(json)) {
        return null;
    }
    const invitationCode = parseInvitationCode(json['invitation_code']);
    const code = json['code'];
    if (invitationCode === null || !isDisplayCode(code)) {
        return null;
    }
    return { invitationCode, code };
}

/** GET invitation-codes (Admin): alle Codes der Stadt, neueste zuerst. */
export function fetchInvitationCodes(fetchFn?: typeof fetch): Promise<ApiResult<InvitationCode[]>> {
    return apiRequest('invitation-codes', parseInvitationCodeList, { ...(fetchFn && { fetchFn }) });
}

export interface NewInvitationCode {
    /** null = ohne Bezeichnung. */
    readonly label: string | null;
    readonly validDays: number;
    readonly maxUses: number;
}

/** POST invitation-codes {label?, valid_days, max_uses} → 201 mit dem Klartext. */
export function createInvitationCode(
    request: NewInvitationCode,
    fetchFn?: typeof fetch,
): Promise<ApiResult<CreatedInvitationCode>> {
    return apiRequest('invitation-codes', parseCreatedInvitationCode, {
        method: 'POST',
        body: {
            ...(request.label !== null && { label: request.label }),
            valid_days: request.validDays,
            max_uses: request.maxUses,
        },
        ...(fetchFn && { fetchFn }),
    });
}

function parseCodeResponse(json: unknown): InvitationCode | null {
    return isRecord(json) ? parseInvitationCode(json['invitation_code']) : null;
}

/** POST invitation-codes/{id}/revoke {} (wiederholbar). */
export function revokeInvitationCode(
    id: number,
    fetchFn?: typeof fetch,
): Promise<ApiResult<InvitationCode>> {
    return apiRequest(`invitation-codes/${String(id)}/revoke`, parseCodeResponse, {
        method: 'POST',
        body: {},
        ...(fetchFn && { fetchFn }),
    });
}
