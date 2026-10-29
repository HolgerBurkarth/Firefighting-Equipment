import { describe, expect, it } from 'vitest';
import type { ApiError } from '../../src/api/client';
import { errorMessage, formatCountdown, formatWaitTime } from '../../src/auth/errorMessages';

function error(code: string, extra: Partial<ApiError> = {}): ApiError {
    return { code, message: null, status: 400, retryAfterSeconds: null, details: null, ...extra };
}

describe('errorMessage', () => {
    it.each([
        ['network', 'Keine Verbindung zum Server'],
        ['invalid_response', 'Fehler aufgetreten'],
        ['invalid_credentials', 'E-Mail-Adresse oder Passwort ist falsch.'],
        ['invalid_current_password', 'Das aktuelle Passwort ist falsch.'],
        ['account_locked', 'Ihr Konto ist gesperrt'],
        ['email_not_verified', 'noch nicht bestätigt'],
        ['pending_approval', 'wartet auf Freischaltung'],
        ['invalid_invitation_code', 'Einladungscode ist ungültig'],
        ['tenant_inactive', 'Feuerwehr ist gesperrt'],
        ['tenant_not_found', 'gibt es unter dieser Adresse nicht'],
        ['not_authenticated', 'Sitzung ist abgelaufen'],
        ['database_unavailable', 'nicht erreichbar'],
    ])('%s', (code, text) => {
        expect(errorMessage(error(code))).toContain(text);
    });

    it('nennt nie den Code im Text', () => {
        for (const code of [
            'network',
            'invalid_credentials',
            'pending_approval',
            'invalid_token',
        ]) {
            expect(errorMessage(error(code))).not.toContain(code);
        }
    });

    it('Ansprechpartner je Bereich', () => {
        expect(errorMessage(error('account_locked'))).toContain('Admin Ihrer Feuerwehr');
        expect(errorMessage(error('account_locked'), { isService: true })).toContain(
            'anderen Super-Admin',
        );
    });

    it('invalid_token je Zusammenhang', () => {
        const e = error('invalid_token');
        expect(errorMessage(e, { context: 'password_reset' })).toContain('neuen Link an');
        expect(errorMessage(e, { context: 'email_verify' })).toContain('Bestätigungslink');
        expect(errorMessage(e, { context: 'invitation' })).toContain('neue Einladung');
    });

    it('Wartezeit aus Retry-After', () => {
        expect(errorMessage(error('too_many_attempts', { retryAfterSeconds: 240 }))).toBe(
            'Zu viele Versuche. Bitte versuchen Sie es in etwa 4 Minuten erneut.',
        );
        expect(errorMessage(error('too_many_attempts'))).toContain('etwas später erneut');
    });

    it('weak_password und Unbekanntes zeigen die Meldung des Servers', () => {
        const message = 'Das Passwort muss mindestens 10 Zeichen lang sein.';
        expect(errorMessage(error('weak_password', { message }))).toBe(message);
        expect(errorMessage(error('neu_im_backend', { message: 'Text.' }))).toBe('Text.');
        expect(errorMessage(error('neu_im_backend'))).toContain('unerwarteter Fehler');
    });
});

describe('formatWaitTime', () => {
    it.each([
        [null, 'etwas später'],
        [0, 'etwas später'],
        [1, 'in 1 Sekunde'],
        [45, 'in 45 Sekunden'],
        [59.2, 'in 60 Sekunden'],
        [60, 'in etwa 1 Minute'],
        [61, 'in etwa 2 Minuten'],
        [900, 'in etwa 15 Minuten'],
        [3600, 'in etwa 1 Stunde'],
        [3601, 'in etwa 2 Stunden'],
    ])('%j → %s', (seconds, text) => {
        expect(formatWaitTime(seconds)).toBe(text);
    });
});

describe('formatCountdown', () => {
    it.each([
        [0, '0:00'],
        [5, '0:05'],
        [59.1, '1:00'],
        [75, '1:15'],
        [900, '15:00'],
    ])('%j → %s', (seconds, text) => {
        expect(formatCountdown(seconds)).toBe(text);
    });
});
