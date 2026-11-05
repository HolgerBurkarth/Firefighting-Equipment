import { describe, expect, it } from 'vitest';
import type { ApiError, ApiResult } from '../../src/api/client';
import type { BookingResponse, DeviceListItem, StateChangeRequest } from '../../src/api/devices';
import {
    actionTime,
    BookingQueue,
    isStale,
    offlineBookingProblem,
    pendingDevice,
    QUEUE_FORMAT,
    queuedText,
    readStoredQueue,
    unsentWarning,
    type QueuedBooking,
    type StoredQueue,
} from '../../src/data/bookingQueue';
import { MemoryDb } from '../../src/runtime/localDb';

const NOW = new Date('2026-10-31T12:00:00Z');

function device(): DeviceListItem {
    return {
        id: 4,
        identifier: 'M4',
        scanCode: null,
        deviceType: { id: 1, name: 'Atemschutzmaske' },
        location: { id: 2, name: 'Lager', path: 'Lager' },
        status: 'ready',
        statusLabel: 'einsatzbereit',
        nextInspectionDue: null,
        version: 3,
        serialNumber: null,
        manufacturer: null,
        model: null,
        stateChangedAt: '2026-10-30T10:00:00Z',
    } as unknown as DeviceListItem;
}

function booking(overrides: Partial<QueuedBooking> = {}): Omit<QueuedBooking, 'state'> {
    return {
        clientRef: '0b4d2a7c-1f3e-4c5a-9b8d-7e6f5a4b3c2d',
        deviceId: 4,
        identifier: 'M4',
        deviceTypeName: 'Atemschutzmaske',
        version: 3,
        locationId: 12,
        locationPath: 'HLF 20 / G2',
        status: 'defective',
        statusLabel: 'defekt',
        comment: 'Riss im Sichtfenster',
        occurredAt: '2026-10-31T11:00:00Z',
        ...overrides,
    };
}

function error(code: string, status: number | null, message: string | null = null): ApiError {
    return { code, status, message, retryAfterSeconds: null, details: null };
}

function response(outcome: 'applied' | 'history_only' = 'applied'): BookingResponse {
    return {
        device: { ...device(), version: 4 },
        result: {
            outcome,
            outcomeLabel: outcome === 'applied' ? 'übernommen' : 'nur im Verlauf',
            reason: null,
            reasonLabel: null,
            message: outcome === 'applied' ? 'Die Buchung wurde übernommen.' : 'Nur im Verlauf.',
            staleConfirmed: false,
            clientRef: null,
            replayed: false,
            noticeId: null,
        },
    };
}

function queue(answers: ApiResult<BookingResponse>[], db = new MemoryDb()) {
    const requests: { id: number; request: StateChangeRequest }[] = [];
    const sent: BookingResponse[] = [];
    let i = 0;
    const q = new BookingQueue({
        db,
        now: () => NOW,
        post: (id, request) => {
            requests.push({ id, request });
            const answer = answers[Math.min(i, answers.length - 1)];
            i += 1;
            return Promise.resolve(answer ?? { ok: false, error: error('network', null) });
        },
        onSent: (r) => sent.push(r),
    });
    return { q, requests, sent, db };
}

