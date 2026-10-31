import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import type { ApiResult } from '../../src/api/client';
import { downloadHistoryExport, historyExportSearchParams } from '../../src/api/deviceEvents';
import {
    fetchNotice,
    fetchNoticePage,
    parseNotice,
    postReopenNotice,
    postResolveNotice,
    type Notice,
    type NoticePage,
} from '../../src/api/notices';
import { MAX_COMMENT_LENGTH } from '../../src/data/booking';
import { NOTICE_PAGE_SIZE, NoticeList } from '../../src/data/noticeList';
import {
    addDays,
    defaultHistoryExportForm,
    HISTORY_EXPORT_DEFAULT_DAYS,
    HISTORY_EXPORT_MAX_DAYS,
    historyExportParams,
    historyExportProblems,
    inquiryMailLink,
    noticeCountText,
    noticeExplanation,
    noticeFilterFromQuery,
    noticeFilterQuery,
    noticeNote,
    noticeNoteProblem,
    otherStateHeading,
    presetRange,
    stateText,
} from '../../src/data/notices';
import { respond } from './support/fetch';

/** Hinweis wie NoticeJson::notice (Art overwritten). */
function noticeJson(id: number, overrides: Record<string, unknown> = {}): Record<string, unknown> {
    return {
        id,
        kind: 'overwritten',
        kind_label: 'Anderen Stand überschrieben',
        status: 'open',
        status_label: 'offen',
        stale_confirmed: false,
        created_at: '2026-10-30T09:00:00Z',
        updated_at: '2026-10-30T09:00:00Z',
        device: { id: 5, identifier: 'M1', device_type: 'Atemschutzmaske' },
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
            user: { id: 7, name: 'Kommandant', email: 'admin@example.org' },
            occurred_at: '2026-10-30T06:00:00Z',
            location: { id: 2, name: 'Lager', path: 'Lager' },
            status: 'ready',
            status_label: 'einsatzbereit',
        },
        history: [],
        ...overrides,
    };
}

function notice(id: number, overrides: Record<string, unknown> = {}): Notice {
    const parsed = parseNotice(noticeJson(id, overrides));
    if (parsed === null) {
        throw new Error('Testhinweis ungültig');
    }
    return parsed;
}

describe('API der Hinweise', () => {
    it('liest einen Hinweis mit Buchung, anderem Stand und Verlauf', () => {
        const parsed = parseNotice(
            noticeJson(12, {
                status: 'resolved',
                status_label: 'erledigt',
                history: [
                    {
                        id: 1,
                        action: 'resolve',
                        action_label: 'Erledigt',
                        user: { id: 7, name: 'Kommandant', email: 'admin@example.org' },
                        at: '2026-10-30T10:00:00Z',
                        note: 'Geklärt\nMaske getauscht',
                    },
                ],
            }),
        );
        expect(parsed).toMatchObject({
            id: 12,
            kind: 'overwritten',
            status: 'resolved',
            device: { id: 5, identifier: 'M1', deviceType: 'Atemschutzmaske' },
            booking: {
                user: { id: 3, name: 'Gerätewart', email: 'geraetewart+1@example.org' },
                location: { path: 'HLF 20 / G1' },
                status: { value: 'defective', label: 'defekt' },
                comment: 'Ventil klemmt',
            },
            other: { status: { value: 'ready', label: 'einsatzbereit' } },
            history: [{ action: 'resolve', note: 'Geklärt\nMaske getauscht' }],
        });
    });

    it('Buchung nur mit Status, anonymisiertes Konto ohne E-Mail, Kommentar fehlt (älterer Server)', () => {
        const json = noticeJson(1);
        const booking: Record<string, unknown> = {
            ...(json['booking'] as Record<string, unknown>),
            location: null,
            user: { id: 3, name: 'Gelöschter Benutzer #3', email: null },
        };
        delete booking['comment'];
        const parsed = parseNotice({
            ...json,
            booking,
            other: { ...(json['other'] as object), user: null },
        });
        expect(parsed?.booking.location).toBeNull();
        expect(parsed?.booking.comment).toBeNull();
        expect(parsed?.booking.user.email).toBeNull();
        expect(parsed?.other.user).toBeNull();
    });

    it.each([
        ['unbekannter Status', { status: 'done' }],
        ['Gerät ohne Typ', { device: { id: 5, identifier: 'M1' } }],
        ['Zeit ohne Z', { created_at: '2026-10-30 09:00:00' }],
        ['Verlauf kein Array', { history: null }],
        [
            'anderer Stand ohne Standort',
            {
                other: {
                    user: null,
                    occurred_at: '2026-10-30T06:00:00Z',
                    location: null,
                    status: 'ready',
                    status_label: 'einsatzbereit',
                },
            },
        ],
    ])('lehnt ab: %s', (_, overrides) => {
        expect(parseNotice(noticeJson(1, overrides))).toBeNull();
    });

    it('GET notices mit Filter und Seite, GET notices/{id}', async () => {
        const list = respond(200, {
            notices: [noticeJson(2)],
            total: 1,
            open: 1,
            limit: 50,
            offset: 0,
        });
        const page = await fetchNoticePage(
            { status: 'resolved', limit: 50, offset: 100 },
            list.fetch,
        );
        expect(page.ok && page.data.notices[0]?.id).toBe(2);
        expect(list.requests[0]?.url).toContain(
            '/test/api/v1/notices?status=resolved&limit=50&offset=100',
        );

        const one = respond(200, { notice: noticeJson(3) });
        const result = await fetchNotice(3, one.fetch);
        expect(result.ok && result.data.id).toBe(3);
        expect(one.requests[0]?.url).toMatch(/\/test\/api\/v1\/notices\/3$/);
    });

    it('POST resolve mit und ohne Notiz, reopen mit leerem Objekt', async () => {
        const answer = { notice: noticeJson(3, { status: 'resolved', status_label: 'erledigt' }) };
        const withNote = respond(200, answer);
        await postResolveNotice(3, 'Geklärt', withNote.fetch);
        expect(withNote.requests[0]).toMatchObject({
            url: expect.stringMatching(/\/api\/v1\/notices\/3\/resolve$/) as unknown,
            method: 'POST',
            body: { note: 'Geklärt' },
        });
        const without = respond(200, answer);
        await postResolveNotice(3, null, without.fetch);
        expect(without.requests[0]?.body).toEqual({});
        const reopen = respond(200, { notice: noticeJson(3) });
        const result = await postReopenNotice(3, reopen.fetch);
        expect(result.ok).toBe(true);
        expect(reopen.requests[0]).toMatchObject({
            url: expect.stringMatching(/\/api\/v1\/notices\/3\/reopen$/) as unknown,
            body: {},
        });
    });
});

