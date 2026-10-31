import { apiRequest, isRecord, type ApiResult } from './client';
import type { EventLocation } from './deviceEvents';
import { isCount, isId, isStatus, isUtcTime, nullableString, type DeviceStatus } from './devices';

/** Status eines Hinweises (NoticeStatus). */
export type NoticeStatus = 'open' | 'resolved';

/** Filter der Liste: GET notices?status=… (Standard des Servers `open`). */
export type NoticeFilter = NoticeStatus | 'all';

/** Konto in einem Hinweis (Anzeigename, E-Mail; anonymisiert „Gelöschter Benutzer #ID“ ohne E-Mail). */
export interface NoticeUser {
    readonly id: number;
    readonly name: string;
    readonly email: string | null;
}

/** Eintrag im Verlauf eines Hinweises (erledigt, wieder geöffnet). */
export interface NoticeHistoryEntry {
    readonly id: number;
    /** resolve, reopen. */
    readonly action: string;
    readonly actionLabel: string;
    readonly user: NoticeUser;
    /** UTC `…Z`. */
    readonly at: string;
    /** Notiz beim Erledigen (mehrzeilig) oder null. */
    readonly note: string | null;
}

/** Status mit deutscher Bezeichnung; null = ungültig. */
function parseStatus(
    value: unknown,
    label: unknown,
): { value: DeviceStatus; label: string } | null {
    return isStatus(value) && typeof label === 'string' ? { value, label } : null;
}

/** Hinweis an die Admins zu einer Offline-Buchung (NoticeJson::notice). */
export interface Notice {
    readonly id: number;
    /** overwritten, history_only, not_applied_retired, not_applied_location_locked, stale_confirmed. */
    readonly kind: string;
    readonly kindLabel: string;
    readonly status: NoticeStatus;
    readonly statusLabel: string;
    /** Die Buchung war älter als erlaubt und wurde nach Rückfrage gesendet. */
    readonly staleConfirmed: boolean;
    /** Eingang der Buchung = Anlegen des Hinweises, UTC `…Z`. */
    readonly createdAt: string;
    readonly updatedAt: string;
    /** Aktueller Stand von Kennung und Typ. */
    readonly device: {
        readonly id: number;
        readonly identifier: string;
        readonly deviceType: string;
    };
    readonly booking: {
        readonly user: NoticeUser;
        /** Aktionszeit auf dem Handy, UTC `…Z`. */
        readonly occurredAt: string;
        readonly receivedAt: string;
        /** Gewünschter Standort bzw. Status; null = nicht Teil der Buchung. */
        readonly location: EventLocation | null;
        readonly status: { readonly value: DeviceStatus; readonly label: string } | null;
        readonly comment: string | null;
    };
    /** Stand beim Eingang der Buchung (bei `overwritten` der überschriebene). */
    readonly other: {
        readonly user: NoticeUser | null;
        readonly occurredAt: string;
        readonly location: EventLocation;
        readonly status: { readonly value: DeviceStatus; readonly label: string };
    };
    /** Älteste zuerst. */
    readonly history: readonly NoticeHistoryEntry[];
}

export interface NoticePage {
    readonly notices: readonly Notice[];
    /** Anzahl für den Filter. */
    readonly total: number;
    /** Offene Hinweise insgesamt (unabhängig vom Filter). */
    readonly open: number;
}

function parseUser(json: unknown): NoticeUser | null {
    if (!isRecord(json) || !isId(json['id']) || typeof json['name'] !== 'string') {
        return null;
    }
    const email = nullableString(json['email']);
    return email === undefined ? null : { id: json['id'], name: json['name'], email };
}

function parseLocation(json: unknown): EventLocation | null {
    if (
        !isRecord(json) ||
        !isId(json['id']) ||
        typeof json['name'] !== 'string' ||
        typeof json['path'] !== 'string'
    ) {
        return null;
    }
    return { id: json['id'], name: json['name'], path: json['path'] };
}

function parseHistoryEntry(json: unknown): NoticeHistoryEntry | null {
    if (!isRecord(json)) {
        return null;
    }
    const user = parseUser(json['user']);
    const note = nullableString(json['note']);
    const { id, action, at } = json;
    const actionLabel = json['action_label'];
    if (
        !isId(id) ||
        typeof action !== 'string' ||
        typeof actionLabel !== 'string' ||
        user === null ||
        !isUtcTime(at) ||
        note === undefined
    ) {
        return null;
    }
    return { id, action, actionLabel, user, at, note };
}

