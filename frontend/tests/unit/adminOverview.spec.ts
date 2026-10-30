import { describe, expect, it } from 'vitest';
import type { ApiError, ApiResult } from '../../src/api/client';
import { parseTenantEventPage, type TenantEventEntry } from '../../src/api/deviceEvents';
import { fetchDevicePage, type DevicePage } from '../../src/api/devices';
import { parseDeviceSummaryCounts, type DeviceSummaryCounts } from '../../src/api/deviceSummary';
import { fetchUsers, parseUserList } from '../../src/api/users';
import {
    ACTIVITY_LIMIT,
    AdminOverviewStore,
    NOT_READY_LIMIT,
    type AdminOverviewLoaders,
} from '../../src/data/adminOverview';
import {
    activityDetail,
    inspectionTile,
    notReadyTile,
    openItemsTile,
    readinessTile,
} from '../../src/data/dashboard';
import { device } from './support/devices';
import { respond } from './support/fetch';

/** Antwort von GET device-summary wie DeviceSummaryHandler (Admin: mit notices). */
function summaryJson(overrides: Record<string, unknown> = {}): Record<string, unknown> {
    return {
        today: '2026-10-30',
        devices: {
            total: 45,
            by_status: [
                { status: 'ready', status_label: 'einsatzbereit', count: 42 },
                { status: 'maintenance', status_label: 'in Wartung', count: 1 },
                { status: 'defective', status_label: 'defekt', count: 2 },
            ],
        },
        inspection: { overdue: 2, due_soon: 4, due_soon_days: 30, without_date: 3 },
        notices: { open: 1 },
        ...overrides,
    };
}

function summary(overrides: Record<string, unknown> = {}): DeviceSummaryCounts {
    const parsed = parseDeviceSummaryCounts(summaryJson(overrides));
    if (parsed === null) {
        throw new Error('Ungültige Zusammenfassung');
    }
    return parsed;
}

/** Eintrag von GET device-events wie DeviceHistoryListHandler. */
function eventJson(id: number, overrides: Record<string, unknown> = {}): Record<string, unknown> {
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
        device: { id: 5, identifier: 'M1', device_type: { id: 1, name: 'Atemschutzmaske' } },
        ...overrides,
    };
}

function events(...entries: Record<string, unknown>[]): TenantEventEntry[] {
    const page = parseTenantEventPage({ events: entries, total: entries.length });
    if (page === null) {
        throw new Error('Ungültiger Verlauf');
    }
    return [...page.events];
}