const FAILED: ApiResult<NoticePage> = {
    ok: false,
    error: { code: 'network', message: null, status: null, retryAfterSeconds: null, details: null },
};

function pages(total: number, open = total) {
    const calls: { status: string; limit: number; offset: number }[] = [];
    const fetchPage = (query: {
        status: 'open' | 'resolved' | 'all';
        limit: number;
        offset: number;
    }) => {
        calls.push(query);
        const ids = Array.from({ length: total }, (_, i) => total - i);
        const notices = ids.slice(query.offset, query.offset + query.limit).map((id) => notice(id));
        return Promise.resolve<ApiResult<NoticePage>>({
            ok: true,
            status: 200,
            data: { notices, total, open },
        });
    };
    return { calls, fetchPage };
}

describe('NoticeList', () => {
    it('lädt die erste Seite des Filters, dann weitere; Auffrischen so viele wie geladen', async () => {
        const source = pages(120, 7);
        const list = new NoticeList(source.fetchPage);
        await list.show('all');
        expect(list.state.notices).toHaveLength(NOTICE_PAGE_SIZE);
        expect([list.state.total, list.state.open]).toEqual([120, 7]);
        await list.more();
        expect(list.state.notices).toHaveLength(100);
        expect(list.find(120)?.id).toBe(120);
        expect(list.find(1)).toBeNull();
        await list.refresh('changed');
        expect(source.calls.map((call) => [call.status, call.limit, call.offset])).toEqual([
            ['all', 50, 0],
            ['all', 50, 50],
            ['all', 100, 0],
        ]);
    });

    it('neuer Filter leert die Liste, gleicher frischt nur auf; Fehler behält die Liste', async () => {
        const source = pages(3);
        let fail = false;
        const list = new NoticeList((query) =>
            fail ? Promise.resolve(FAILED) : source.fetchPage(query),
        );
        await list.show('open');
        expect(list.state.notices).toHaveLength(3);
        fail = true;
        await list.refresh('changed');
        expect(list.state.notices).toHaveLength(3);
        expect(list.state.error?.code).toBe('network');
        fail = false;
        const shown = list.show('resolved');
        expect([list.state.filter, list.state.notices.length, list.state.total]).toEqual([
            'resolved',
            0,
            null,
        ]);
        await shown;
        expect(list.state.error).toBeNull();
        list.clear();
        expect(list.state.filter).toBeNull();
        await list.refresh('visible');
        expect(source.calls.at(-1)?.status).toBe('resolved');
    });
});

