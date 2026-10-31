import type { MockRequest, MockResponse } from '../fixtures';
import { devicesEndpoint } from './devices';

/** Antwort von GET device-summary für Admins wie DeviceSummaryHandler (mit notices). */
export function summaryJson(
    counts: {
        ready?: number;
        maintenance?: number;
        defective?: number;
        overdue?: number;
        dueSoon?: number;
        openNotices?: number;
    } = {},
): Record<string, unknown> {
    const { ready = 0, maintenance = 0, defective = 0 } = counts;
    return {
        today: '2026-10-30',
        devices: {
            total: ready + maintenance + defective,
            by_status: [
                { status: 'ready', status_label: 'einsatzbereit', count: ready },
                { status: 'maintenance', status_label: 'in Wartung', count: maintenance },
                { status: 'defective', status_label: 'defekt', count: defective },
            ],
        },
        inspection: {
            overdue: counts.overdue ?? 0,
            due_soon: counts.dueSoon ?? 0,
            due_soon_days: 30,
            without_date: 0,
        },
        notices: { open: counts.openNotices ?? 0 },
    };
}

/** Konto wie UserJson::user (Benutzerverwaltung). */
export function userJson(
    id: number,
    overrides: Record<string, unknown> = {},
): Record<string, unknown> {
    return {
        id,
        label: `Person ${String(id)}`,
        email: `person${String(id)}@example.org`,
        name: `Person ${String(id)}`,
        display_name: null,
        role: 'user',
        role_label: 'Benutzer',
        status: 'pending',
        status_label: 'wartet auf Freischaltung',
        locked: false,
        email_verified_at: '2026-10-29T10:00:00Z',
        created_at: '2026-10-29T09:00:00Z',
        ...overrides,
    };
}

/** Eintrag von GET device-events wie DeviceHistoryListHandler (Umbuchung in ein Fach). */
export function tenantEventJson(
    id: number,
    overrides: Record<string, unknown> = {},
): Record<string, unknown> {
    return {
        id,
        type: 'state_changed',
        type_label: 'Umgebucht',
        created_at: '2026-10-30T07:12:00Z',
        occurred_at: '2026-10-30T07:12:00Z',
        received_at: '2026-10-30T07:12:00Z',
        applied: true,
        not_applied_reason: null,
        not_applied_label: null,
        client_ref: null,
        user: { id: 3, name: 'J. Müller' },
        comment: null,
        location: {
            from: { id: 2, name: 'Lager', path: 'Lager' },
            to: { id: 11, name: 'G1', path: 'HLF 20 / G1' },
        },
        status: null,
        inspection_due: null,
        field: null,
        device: { id: 5, identifier: 'M5', device_type: { id: 1, name: 'Atemschutzmaske' } },
        ...overrides,
    };
}

export interface AdminOverviewData {
    readonly summary?: Record<string, unknown>;
    /** Konten mit Status pending (GET users?status=pending). */
    readonly pending?: readonly Record<string, unknown>[];
    /** Antwort auf GET devices mit `status` (Liste „Nicht einsatzbereit“). */
    readonly notReady?: readonly Record<string, unknown>[];
    readonly notReadyTotal?: number;
    readonly events?: readonly Record<string, unknown>[];
    /** Geräteliste ohne `status` (z. B. für „Suchen“). */
    readonly devices?: readonly Record<string, unknown>[];
}

/**
 * Simulierte API für das Dashboard und die Zähler im Menü der Admin-Konsole: GET device-summary,
 * users, devices (mit `status` die nicht einsatzbereiten, sonst `devices`), device-events.
 */
export function adminOverview(
    data: AdminOverviewData = {},
): Record<string, MockResponse | ((request: MockRequest) => MockResponse)> {
    const list = devicesEndpoint(data.devices ?? []);
    const notReady = data.notReady ?? [];
    return {
        'GET api/v1/device-summary': { status: 200, body: data.summary ?? summaryJson() },
        'GET api/v1/users': { status: 200, body: { users: data.pending ?? [] } },
        'GET api/v1/devices': (request) => {
            const params = new URL(request.url).searchParams;
            if (params.get('status') === null) {
                return list(request);
            }
            const limit = Number(params.get('limit') ?? '50');
            return {
                status: 200,
                body: {
                    devices: notReady.slice(0, limit),
                    total: data.notReadyTotal ?? notReady.length,
                    limit,
                    offset: 0,
                },
            };
        },
        'GET api/v1/device-events': {
            status: 200,
            body: {
                events: data.events ?? [],
                total: (data.events ?? []).length,
                limit: 8,
                offset: 0,
            },
        },
    };
}

/** Leeres Dashboard für Tests, die als Admin nur auf der Startseite landen. */
export const ADMIN_OVERVIEW = adminOverview();

/**
 * Hinweis wie NoticeJson::notice (Admin): M1 offline auf HLF 20 / G1 · defekt gebucht und damit
 * den Stand „Lager · einsatzbereit“ des Kommandanten überschrieben.
 */
export function noticeJson(
    id: number,
    overrides: Record<string, unknown> = {},
): Record<string, unknown> {
    return {
        id,
        kind: 'overwritten',
        kind_label: 'Anderen Stand überschrieben',
        status: 'open',
        status_label: 'offen',
        stale_confirmed: false,
        created_at: '2026-10-30T09:00:00Z',
        updated_at: '2026-10-30T09:00:00Z',
        device: { id: 1, identifier: 'M1', device_type: 'Atemschutzmaske' },
        booking: {
            client_ref: '00000000-0000-4000-8000-000000000001',
            event_id: 40,
            user: { id: 3, name: 'Gerätewart', email: 'geraetewart+1@example.org' },
            occurred_at: '2026-10-30T07:15:00Z',
            received_at: '2026-10-30T09:00:00Z',
            location: { id: 11, name: 'G1', path: 'HLF 20 / G1' },
            status: 'defective',
            status_label: 'defekt',
            comment: 'Ventil klemmt',
        },
        other: {
            user: { id: 8, name: 'Kommandant', email: 'kommandant@example.org' },
            occurred_at: '2026-10-30T06:00:00Z',
            location: { id: 30, name: 'Lager', path: 'Lager' },
            status: 'ready',
            status_label: 'einsatzbereit',
        },
        history: [],
        ...overrides,
    };
}