describe('BookingQueue', () => {
    it('sendet mit Aktionszeit und client_ref, merkt sich das Ergebnis', async () => {
        const { q, requests, sent, db } = queue([{ ok: true, status: 200, data: response() }]);
        await q.use('test:7');
        expect(await q.add(booking())).toBe(true);
        expect(((await db.get('queue', 'test:7')) as StoredQueue).bookings).toHaveLength(1);
        expect(q.unsent).toHaveLength(1);
        await q.send();
        expect(requests).toEqual([
            {
                id: 4,
                request: {
                    version: 3,
                    clientRef: '0b4d2a7c-1f3e-4c5a-9b8d-7e6f5a4b3c2d',
                    occurredAt: '2026-10-31T11:00:00Z',
                    locationId: 12,
                    status: 'defective',
                    comment: 'Riss im Sichtfenster',
                },
            },
        ]);
        expect(q.state.bookings[0]?.state).toBe('done');
        expect(q.state.bookings[0]?.result?.message).toBe('Die Buchung wurde übernommen.');
        expect(sent).toHaveLength(1);
        expect(q.unsent).toHaveLength(0);
        const stored = (await db.get('queue', 'test:7')) as StoredQueue;
        expect(stored.format).toBe(QUEUE_FORMAT);
        expect(stored.bookings[0]?.state).toBe('done');
        q.dismissDone();
        expect(q.state.bookings).toEqual([]);
    });

    it('dieselbe client_ref kommt nur einmal in die Warteschlange', async () => {
        const { q, db } = queue([]);
        await q.use('test:7');
        expect(await q.add(booking())).toBe(true);
        expect(await q.add(booking())).toBe(true);
        expect(q.state.bookings).toHaveLength(1);
        expect(((await db.get('queue', 'test:7')) as StoredQueue).bookings).toHaveLength(1);
    });

    it('ohne Verbindung, bei 5xx, 401 und client_outdated wartet die Buchung weiter', async () => {
        for (const failure of [
            error('network', null),
            error('database_unavailable', 503),
            error('not_authenticated', 401),
            error(
                'client_outdated',
                409,
                'Diese Version der App ist veraltet – bitte aktualisieren.',
            ),
        ]) {
            const { q, requests } = queue([{ ok: false, error: failure }]);
            await q.use('test:7');
            await q.add(booking());
            await q.add(booking({ clientRef: 'a'.repeat(8) + '-1111-4111-8111-111111111111' }));
            await q.send();
            expect(requests).toHaveLength(1);
            expect(q.state.bookings.map((b) => b.state)).toEqual(['waiting', 'waiting']);
        }
    });

    it('alte Buchung: erst Rückfrage, dann mit confirmed_stale', async () => {
        const { q, requests } = queue([{ ok: true, status: 200, data: response('history_only') }]);
        q.setLimits({ maxAgeDays: 7, queueMaxAgeDays: 2 });
        await q.use('test:7');
        await q.add(booking({ occurredAt: '2026-10-29T11:59:59Z' }));
        await q.send();
        expect(requests).toHaveLength(0);
        expect(q.state.bookings[0]?.state).toBe('stale');
        expect(q.state.bookings[0]?.problem).toBe('Diese Buchung wartet seit mehr als 2 Tagen.');
        await q.confirmStale('0b4d2a7c-1f3e-4c5a-9b8d-7e6f5a4b3c2d');
        expect(requests[0]?.request.confirmedStale).toBe(true);
        expect(q.state.bookings[0]?.state).toBe('done');
        expect(q.state.bookings[0]?.result?.outcome).toBe('history_only');
    });

    it('stale_action des Servers ebenso; clock_implausible ist ein Fehler zum Verwerfen', async () => {
        const stale = queue([{ ok: false, error: error('stale_action', 409, 'Zu alt.') }]);
        await stale.q.use('test:7');
        await stale.q.add(booking());
        await stale.q.send();
        expect(stale.q.state.bookings[0]?.state).toBe('stale');

        const clock = queue([{ ok: false, error: error('clock_implausible', 422) }]);
        await clock.q.use('test:7');
        await clock.q.add(booking());
        await clock.q.send();
        expect(clock.q.state.bookings[0]?.state).toBe('failed');
        expect(clock.q.state.bookings[0]?.problem).toContain('Die Uhr dieses Geräts geht vor');
        clock.q.discard('0b4d2a7c-1f3e-4c5a-9b8d-7e6f5a4b3c2d');
        expect(clock.q.state.bookings).toEqual([]);
    });

    it('Gerät inzwischen außerhalb des Datenfilters (404, auch für dieselbe client_ref): failed, nicht waiting', async () => {
        // Erster Versuch ohne Antwort (kam vielleicht an), die Wiederholung derselben client_ref
        // findet das Gerät nicht mehr: Der Server antwortet 404 statt des gespeicherten Ergebnisses.
        const { q, requests } = queue([
            { ok: false, error: error('network', null) },
            { ok: false, error: error('not_found', 404, 'Das Gerät wurde nicht gefunden.') },
        ]);
        await q.use('test:7');
        await q.add(booking());
        await q.send();
        expect(q.state.bookings[0]?.state).toBe('waiting');

        await q.send();
        expect(requests.map((r) => r.request.clientRef)).toEqual([
            '0b4d2a7c-1f3e-4c5a-9b8d-7e6f5a4b3c2d',
            '0b4d2a7c-1f3e-4c5a-9b8d-7e6f5a4b3c2d',
        ]);
        expect(q.state.bookings[0]?.state).toBe('failed');
        expect(q.state.bookings[0]?.problem).toBeTruthy();

        await q.send();
        expect(requests).toHaveLength(2);
        q.discard('0b4d2a7c-1f3e-4c5a-9b8d-7e6f5a4b3c2d');
        expect(q.state.bookings).toEqual([]);
    });

    it('nach client_outdated sendet die neue Version dieselbe Buchung', async () => {
        const db = new MemoryDb();
        const old = queue([{ ok: false, error: error('client_outdated', 409) }], db);
        await old.q.use('test:7');
        await old.q.add(booking());
        await old.q.send();
        expect(old.q.state.bookings[0]?.state).toBe('waiting');

        // Nach dem Update: neue App, dieselbe Datenbank.
        const fresh = queue([{ ok: true, status: 200, data: response() }], db);
        await fresh.q.use('test:7');
        await fresh.q.send();
        expect(fresh.requests[0]?.request.clientRef).toBe('0b4d2a7c-1f3e-4c5a-9b8d-7e6f5a4b3c2d');
        expect(fresh.q.state.bookings[0]?.state).toBe('done');
    });

    it('liest die Liste ohne Formatversion (vor dem Format) und speichert dann mit Format', async () => {
        const db = new MemoryDb();
        await db.put('queue', 'test:7', [{ ...booking(), state: 'waiting' }]);
        const { q } = queue([{ ok: true, status: 200, data: response() }], db);
        await q.use('test:7');
        expect(q.state.bookings).toHaveLength(1);
        await q.send();
        expect(await db.get('queue', 'test:7')).toEqual({
            format: QUEUE_FORMAT,
            bookings: [expect.objectContaining({ state: 'done' })],
        });
    });

    it('Format einer neueren Version: nichts anzeigen, nichts senden, nichts überschreiben', async () => {
        const db = new MemoryDb();
        const newer = { format: QUEUE_FORMAT + 1, bookings: [{ ...booking(), state: 'waiting' }] };
        await db.put('queue', 'test:7', newer);
        const { q, requests } = queue([{ ok: true, status: 200, data: response() }], db);
        await q.use('test:7');
        expect(q.state.bookings).toEqual([]);
        expect(
            await q.add(booking({ clientRef: 'b'.repeat(8) + '-1111-4111-8111-111111111111' })),
        ).toBe(false);
        await q.send();
        expect(requests).toEqual([]);
        expect(await db.get('queue', 'test:7')).toEqual(newer);
    });

    it('je Konto getrennt; eine unterbrochene Sendung wartet wieder; Löschen beim Abmelden', async () => {
        const db = new MemoryDb();
        await db.put('queue', 'test:7', {
            format: QUEUE_FORMAT,
            bookings: [{ ...booking(), state: 'sending' }],
        });
        const { q } = queue([], db);
        await q.use('test:8');
        expect(q.state.bookings).toEqual([]);
        await q.use('test:7');
        expect(q.state.bookings[0]?.state).toBe('waiting');
        await q.remove('test:7');
        expect(q.state.key).toBeNull();
        expect(await db.get('queue', 'test:7')).toBeNull();
        expect(await q.add(booking())).toBe(false);
    });
});