describe('Texte und Filter des Protokolls', () => {
    it('Filter in der Adresse', () => {
        expect(noticeFilterFromQuery(undefined)).toBe('open');
        expect(noticeFilterFromQuery('erledigt')).toBe('resolved');
        expect(noticeFilterFromQuery('alle')).toBe('all');
        expect(noticeFilterFromQuery('x')).toBe('open');
        expect(noticeFilterQuery('open')).toEqual({});
        expect(noticeFilterQuery('resolved')).toEqual({ status: 'erledigt' });
        expect(noticeFilterQuery('all')).toEqual({ status: 'alle' });
    });

    it('Anzahl', () => {
        expect(noticeCountText(1, 'open')).toBe('1 offener Hinweis');
        expect(noticeCountText(3, 'open')).toBe('3 offene Hinweise');
        expect(noticeCountText(1, 'resolved')).toBe('1 erledigter Hinweis');
        expect(noticeCountText(12, 'all')).toBe('12 Hinweise');
    });

    it('Erklärung je Art, „nach Rückfrage“ zusätzlich, Überschrift des anderen Stands', () => {
        expect(noticeExplanation(notice(1))).toContain('hat diesen Stand überschrieben');
        expect(noticeExplanation(notice(1, { stale_confirmed: true }))).toContain(
            'Außerdem war die Buchung älter als erlaubt',
        );
        const stale = notice(1, { kind: 'stale_confirmed', stale_confirmed: true });
        expect(noticeExplanation(stale)).not.toContain('Außerdem');
        expect(noticeExplanation(notice(1, { kind: 'unbekannt' }))).toBeNull();
        expect(otherStateHeading(notice(1))).toBe('Überschriebener Stand');
        expect(otherStateHeading(notice(1, { kind: 'history_only' }))).toBe('Stand beim Eingang');
    });

    it('Stand als eine Zeile', () => {
        const n = notice(1);
        expect(stateText(n.booking.location, n.booking.status)).toBe('HLF 20 / G1 · defekt');
        expect(stateText(null, n.booking.status)).toBe('defekt');
        expect(stateText(n.booking.location, null)).toBe('HLF 20 / G1');
    });

    it('Rückfrage per Mail mit Betreff, Zeit (Ortszeit) und Stand; ohne E-Mail keine', () => {
        const n = notice(1);
        const link = inquiryMailLink(n, 'booking');
        expect(link?.startsWith('mailto:geraetewart+1@example.org?subject=')).toBe(true);
        const params = new URLSearchParams((link ?? '').split('?')[1]);
        expect(params.get('subject')).toBe('Rückfrage zu Ihrer Buchung von Atemschutzmaske M1');
        const occurred = new Date('2026-10-30T07:15:00Z');
        const time = `${String(occurred.getDate()).padStart(2, '0')}.${String(occurred.getMonth() + 1).padStart(2, '0')}.${String(occurred.getFullYear())}, ${String(occurred.getHours()).padStart(2, '0')}:15`;
        expect(params.get('body')).toBe(
            `Guten Tag Gerätewart,\r\n\r\nzu Ihrer Buchung von Atemschutzmaske M1 am ${time} (HLF 20 / G1 · defekt) habe ich eine Rückfrage:\r\n\r\n`,
        );
        expect(inquiryMailLink(n, 'other')).toContain('mailto:admin@example.org?');
        const anonymized = notice(1, {
            other: {
                user: null,
                occurred_at: '2026-10-30T06:00:00Z',
                location: { id: 2, name: 'Lager', path: 'Lager' },
                status: 'ready',
                status_label: 'einsatzbereit',
            },
        });
        expect(inquiryMailLink(anonymized, 'other')).toBeNull();
    });

    it('Notiz: Zeilenumbrüche bleiben, Tabulatoren werden Leerzeichen, höchstens 500 Zeichen', () => {
        expect(noticeNote('  \r\n ')).toBeNull();
        expect(noticeNote(' Geklärt\r\nTab\there \r')).toBe('Geklärt\nTab here');
        expect(MAX_COMMENT_LENGTH).toBe(500);
        expect(noticeNoteProblem('ä'.repeat(500))).toBeNull();
        expect(noticeNoteProblem('😀'.repeat(501))).toBe('Höchstens 500 Zeichen (jetzt 501).');
    });

    it('Notiz gleich DeviceText::noticeNote (500 Zeichen wie der Kommentar)', () => {
        const php = readFileSync(resolve(process.cwd(), '../src/Device/DeviceText.php'), 'utf8');
        expect(/COMMENT_MAX_LENGTH = (\d+);/.exec(php)?.[1]).toBe(String(MAX_COMMENT_LENGTH));
        expect(php).toMatch(/noticeNote[\s\S]*?COMMENT_MAX_LENGTH, true\)/);
    });
});