function parseBooking(json: unknown): Notice['booking'] | null {
    if (!isRecord(json)) {
        return null;
    }
    const user = parseUser(json['user']);
    const location = json['location'] === null ? null : parseLocation(json['location']);
    const status =
        json['status'] === null ? null : parseStatus(json['status'], json['status_label']);
    // Neues Feld (seit Schritt 11 der Admin-Konsole); fehlt es, gilt es als leer.
    const comment = json['comment'] === undefined ? null : nullableString(json['comment']);
    const occurredAt = json['occurred_at'];
    const receivedAt = json['received_at'];
    if (
        user === null ||
        !isUtcTime(occurredAt) ||
        !isUtcTime(receivedAt) ||
        (json['location'] !== null && location === null) ||
        (json['status'] !== null && status === null) ||
        comment === undefined
    ) {
        return null;
    }
    return { user, occurredAt, receivedAt, location, status, comment };
}

function parseOther(json: unknown): Notice['other'] | null {
    if (!isRecord(json)) {
        return null;
    }
    const user = json['user'] === null ? null : parseUser(json['user']);
    const location = parseLocation(json['location']);
    const occurredAt = json['occurred_at'];
    const status = parseStatus(json['status'], json['status_label']);
    if (
        (json['user'] !== null && user === null) ||
        location === null ||
        !isUtcTime(occurredAt) ||
        status === null
    ) {
        return null;
    }
    return { user, occurredAt, location, status };
}

export function parseNotice(json: unknown): Notice | null {
    if (!isRecord(json) || !isRecord(json['device']) || !Array.isArray(json['history'])) {
        return null;
    }
    const { id, kind, status, device } = json;
    const kindLabel = json['kind_label'];
    const statusLabel = json['status_label'];
    const staleConfirmed = json['stale_confirmed'];
    const createdAt = json['created_at'];
    const updatedAt = json['updated_at'];
    const booking = parseBooking(json['booking']);
    const other = parseOther(json['other']);
    const history: NoticeHistoryEntry[] = [];
    for (const entry of json['history']) {
        const parsed = parseHistoryEntry(entry);
        if (parsed === null) {
            return null;
        }
        history.push(parsed);
    }
    if (
        !isId(id) ||
        typeof kind !== 'string' ||
        typeof kindLabel !== 'string' ||
        (status !== 'open' && status !== 'resolved') ||
        typeof statusLabel !== 'string' ||
        typeof staleConfirmed !== 'boolean' ||
        !isUtcTime(createdAt) ||
        !isUtcTime(updatedAt) ||
        !isId(device['id']) ||
        typeof device['identifier'] !== 'string' ||
        typeof device['device_type'] !== 'string' ||
        booking === null ||
        other === null
    ) {
        return null;
    }
    return {
        id,
        kind,
        kindLabel,
        status,
        statusLabel,
        staleConfirmed,
        createdAt,
        updatedAt,
        device: {
            id: device['id'],
            identifier: device['identifier'],
            deviceType: device['device_type'],
        },
        booking,
        other,
        history,
    };
}

export function parseNoticePage(json: unknown): NoticePage | null {
    if (
        !isRecord(json) ||
        !Array.isArray(json['notices']) ||
        !isCount(json['total']) ||
        !isCount(json['open'])
    ) {
        return null;
    }
    const notices: Notice[] = [];
    for (const entry of json['notices']) {
        const notice = parseNotice(entry);
        if (notice === null) {
            return null;
        }
        notices.push(notice);
    }
    return { notices, total: json['total'], open: json['open'] };
}

function parseNoticeResponse(json: unknown): Notice | null {
    return isRecord(json) ? parseNotice(json['notice']) : null;
}

/** GET notices (Admin): neueste zuerst, seitenweise. */
export function fetchNoticePage(
    query: { readonly status: NoticeFilter; readonly limit: number; readonly offset: number },
    fetchFn?: typeof fetch,
): Promise<ApiResult<NoticePage>> {
    const params = new URLSearchParams({
        status: query.status,
        limit: String(query.limit),
        offset: String(query.offset),
    });
    return apiRequest(`notices?${params.toString()}`, parseNoticePage, {
        ...(fetchFn && { fetchFn }),
    });
}

/** GET notices/{id} (Admin): ein Hinweis mit Verlauf. */
export function fetchNotice(id: number, fetchFn?: typeof fetch): Promise<ApiResult<Notice>> {
    return apiRequest(`notices/${String(id)}`, parseNoticeResponse, {
        ...(fetchFn && { fetchFn }),
    });
}

/** POST notices/{id}/resolve (wiederholbar), Notiz optional (mehrzeilig, höchstens 500 Zeichen). */
export function postResolveNotice(
    id: number,
    note: string | null,
    fetchFn?: typeof fetch,
): Promise<ApiResult<Notice>> {
    return apiRequest(`notices/${String(id)}/resolve`, parseNoticeResponse, {
        method: 'POST',
        body: note === null ? {} : { note },
        ...(fetchFn && { fetchFn }),
    });
}

/** POST notices/{id}/reopen (wiederholbar). */
export function postReopenNotice(id: number, fetchFn?: typeof fetch): Promise<ApiResult<Notice>> {
    return apiRequest(`notices/${String(id)}/reopen`, parseNoticeResponse, {
        method: 'POST',
        body: {},
        ...(fetchFn && { fetchFn }),
    });
}
