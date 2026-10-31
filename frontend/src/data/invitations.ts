import type { InvitationCode, NewInvitationCode } from '../api/invitationCodes';
import { phpTrim } from './masterData';

/**
 * Einladungscodes der Benutzerverwaltung (`benutzer?ansicht=einladungscodes`; KONZEPT.md
 * "Admin-Konsole – Entscheidungen", 30): Prüfung beim Tippen, Texte, Link und Aushang. Verbindlich
 * bleibt der Server (InvitationCodeCreateHandler, InvitationCodeRepository).
 */

/** Wie InvitationCodeLabel::MAX_LENGTH. */
export const LABEL_MAX_LENGTH = 100;
/** Wie InvitationCodeRepository::MAX_VALID_DAYS und MAX_USES. */
export const MAX_VALID_DAYS = 90;
export const MAX_USES = 500;
/** Vorbelegung: ein Monat, genug für einen Dienstabend mit Aushang an der Wache. */
export const DEFAULT_VALID_DAYS = 30;
export const DEFAULT_MAX_USES = 50;

/** Eingaben im Fenster „Neuer Einladungscode“ (Zahlen als Text wie im Feld). */
export interface InvitationForm {
    readonly label: string;
    readonly validDays: string;
    readonly maxUses: string;
}

export function newInvitationForm(): InvitationForm {
    return {
        label: '',
        validDays: String(DEFAULT_VALID_DAYS),
        maxUses: String(DEFAULT_MAX_USES),
    };
}

export interface InvitationProblems {
    readonly label?: string;
    readonly validDays?: string;
    readonly maxUses?: string;
}

/** Ganze Zahl von 1 bis `max` ohne Vorzeichen (Rand wie PHP `trim()`), sonst null. */
function wholeNumber(value: string, max: number): number | null {
    const text = phpTrim(value);
    if (!/^\d{1,4}$/.test(text)) {
        return null;
    }
    const number = Number(text);
    return number >= 1 && number <= max ? number : null;
}

/** Bezeichnung wie InvitationCodeLabel (leer = keine), sonst die Meldung. */
function labelProblem(value: string): string | null {
    const label = phpTrim(value);
    const length = Array.from(label).length;
    if (length > LABEL_MAX_LENGTH) {
        return `Höchstens ${String(LABEL_MAX_LENGTH)} Zeichen (jetzt ${String(length)}).`;
    }
    if (/\p{Cc}/u.test(label)) {
        return 'Keine Zeilenumbrüche oder Steuerzeichen.';
    }
    return null;
}

export function invitationProblems(form: InvitationForm): InvitationProblems {
    const label = labelProblem(form.label);
    return {
        ...(label !== null && { label }),
        ...(wholeNumber(form.validDays, MAX_VALID_DAYS) === null && {
            validDays: `Bitte 1 bis ${String(MAX_VALID_DAYS)} Tage angeben.`,
        }),
        ...(wholeNumber(form.maxUses, MAX_USES) === null && {
            maxUses: `Bitte eine Zahl von 1 bis ${String(MAX_USES)} angeben.`,
        }),
    };
}

/** Body für POST invitation-codes; null, solange die Prüfung etwas findet. */
export function invitationRequest(form: InvitationForm): NewInvitationCode | null {
    const validDays = wholeNumber(form.validDays, MAX_VALID_DAYS);
    const maxUses = wholeNumber(form.maxUses, MAX_USES);
    if (labelProblem(form.label) !== null || validDays === null || maxUses === null) {
        return null;
    }
    const label = phpTrim(form.label);
    return { label: label === '' ? null : label, validDays, maxUses };
}

/** Ungefähres Ende der Gültigkeit für den Hinweis am Feld (der Server rechnet ab seiner Zeit). */
export function expiryPreview(form: InvitationForm, now: Date): string | null {
    const days = wholeNumber(form.validDays, MAX_VALID_DAYS);
    if (days === null) {
        return null;
    }
    return dateTimeText(new Date(now.getTime() + days * 86_400_000).toISOString());
}

/** „30.11.2026, 14:05“ in Ortszeit. */
export function dateTimeText(iso: string): string {
    const date = new Date(iso);
    const day = date.toLocaleDateString('de-DE', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    });
    const time = date.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
    return `${day}, ${time}`;
}

/** „12.10.2026“ in Ortszeit. */
export function dateText(iso: string): string {
    return new Date(iso).toLocaleDateString('de-DE', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    });
}

/** Name in Liste, Rückfrage und Meldungen. */
export function codeTitle(code: InvitationCode): string {
    return code.label ?? `Code #${String(code.id)}`;
}

/** „3 von 50 Registrierungen“. */
export function usesText(code: Pick<InvitationCode, 'useCount' | 'maxUses'>): string {
    return `${String(code.useCount)} von ${String(code.maxUses)} Registrierungen`;
}

/** Nur aktive Codes lassen sich widerrufen und verwenden. */
export function isUsable(code: InvitationCode): boolean {
    return code.status === 'active';
}

/** Liste: aktive immer, abgelaufene, aufgebrauchte und widerrufene nur auf Wunsch (Reihenfolge des Servers). */
export function visibleCodes(
    codes: readonly InvitationCode[],
    showInactive: boolean,
): InvitationCode[] {
    return codes.filter((code) => showInactive || isUsable(code));
}

/**
 * Link zur Registrierung mit dem Code im Fragment (KONZEPT.md "Anmeldung und Registrierung", 1):
 * `…/registrieren#code=XXXX-XXXX-XXXX`; der Code erreicht so nie die Server-Adresse.
 */
export function registerLink(code: string, baseUri: string = document.baseURI): string {
    return new URL(`registrieren#code=${code}`, baseUri).href;
}

/** Adresse zum Abtippen auf dem Aushang, ohne Schema und Code: „www.prodad.de/firefighting/…“. */
export function registerAddress(baseUri: string = document.baseURI): string {
    const url = new URL('registrieren', baseUri);
    return `${url.host}${url.pathname}`;
}

/** Inhalt für „Link teilen“ (Web Share API) bzw. die Zwischenablage. */
export interface ShareContent {
    readonly title: string;
    readonly text: string;
    readonly url: string;
}

export function shareContent(
    tenantName: string,
    code: string,
    expiresAt: string,
    baseUri: string = document.baseURI,
): ShareContent {
    return {
        title: `Registrierung ${tenantName}`,
        text: `Registrierung in der Geräteverwaltung (${tenantName}): Link öffnen, Formular ausfüllen und die E-Mail-Adresse bestätigen. Einladungscode ${code}, gültig bis ${dateTimeText(expiresAt)}.`,
        url: registerLink(code, baseUri),
    };
}