describe('Regeln', () => {
    it('Aktionszeit in Sekunden, UTC', () => {
        expect(actionTime(new Date('2026-10-31T11:22:33.456Z'))).toBe('2026-10-31T11:22:33Z');
    });

    it('alt erst nach der Grenze (genau auf der Grenze noch nicht)', () => {
        const limits = { maxAgeDays: 7, queueMaxAgeDays: 1 };
        const entry = {
            ...booking({ occurredAt: '2026-10-30T12:00:00Z' }),
            state: 'waiting' as const,
        };
        expect(isStale(entry, limits, NOW)).toBe(false);
        expect(isStale(entry, limits, new Date('2026-10-31T12:00:01Z'))).toBe(true);
    });

    it('offline buchen nur mit frischer Kopie', () => {
        const limits = { maxAgeDays: 7, queueMaxAgeDays: 7 };
        expect(offlineBookingProblem('2026-10-24T12:00:00Z', limits, NOW)).toBeNull();
        expect(offlineBookingProblem('2026-10-24T11:59:59Z', limits, NOW)).toBe(
            'Daten zu alt, bitte mit dem Internet verbinden.',
        );
        expect(offlineBookingProblem(null, limits, NOW)).toContain('einmal geladen');
    });

    it('Gerät mit gewünschtem Stand, Text und Warnung', () => {
        const entry = { ...booking(), state: 'waiting' as const };
        const pending = pendingDevice(device(), entry);
        expect(pending.location).toEqual({ id: 12, name: 'G2', path: 'HLF 20 / G2' });
        expect(pending.status).toBe('defective');
        expect(pending.statusLabel).toBe('defekt');
        expect(pending.version).toBe(3);
        expect(pending.stateChangedAt).toBe('2026-10-31T11:00:00Z');
        expect(queuedText(entry)).toBe('M4 → HLF 20 / G2 · defekt');
        const statusOnly: QueuedBooking = {
            clientRef: entry.clientRef,
            deviceId: 4,
            identifier: 'M4',
            deviceTypeName: 'Atemschutzmaske',
            version: 3,
            status: 'defective',
            statusLabel: 'defekt',
            occurredAt: entry.occurredAt,
            state: 'waiting',
        };
        expect(queuedText(statusOnly)).toBe('M4 → defekt');
        expect(pendingDevice(device(), statusOnly).location.path).toBe('Lager');
        expect(unsentWarning(0)).toBeNull();
        expect(unsentWarning(1)).toBe(
            '1 Buchung wurde noch nicht gesendet und geht beim Abmelden verloren.',
        );
        expect(unsentWarning(3)).toBe(
            '3 Buchungen wurden noch nicht gesendet und gehen beim Abmelden verloren.',
        );
    });
});

describe('readStoredQueue', () => {
    const entry = { ...booking(), state: 'waiting' } as QueuedBooking;

    it.each([
        ['Liste ohne Format', [entry], [entry], true],
        ['aktuelles Format', { format: QUEUE_FORMAT, bookings: [entry] }, [entry], true],
        ['neueres Format', { format: QUEUE_FORMAT + 1, bookings: [entry] }, [], false],
        ['unbekannte Form', { format: 'x' }, [], true],
        ['aktuelles Format ohne Liste', { format: QUEUE_FORMAT }, [], true],
        ['nichts gespeichert', null, [], true],
    ])('%s', (_name, stored, bookings, writable) => {
        expect(readStoredQueue(stored)).toEqual({ bookings, writable });
    });
});