describe('Export des Verlaufs', () => {
    it('Grenzen gleich DeviceHistoryExportHandler', () => {
        const php = readFileSync(
            resolve(process.cwd(), '../src/Api/V1/Devices/DeviceHistoryExportHandler.php'),
            'utf8',
        );
        expect(/const int DEFAULT_DAYS = (\d+);/.exec(php)?.[1]).toBe(
            String(HISTORY_EXPORT_DEFAULT_DAYS),
        );
        expect(/const int MAX_DAYS = (\d+);/.exec(php)?.[1]).toBe(String(HISTORY_EXPORT_MAX_DAYS));
    });

    it('Standard wie der Server: die letzten 90 Tage bis heute', () => {
        expect(defaultHistoryExportForm('2026-10-31')).toEqual({
            from: '2026-08-03',
            to: '2026-10-31',
            deviceTypeId: null,
            locationId: null,
            order: 'newest',
        });
        expect(presetRange('2024-03-01', 30)).toEqual({ from: '2024-02-01', to: '2024-03-01' });
        expect(addDays('2026-03-28', 2)).toBe('2026-03-30');
    });

    it('prüft Datumsfelder und Zeitraum', () => {
        const form = defaultHistoryExportForm('2026-10-31');
        expect(historyExportProblems(form)).toEqual({});
        expect(historyExportProblems({ ...form, from: '' })).toEqual({
            from: 'Bitte ein Datum eingeben.',
        });
        expect(historyExportProblems({ ...form, to: '31.02.2026' }).to).toBe(
            'Bitte ein gültiges Datum eingeben (z. B. 01.12.2026).',
        );
        expect(historyExportProblems({ ...form, from: '2026-11-01' }).range).toBe(
            'Das Enddatum liegt vor dem Startdatum.',
        );
        expect(historyExportProblems({ ...form, from: '2025-10-30', to: '2026-10-31' }).range).toBe(
            'Höchstens 366 Tage auf einmal (jetzt 367).',
        );
    });

    it('366 Tage gehen, 367 nicht; deutsche Schreibweise wird umgewandelt', () => {
        const form = defaultHistoryExportForm('2026-10-31');
        expect(historyExportParams({ ...form, from: '2025-10-30', to: '2026-10-31' })).toBeNull();
        expect(historyExportParams({ ...form, from: '2025-10-31', to: '2026-10-31' })?.from).toBe(
            '2025-10-31',
        );
        expect(historyExportParams({ ...form, from: '1.11.2025', to: '31.10.2026' })).toEqual({
            from: '2025-11-01',
            to: '2026-10-31',
            deviceTypeId: null,
            locationId: null,
            order: 'newest',
        });
    });

    it('Parameter: Standort immer mit Fächern, Reihenfolge nur, wenn nicht Standard', async () => {
        const base = { from: '2026-08-02', to: '2026-10-31', deviceTypeId: null, locationId: null };
        expect(historyExportSearchParams({ ...base, order: 'newest' }).toString()).toBe(
            'from=2026-08-02&to=2026-10-31',
        );
        expect(
            historyExportSearchParams({
                ...base,
                deviceTypeId: 3,
                locationId: 10,
                order: 'oldest',
            }).toString(),
        ).toBe(
            'from=2026-08-02&to=2026-10-31&device_type_id=3&location_id=10&include_sublocations=1&order=oldest',
        );
        const { fetch, requests } = respond(200, 'Aktionszeit;Kennung\r\n', {
            'Content-Type': 'text/csv; charset=utf-8',
            'Content-Disposition':
                'attachment; filename="historie-test-2026-08-02-bis-2026-10-31.csv"',
        });
        const result = await downloadHistoryExport({ ...base, order: 'newest' }, fetch);
        expect(result.ok && result.data.fileName).toBe(
            'historie-test-2026-08-02-bis-2026-10-31.csv',
        );
        expect(requests[0]?.url).toContain(
            '/test/api/v1/device-events/export?from=2026-08-02&to=2026-10-31',
        );
    });
});
