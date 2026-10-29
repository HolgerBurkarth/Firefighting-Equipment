import { afterEach, describe, expect, it } from 'vitest';
import { parseBookingResponse } from '../../src/api/devices';
import {
    bookingNotice,
    ClientRefKeeper,
    commentLength,
    confirmationText,
    MAX_COMMENT_LENGTH,
    newClientRef,
    normalizeComment,
    setBookingNotice,
    takeBookingNotice,
} from '../../src/data/booking';
import { device, deviceJson } from './support/devices';

const UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

describe('Kommentar', () => {
    it.each([
        ['  Ventil undicht  ', 'Ventil undicht'],
        ['Zeile 1\nZeile 2', 'Zeile 1 Zeile 2'],
        ['Zeile 1 \r\n\r\n Zeile 2', 'Zeile 1 Zeile 2'],
        ['a\tb', 'a b'],
        ['a\u0085b', 'a b'],
        ['mehrere   Leerzeichen bleiben', 'mehrere   Leerzeichen bleiben'],
        ['\n\n', ''],
    ])('%j → %j', (input, expected) => {
        expect(normalizeComment(input)).toBe(expected);
    });

    it('Länge in Unicode-Zeichen nach dem Bereinigen', () => {
        expect(commentLength('  abcd ')).toBe(4);
        expect(commentLength('😀😀😀😀😀')).toBe(5);
        expect(commentLength('a\n\nb')).toBe(3);
    });

    it('Höchstlänge wie im Backend', () => {
        expect(MAX_COMMENT_LENGTH).toBe(500);
    });
});

describe('client_ref', () => {
    it('UUID v4 über randomUUID', () => {
        expect(newClientRef()).toMatch(UUID_V4);
    });

    it('UUID v4 ohne randomUUID (aus Zufallsbytes)', () => {
        const source = { getRandomValues: crypto.getRandomValues.bind(crypto) };
        const refs = new Set(Array.from({ length: 50 }, () => newClientRef(source)));
        expect(refs.size).toBe(50);
        for (const ref of refs) {
            expect(ref).toMatch(UUID_V4);
        }
    });

    it('gleicher Inhalt behält die Kennung, anderer bekommt eine neue', () => {
        let count = 0;
        const keeper = new ClientRefKeeper(() => `ref-${String(++count)}`);
        expect(keeper.refFor({ deviceId: 5, version: 1, status: 'defective' })).toBe('ref-1');
        expect(keeper.refFor({ deviceId: 5, version: 1, status: 'defective' })).toBe('ref-1');
        // Nach einem Konflikt: neue Version → neue Buchung.
        expect(keeper.refFor({ deviceId: 5, version: 2, status: 'defective' })).toBe('ref-2');
        expect(keeper.refFor({ deviceId: 5, version: 2, status: 'ready' })).toBe('ref-3');
        // Zurück zu einem früheren Inhalt: nicht die alte Kennung (der Server kennt sie vielleicht).
        expect(keeper.refFor({ deviceId: 5, version: 1, status: 'defective' })).toBe('ref-4');
    });
});

describe('Bestätigung nach dem Speichern', () => {
    afterEach(() => {
        window.sessionStorage.clear();
        setBookingNotice(null);
    });

    function response(outcome: string, message = 'Keine Änderung: …') {
        const parsed = parseBookingResponse({
            device: deviceJson(1, {
                location: { id: 12, name: 'G2', path: 'HLF 20 / G2' },
            }),
            result: {
                outcome,
                outcome_label: 'x',
                reason: null,
                reason_label: null,
                message,
                stale_confirmed: false,
                client_ref: null,
                replayed: false,
                notice_id: null,
            },
        });
        if (parsed === null) {
            throw new Error('Ungültige Antwort');
        }
        return parsed;
    }

    it('Text wie „M1 → HLF 20 / G2 · einsatzbereit“', () => {
        expect(
            confirmationText(device(1, { location: { id: 12, name: 'G2', path: 'HLF 20 / G2' } })),
        ).toBe('M1 → HLF 20 / G2 · einsatzbereit');
    });

    it('übernommen → grün, sonst die Meldung des Servers', () => {
        expect(bookingNotice(response('applied'))).toEqual({
            kind: 'success',
            text: 'M1 → HLF 20 / G2 · einsatzbereit',
        });
        expect(bookingNotice(response('unchanged', 'Keine Änderung: X.'))).toEqual({
            kind: 'info',
            text: 'Keine Änderung: X.',
        });
    });

    it('übersteht ein Neuladen (sessionStorage), wird einmal abgeholt', () => {
        setBookingNotice({ kind: 'success', text: 'M1 → LF 10 · defekt' });
        expect(window.sessionStorage.getItem('ff-booking-notice')).not.toBeNull();
        expect(takeBookingNotice()).toEqual({ kind: 'success', text: 'M1 → LF 10 · defekt' });
        expect(takeBookingNotice()).toBeNull();
    });

    it('ungültiger Inhalt → kein Hinweis', () => {
        window.sessionStorage.setItem('ff-booking-notice', '{"kind":"evil","text":"x"}');
        expect(takeBookingNotice()).toBeNull();
        window.sessionStorage.setItem('ff-booking-notice', 'kein JSON');
        expect(takeBookingNotice()).toBeNull();
    });

    it('ohne Speicher bis zum Neuladen im Speicher der Seite', () => {
        setBookingNotice({ kind: 'info', text: 'Hinweis' }, null);
        expect(takeBookingNotice(null)).toEqual({ kind: 'info', text: 'Hinweis' });
        expect(takeBookingNotice(null)).toBeNull();
    });
});
