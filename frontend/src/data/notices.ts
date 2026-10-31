import type { EventLocation, HistoryExportParams } from '../api/deviceEvents';
import type { DeviceStatus } from '../api/devices';
import type { Notice, NoticeFilter, NoticeUser } from '../api/notices';
import { MAX_COMMENT_LENGTH } from './booking';
import { dateTimeText, daysBetween } from './changeTime';
import { dateProblem, parseDateInput } from './deviceForm';
import { phpTrim } from './masterData';

/**
 * Protokoll des Admins (`protokoll`, KONZEPT.md, "Hinweise für Admins"): Filter der Liste in der
 * Adresse, Texte der Hinweise, Rückfrage per Mail, Notiz beim Erledigen und Export des Verlaufs.
 */

type QueryValue = string | null | (string | null)[] | undefined;

/** Filter in der Adresse: ohne Angabe offen, `status=erledigt` bzw. `status=alle`. */
const FILTER_WORDS: Readonly<Record<NoticeFilter, string | null>> = {
    open: null,
    resolved: 'erledigt',
    all: 'alle',
};

export const NOTICE_FILTERS: readonly { readonly filter: NoticeFilter; readonly label: string }[] =
    [
        { filter: 'open', label: 'Offen' },
        { filter: 'resolved', label: 'Erledigt' },
        { filter: 'all', label: 'Alle' },
    ];

export function noticeFilterFromQuery(value: QueryValue): NoticeFilter {
    if (value === 'erledigt') {
        return 'resolved';
    }
    return value === 'alle' ? 'all' : 'open';
}

export function noticeFilterQuery(filter: NoticeFilter): Record<string, string> {
    const word = FILTER_WORDS[filter];
    return word === null ? {} : { status: word };
}

/** „3 offene Hinweise“, „1 erledigter Hinweis“, „12 Hinweise“. */
export function noticeCountText(total: number, filter: NoticeFilter): string {
    const n = String(total);
    switch (filter) {
        case 'open':
            return total === 1 ? '1 offener Hinweis' : `${n} offene Hinweise`;
        case 'resolved':
            return total === 1 ? '1 erledigter Hinweis' : `${n} erledigte Hinweise`;
        case 'all':
            return total === 1 ? '1 Hinweis' : `${n} Hinweise`;
    }
}

export function emptyNoticeText(filter: NoticeFilter): string {
    switch (filter) {
        case 'open':
            return 'Keine offenen Hinweise. Hier erscheinen Offline-Buchungen, die ein Admin prüfen sollte.';
        case 'resolved':
            return 'Noch keine erledigten Hinweise.';
        case 'all':
            return 'Noch keine Hinweise.';
    }
}

/** Was geschehen ist, je Art des Hinweises (NoticeKind). */
const KIND_EXPLANATIONS: Readonly<Partial<Record<string, string>>> = {
    overwritten:
        'Die Buchung wurde offline erfasst und kam erst später beim Server an. Inzwischen war das Gerät anders gebucht worden; weil die Buchung danach erfasst wurde, gilt sie und hat diesen Stand überschrieben.',
    history_only:
        'Die Buchung wurde offline erfasst und kam erst an, nachdem das Gerät später noch einmal geändert worden war. Sie steht nur im Verlauf; das Gerät behielt den neueren Stand.',
    not_applied_retired:
        'Das Gerät war beim Eingang der Buchung ausgesondert. Die Buchung steht nur im Verlauf.',
    not_applied_location_locked:
        'Der gewünschte Standort war beim Eingang der Buchung gesperrt. Die Buchung steht nur im Verlauf; das Gerät blieb, wo es war.',
    stale_confirmed:
        'Die Buchung war älter als erlaubt und wurde nach einer Rückfrage auf dem Handy trotzdem gesendet.',
};

export function noticeExplanation(notice: Notice): string | null {
    const text = KIND_EXPLANATIONS[notice.kind] ?? null;
    if (notice.staleConfirmed && notice.kind !== 'stale_confirmed') {
        const stale =
            'Außerdem war die Buchung älter als erlaubt und wurde nach einer Rückfrage auf dem Handy gesendet.';
        return text === null ? stale : `${text} ${stale}`;
    }
    return text;
}

/** Überschrift des anderen Stands: bei `overwritten` der überschriebene, sonst der beim Eingang. */
export function otherStateHeading(notice: Notice): string {
    return notice.kind === 'overwritten' ? 'Überschriebener Stand' : 'Stand beim Eingang';
}

/** „HLF 20 / G1 · defekt“ (Teile, die fehlen, entfallen). */
export function stateText(
    location: EventLocation | null,
    status: { readonly value: DeviceStatus; readonly label: string } | null,
): string {
    return [location?.path, status?.label].filter((part) => part !== undefined).join(' · ');
}

/** Gerät für Betreff und Text: „Atemschutzmaske M1“. */
function deviceName(notice: Notice): string {
    return `${notice.device.deviceType} ${notice.device.identifier}`;
}

/** Zeichen der Mail-Adresse, die in `mailto:` bleiben dürfen (RFC 6068), der Rest kodiert. */
function mailAddress(email: string): string {
    return encodeURIComponent(email).replace(/%40/g, '@').replace(/%2B/g, '+');
}

function mailPart(text: string): string {
    return encodeURIComponent(text.replace(/\n/g, '\r\n'));
}

/**
 * Rückfrage per Mail (KONZEPT.md, "Hinweise für Admins") an das Konto der Buchung bzw. des anderen
 * Stands: `mailto:` mit Betreff „Rückfrage zu Ihrer Buchung von Atemschutzmaske M1“ und einem
 * Anfang des Textes mit Zeit und Stand. Ohne E-Mail (gelöschtes Konto) null.
 */
