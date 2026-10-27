import type { ApiError } from '../api/client';

/** Wo der Fehler auftritt; bestimmt z. B., welcher Link bei invalid_token gemeint ist. */
export type ErrorContext =
    'general' | 'login' | 'password_reset' | 'email_verify' | 'invitation' | 'register';

export interface ErrorOptions {
    readonly context?: ErrorContext;
    /** Bereich des Super-Admins (andere Ansprechpartner). */
    readonly isService?: boolean;
}

/** Wartezeit in Alltagssprache, z. B. „in 45 Sekunden“, „in etwa 4 Minuten“. */
export function formatWaitTime(seconds: number | null): string {
    if (seconds === null || !Number.isFinite(seconds) || seconds <= 0) {
        return 'etwas später';
    }
    if (seconds < 60) {
        const s = Math.ceil(seconds);
        return s === 1 ? 'in 1 Sekunde' : `in ${s} Sekunden`;
    }
    if (seconds < 3600) {
        const m = Math.ceil(seconds / 60);
        return m === 1 ? 'in etwa 1 Minute' : `in etwa ${m} Minuten`;
    }
    const h = Math.ceil(seconds / 3600);
    return h === 1 ? 'in etwa 1 Stunde' : `in etwa ${h} Stunden`;
}

function invalidTokenMessage(context: ErrorContext): string {
    switch (context) {
        case 'password_reset':
            return 'Dieser Link ist abgelaufen oder wurde schon benutzt. Bitte fordern Sie einen neuen Link an.';
        case 'email_verify':
            return 'Dieser Bestätigungslink ist abgelaufen oder wurde schon benutzt. Sie können sich einen neuen Link schicken lassen.';
        case 'invitation':
            return 'Diese Einladung ist abgelaufen oder wurde schon benutzt. Bitte fordern Sie beim Super-Admin eine neue Einladung an.';
        default:
            return 'Dieser Link ist abgelaufen oder wurde schon benutzt.';
    }
}

/** Meldung in Alltagssprache zu einem Fehler der API (der Code erscheint nur klein daneben). */
export function errorMessage(error: ApiError, options: ErrorOptions = {}): string {
    const context = options.context ?? 'general';
    const contact =
        options.isService === true ? 'einen anderen Super-Admin' : 'einen Admin Ihrer Feuerwehr';
    switch (error.code) {
        case 'network':
            return 'Keine Verbindung zum Server. Bitte prüfen Sie die Internetverbindung und versuchen Sie es erneut.';
        case 'invalid_response':
        case 'internal_error':
            return 'Beim Server ist ein Fehler aufgetreten. Bitte versuchen Sie es später erneut.';
        case 'database_unavailable':
            return 'Der Server ist gerade nicht erreichbar. Bitte versuchen Sie es in einigen Minuten erneut.';
        case 'invalid_credentials':
            return 'E-Mail-Adresse oder Passwort ist falsch.';
        case 'account_locked':
            return `Ihr Konto ist gesperrt. Bitte wenden Sie sich an ${contact}.`;
        case 'email_not_verified':
            return 'Ihre E-Mail-Adresse ist noch nicht bestätigt. Bitte öffnen Sie den Link in der Bestätigungsmail.';
        case 'pending_approval':
            return 'Ihr Konto wartet auf Freischaltung durch einen Admin Ihrer Feuerwehr. Sie erhalten eine E-Mail, sobald es freigeschaltet ist.';
        case 'too_many_attempts':
            return `Zu viele Versuche. Bitte versuchen Sie es ${formatWaitTime(error.retryAfterSeconds)} erneut.`;
        case 'invalid_token':
            return invalidTokenMessage(context);
        case 'invalid_invitation_code':
            return 'Dieser Einladungscode ist ungültig, abgelaufen oder schon aufgebraucht. Bitte fragen Sie einen Admin Ihrer Feuerwehr nach einem neuen Code.';
        case 'tenant_inactive':
            return 'Der Zugang dieser Feuerwehr ist gesperrt.';
        case 'tenant_not_found':
            return 'Diese Feuerwehr gibt es unter dieser Adresse nicht.';
        case 'not_authenticated':
            return 'Ihre Sitzung ist abgelaufen. Bitte melden Sie sich erneut an.';
        case 'forbidden':
            return 'Dafür fehlen Ihnen die Rechte.';
        case 'weak_password':
            return error.message ?? 'Dieses Passwort ist zu unsicher.';
        default:
            return error.message ?? 'Ein unerwarteter Fehler ist aufgetreten.';
    }
}