function userJson(id: number, overrides: Record<string, unknown> = {}): Record<string, unknown> {
    return {
        id,
        label: 'Max',
        email: 'max@example.org',
        name: 'Max Mustermann',
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

const NETWORK: ApiError = {
    code: 'network',
    message: null,
    status: null,
    retryAfterSeconds: null,
    details: null,
};

describe('GET device-summary', () => {
    it('liest Zahlen und offene Hinweise (nur Admins)', () => {
        expect(summary()).toEqual({
            today: '2026-10-30',
            total: 45,
            byStatus: { ready: 42, maintenance: 1, defective: 2 },
            overdue: 2,
            dueSoon: 4,
            dueSoonDays: 30,
            withoutDate: 3,
            openNotices: 1,
        });
        const forUser = summaryJson();
        delete forUser['notices'];
        expect(parseDeviceSummaryCounts(forUser)?.openNotices).toBeNull();
    });

    it.each([
        ['ohne Status', { devices: { total: 1, by_status: [] } }],
        [
            'unbekannter Status',
            { devices: { total: 1, by_status: [{ status: 'retired', count: 1 }] } },
        ],
        [
            'negative Zahl',
            { inspection: { overdue: -1, due_soon: 0, due_soon_days: 30, without_date: 0 } },
        ],
        ['notices ohne Zahl', { notices: { open: 'x' } }],
        ['notices kein Objekt', { notices: 3 }],
    ])('lehnt ab: %s', (_, overrides) => {
        expect(parseDeviceSummaryCounts(summaryJson(overrides))).toBeNull();
    });
});

describe('GET users', () => {
    it('liest Konten und sendet den Filter', async () => {
        const { fetch, requests } = respond(200, {
            users: [userJson(4), userJson(9, { locked: true })],
        });
        const result = await fetchUsers('pending', fetch);
        expect(result.ok && result.data.map((u) => [u.id, u.status, u.locked])).toEqual([
            [4, 'pending', false],
            [9, 'pending', true],
        ]);
        expect(requests[0]?.url).toMatch(/\/api\/v1\/users\?status=pending$/);
    });

    it('lehnt unbekannte Rolle oder fehlende Felder ab', () => {
        expect(parseUserList({ users: [userJson(1, { role: 'chef' })] })).toBeNull();
        expect(parseUserList({ users: [userJson(1, { created_at: null })] })).toBeNull();
        expect(parseUserList({})).toBeNull();
    });
});

describe('GET device-events', () => {
    it('liest Einträge mit Gerät', () => {
        const [entry] = events(eventJson(1));
        expect(entry?.device).toEqual({
            id: 5,
            identifier: 'M1',
            deviceType: { id: 1, name: 'Atemschutzmaske' },
        });
        expect(entry?.typeLabel).toBe('Umgebucht');
    });

    it('lehnt Einträge ohne Gerät ab', () => {
        expect(
            parseTenantEventPage({ events: [eventJson(1, { device: null })], total: 1 }),
        ).toBeNull();
        expect(
            parseTenantEventPage({
                events: [eventJson(1, { device: { id: 5, identifier: 'M1', device_type: null } })],
                total: 1,
            }),
        ).toBeNull();
    });
});

describe('GET devices mit Status und Sortierung', () => {
    it('sendet status, sort und dir', async () => {
        const { fetch, requests } = respond(200, { devices: [], total: 0, limit: 10, offset: 0 });
        await fetchDevicePage(
            {
                limit: 10,
                offset: 0,
                status: ['maintenance', 'defective'],
                sort: 'status',
                dir: 'desc',
            },
            fetch,
        );
        const url = new URL(requests[0]?.url ?? '', 'http://localhost');
        expect(url.searchParams.get('status')).toBe('maintenance,defective');
        expect(url.searchParams.get('sort')).toBe('status');
        expect(url.searchParams.get('dir')).toBe('desc');
    });
});

function loaders(fail: { value: boolean }, calls: string[]): AdminOverviewLoaders {
    const ok = <T>(name: string, data: T): Promise<ApiResult<T>> => {
        calls.push(name);
        return Promise.resolve(
            fail.value && name === 'activity'
                ? { ok: false, error: NETWORK }
                : { ok: true, status: 200, data },
        );
    };
    const notReady: DevicePage = {
        devices: [device(2, { status: 'defective', status_label: 'defekt' })],
        total: 3,
        limit: NOT_READY_LIMIT,
        offset: 0,
    };
    return {
        summary: () => ok('summary', summary()),
        pendingUsers: () => ok('pendingUsers', parseUserList({ users: [userJson(4)] }) ?? []),
        notReady: () => ok('notReady', notReady),
        activity: () => ok('activity', { events: events(eventJson(1), eventJson(2)) }),
    };
}

describe('AdminOverviewStore', () => {
    it('lädt alle vier Quellen und behält bei einem Fehler den bisherigen Stand', async () => {
        const fail = { value: false };
        const calls: string[] = [];
        const store = new AdminOverviewStore(loaders(fail, calls), () => 1000);
        await store.refresh('open');
        expect(calls).toEqual(['summary', 'pendingUsers', 'notReady', 'activity']);
        expect(store.state.error).toBeNull();
        expect(store.state.loadedAt).toBe(1000);
        expect(store.state.overview?.pendingUsers).toBe(1);
        expect(store.state.overview?.notReady.total).toBe(3);
        expect(store.state.overview?.activity.map((e) => e.id)).toEqual([1, 2]);

        fail.value = true;
        await store.refresh('changed');
        expect(store.state.error?.code).toBe('network');
        expect(store.state.overview?.activity).toHaveLength(2);
        expect(store.state.loading).toBe(false);
    });

    it('clear vergisst alles', async () => {
        const store = new AdminOverviewStore(loaders({ value: false }, []));
        await store.refresh('open');
        store.clear();
        expect(store.state).toEqual({
            overview: null,
            loading: false,
            error: null,
            loadedAt: null,
        });
    });

    it('Grenzen der Listen', () => {
        expect([NOT_READY_LIMIT, ACTIVITY_LIMIT]).toEqual([10, 8]);
    });
});

describe('Kacheln des Dashboards', () => {
    it('Einsatzbereitschaft mit Anteil', () => {
        expect(readinessTile(summary())).toEqual({
            value: '42 von 45',
            detail: 'Geräte einsatzbereit',
            percent: 93,
        });
        const empty = summary({
            devices: {
                total: 0,
                by_status: [
                    { status: 'ready', count: 0 },
                    { status: 'maintenance', count: 0 },
                    { status: 'defective', count: 0 },
                ],
            },
        });
        expect(readinessTile(empty)).toEqual({
            value: '0 von 0',
            detail: 'Noch keine Geräte erfasst',
            percent: 0,
        });
        expect(notReadyTile(empty)).toEqual({ value: '0', detail: 'Alle Geräte einsatzbereit' });
    });

    it('nicht einsatzbereit ohne Teile mit 0', () => {
        expect(notReadyTile(summary())).toEqual({ value: '3', detail: '2 defekt · 1 in Wartung' });
        const onlyDefective = summary({
            devices: {
                total: 5,
                by_status: [
                    { status: 'ready', count: 4 },
                    { status: 'maintenance', count: 0 },
                    { status: 'defective', count: 1 },
                ],
            },
        });
        expect(notReadyTile(onlyDefective).detail).toBe('1 defekt');
    });

    it('Prüffristen und offene Hinweise (Einzahl und Mehrzahl)', () => {
        expect(inspectionTile(summary())).toEqual({
            value: '2 überfällig',
            detail: '4 in den nächsten 30 Tagen',
        });
        expect(openItemsTile(1, 2)).toEqual({
            value: '3',
            detail: '1 Hinweis · 2 Freischaltungen',
        });
        expect(openItemsTile(3, 1)).toEqual({ value: '4', detail: '3 Hinweise · 1 Freischaltung' });
        expect(openItemsTile(0, 0)).toEqual({ value: '0', detail: 'Nichts zu erledigen' });
    });

    it('Aktivität in einer Zeile', () => {
        const [moved, status] = events(
            eventJson(1),
            eventJson(2, {
                type_label: 'Umgebucht und Status geändert',
                status: {
                    from: 'ready',
                    from_label: 'einsatzbereit',
                    to: 'defective',
                    to_label: 'defekt',
                },
            }),
        );
        if (moved === undefined || status === undefined) {
            throw new Error('Einträge fehlen');
        }
        expect(activityDetail(moved)).toBe('Standort: Lager → HLF 20 / G1');
        expect(activityDetail(status)).toBe(
            'Standort: Lager → HLF 20 / G1 · Status: einsatzbereit → defekt',
        );
    });
});