export function inquiryMailLink(notice: Notice, side: 'booking' | 'other'): string | null {
    const user: NoticeUser | null = side === 'booking' ? notice.booking.user : notice.other.user;
    const email = user?.email ?? null;
    if (user === null || email === null) {
        return null;
    }
    const time = dateTimeText(
        side === 'booking' ? notice.booking.occurredAt : notice.other.occurredAt,
    );
    const state =
        side === 'booking'
            ? stateText(notice.booking.location, notice.booking.status)
            : stateText(notice.other.location, notice.other.status);
    const subject = `Rückfrage zu Ihrer Buchung von ${deviceName(notice)}`;
    const body = [
        `Guten Tag ${user.name},`,
        '',
        `zu Ihrer Buchung von ${deviceName(notice)} am ${time}${state === '' ? '' : ` (${state})`} habe ich eine Rückfrage:`,
        '',
        '',
    ].join('\n');
    return `mailto:${mailAddress(email)}?subject=${mailPart(subject)}&body=${mailPart(body)}`;
}

/**
 * Notiz beim Erledigen wie DeviceText::noticeNote: Zeilenumbrüche bleiben (als LF), andere
 * Steuerzeichen (z. B. Tabulatoren aus kopiertem Text) werden zu Leerzeichen; Rand wie PHP `trim()`.
 * Leer = null.
 */
export function noticeNote(text: string): string | null {
    const note = phpTrim(text.replace(/\r\n?/g, '\n').replace(/[^\P{Cc}\n]/gu, ' '));
    return note === '' ? null : note;
}

/** Höchstens 500 Zeichen (Unicode-Zeichen wie mb_strlen); null = in Ordnung. */
export function noticeNoteProblem(text: string): string | null {
    const length = Array.from(noticeNote(text) ?? '').length;
    return length > MAX_COMMENT_LENGTH
        ? `Höchstens ${String(MAX_COMMENT_LENGTH)} Zeichen (jetzt ${String(length)}).`
        : null;
}

/** Wie DeviceHistoryExportHandler::DEFAULT_DAYS und MAX_DAYS (per Test gleich). */
export const HISTORY_EXPORT_DEFAULT_DAYS = 90;
export const HISTORY_EXPORT_MAX_DAYS = 366;

/** Schnellwahl des Zeitraums, je bis heute. */
export const HISTORY_EXPORT_PRESETS: readonly { readonly days: number; readonly label: string }[] =
    [
        { days: 30, label: 'Letzte 30 Tage' },
        { days: 90, label: 'Letzte 90 Tage' },
        { days: 365, label: 'Letzte 365 Tage' },
    ];

export interface HistoryExportForm {
    /** Eingabe der Datumsfelder (JJJJ-MM-TT, ohne Datumsauswahl auch TT.MM.JJJJ). */
    readonly from: string;
    readonly to: string;
    readonly deviceTypeId: number | null;
    readonly locationId: number | null;
    readonly order: 'newest' | 'oldest';
}

/** `JJJJ-MM-TT` plus `days` Kalendertage (ohne Zeitzone). */
export function addDays(date: string, days: number): string {
    const [year = 0, month = 1, day = 1] = date.split('-').map(Number);
    const result = new Date(Date.UTC(year, month - 1, day + days));
    return result.toISOString().slice(0, 10);
}

/** Zeitraum der letzten `days` Tage bis `today` (beide einschließlich). */
export function presetRange(today: string, days: number): { from: string; to: string } {
    return { from: addDays(today, 1 - days), to: today };
}

/** Standard wie der Server: die letzten 90 Tage bis heute, alle Geräte, neueste zuerst. */
export function defaultHistoryExportForm(today: string): HistoryExportForm {
    return {
        ...presetRange(today, HISTORY_EXPORT_DEFAULT_DAYS),
        deviceTypeId: null,
        locationId: null,
        order: 'newest',
    };
}

export interface HistoryExportProblems {
    readonly from?: string;
    readonly to?: string;
    /** Zeitraum als Ganzes (Reihenfolge, Länge). */
    readonly range?: string;
}

function dayProblem(value: string): string | null {
    return value.trim() === '' ? 'Bitte ein Datum eingeben.' : dateProblem(value);
}

export function historyExportProblems(form: HistoryExportForm): HistoryExportProblems {
    const from = dayProblem(form.from);
    const to = dayProblem(form.to);
    const problems: { from?: string; to?: string; range?: string } = {
        ...(from !== null && { from }),
        ...(to !== null && { to }),
    };
    const start = parseDateInput(form.from);
    const end = parseDateInput(form.to);
    if (from === null && to === null && typeof start === 'string' && typeof end === 'string') {
        const days = daysBetween(start, end) + 1;
        if (days < 1) {
            problems.range = 'Das Enddatum liegt vor dem Startdatum.';
        } else if (days > HISTORY_EXPORT_MAX_DAYS) {
            problems.range = `Höchstens ${String(HISTORY_EXPORT_MAX_DAYS)} Tage auf einmal (jetzt ${String(days)}).`;
        }
    }
    return problems;
}

/** Parameter für GET device-events/export; null, solange die Eingabe nicht passt. */
export function historyExportParams(form: HistoryExportForm): HistoryExportParams | null {
    const problems = historyExportProblems(form);
    const from = parseDateInput(form.from);
    const to = parseDateInput(form.to);
    if (Object.keys(problems).length > 0 || typeof from !== 'string' || typeof to !== 'string') {
        return null;
    }
    return {
        from,
        to,
        deviceTypeId: form.deviceTypeId,
        locationId: form.locationId,
        order: form.order,
    };
}
