import type {
    BookableStatus,
    DeviceDetail,
    DeviceFieldChanges,
    ReinstateRequest,
} from '../api/devices';
import { normalizeComment } from './booking';
import {
    IDENTIFIER_MAX_LENGTH,
    identifierText,
    SCAN_CODE_MAX_LENGTH,
    trimCode,
} from './deviceSearch';
import { phpTrim } from './masterData';

/**
 * Formulare der Gerätedetails für Admins (KONZEPT.md, "Bereiche des Admins", Geräte): Prüfung beim
 * Tippen wie die Regeln im Backend (DeviceIdentifier, ScanCode, DeviceText, DeviceDate), nie strenger;
 * verbindlich bleibt der Server. Dazu, welche Felder sich gegenüber dem Gerät ändern.
 */

/** Höchstlängen wie DeviceText in PHP (Seriennummer, Hersteller, Modell bzw. Notiz). */
export const TEXT_MAX_LENGTH = 100;
export const NOTE_MAX_LENGTH = 1000;

/** Zulässige Daten wie DeviceDate::MIN/MAX in PHP. */
export const DATE_MIN = '1950-01-01';
export const DATE_MAX = '2099-12-31';

function length(value: string): number {
    return Array.from(value).length;
}

/** Kennung wie DeviceIdentifier; null = in Ordnung. Gesendet wird `identifierText`. */
export function identifierProblem(value: string): string | null {
    const text = identifierText(value);
    if (text === '') {
        return 'Bitte eine Kennung eingeben.';
    }
    if (length(text) > IDENTIFIER_MAX_LENGTH) {
        return `Höchstens ${String(IDENTIFIER_MAX_LENGTH)} Zeichen (jetzt ${String(length(text))}).`;
    }
    if (/\p{M}/u.test(text)) {
        return 'Bitte den Buchstaben direkt eingeben, nicht den Akzent einzeln.';
    }
    if (!/^[\p{L}0-9./-]+( [\p{L}0-9./-]+)*$/u.test(text)) {
        return 'Nur Buchstaben, Ziffern, Leerzeichen, Bindestrich (-), Schrägstrich (/) und Punkt (.).';
    }
    return null;
}

/**
 * Freier Text wie DeviceText (Seriennummer, Hersteller, Modell; mit `multiline` die Notiz mit
 * Zeilenumbrüchen): Leerraum am Rand wie PHP `trim()`, leer = null.
 */
export function textValue(value: string, multiline = false): string | null {
    const text = phpTrim(multiline ? value.replace(/\r\n?/g, '\n') : value);
    return text === '' ? null : text;
}

export function textProblem(value: string, maxLength: number, multiline = false): string | null {
    const text = textValue(value, multiline) ?? '';
    if (length(text) > maxLength) {
        return `Höchstens ${String(maxLength)} Zeichen (jetzt ${String(length(text))}).`;
    }
    if ((multiline ? /[^\P{Cc}\n]/u : /\p{Cc}/u).test(text)) {
        return multiline
            ? 'Außer Zeilenumbrüchen keine Steuerzeichen (z. B. Tabulatoren).'
            : 'Keine Zeilenumbrüche oder Steuerzeichen.';
    }
    return null;
}

/**
 * Datum aus einem Eingabefeld: JJJJ-MM-TT (Datumsauswahl des Browsers) oder TT.MM.JJJJ (Browser
 * ohne Datumsauswahl). '' = kein Datum (null), ungültig = undefined.
 */
export function parseDateInput(value: string): string | null | undefined {
    const text = value.trim();
    if (text === '') {
        return null;
    }
    const iso = /^(\d{4})-(\d{2})-(\d{2})$/.exec(text);
    const german = /^(\d{1,2})\.(\d{1,2})\.(\d{4})$/.exec(text);
    const [year, month, day] = iso
        ? [iso[1], iso[2], iso[3]]
        : german
          ? [german[3], german[2], german[1]]
          : [];
    if (year === undefined || month === undefined || day === undefined) {
        return undefined;
    }
    const date = new Date(Date.UTC(Number(year), Number(month) - 1, Number(day)));
    if (date.getUTCMonth() !== Number(month) - 1 || date.getUTCDate() !== Number(day)) {
        return undefined;
    }
    return `${year}-${month.padStart(2, '0')}-${day.padStart(2, '0')}`;
}

export function dateProblem(value: string): string | null {
    const date = parseDateInput(value);
    if (date === undefined) {
        return 'Bitte ein gültiges Datum eingeben (z. B. 01.12.2026).';
    }
    if (date !== null && (date < DATE_MIN || date > DATE_MAX)) {
        return 'Das Datum muss zwischen 1950 und 2099 liegen.';
    }
    return null;
}

/** Scan-Code wie ScanCode (Rand ohne Leerzeichen/Tab/CR/LF, GS/RS/EOT erlaubt); null = in Ordnung. */
export function scanCodeProblem(value: string): string | null {
    const code = trimCode(value);
    if (code === '') {
        return 'Bitte einen Code scannen oder eingeben.';
    }
    if (length(code) > SCAN_CODE_MAX_LENGTH) {
        return `Höchstens ${String(SCAN_CODE_MAX_LENGTH)} Zeichen (jetzt ${String(length(code))}).`;
    }
    if (/[\u0000-\u0003\u0005-\u001c\u001f\u007f-\u009f]/.test(code)) {
        return 'Keine Steuerzeichen außer den Trennzeichen GS, RS und EOT (oft zwei zusammengefügte Scans).';
    }
    if (/[\p{Cf}\p{Zl}\p{Zp}]/u.test(code)) {
        return 'Der Code enthält unsichtbare Formatzeichen. Bitte erneut scannen oder eingeben.';
    }
    return null;
}

/** Kommentar der Aktion (eine Zeile wie beim Umbuchen); leer = ohne. */
export function commentPart(text: string): { comment?: string } {
    const comment = normalizeComment(text);
    return comment === '' ? {} : { comment };
}

/** Eingaben im Fenster „Bearbeiten“ (Stammdaten ohne Scan-Code; der hat „Code zuordnen“). */
export interface DeviceEditForm {
    identifier: string;
    deviceTypeId: number;
    serialNumber: string;
    manufacturer: string;
    model: string;
    /** Wert des Datumsfelds (JJJJ-MM-TT) oder ''. */
    procuredOn: string;
    note: string;
}

export type EditField = keyof DeviceEditForm;

export function editFormFromDevice(device: DeviceDetail): DeviceEditForm {
    return {
        identifier: device.identifier,
        deviceTypeId: device.deviceType.id,
        serialNumber: device.serialNumber ?? '',
        manufacturer: device.manufacturer ?? '',
        model: device.model ?? '',
        procuredOn: device.procuredOn ?? '',
        note: device.note ?? '',
    };
}

/** Probleme je Feld (nur Felder mit Problem). */
export function editFormProblems(form: DeviceEditForm): Partial<Record<EditField, string>> {
    const problems: Partial<Record<EditField, string>> = {};
    const checks: [EditField, string | null][] = [
        ['identifier', identifierProblem(form.identifier)],
        ['serialNumber', textProblem(form.serialNumber, TEXT_MAX_LENGTH)],
        ['manufacturer', textProblem(form.manufacturer, TEXT_MAX_LENGTH)],
        ['model', textProblem(form.model, TEXT_MAX_LENGTH)],
        ['procuredOn', dateProblem(form.procuredOn)],
        ['note', textProblem(form.note, NOTE_MAX_LENGTH, true)],
    ];
    for (const [field, problem] of checks) {
        if (problem !== null) {
            problems[field] = problem;
        }
    }
    return problems;
}

/**
 * Was sich gegenüber dem Gerät ändert (nur diese Felder gehen an den Server). Vergleich wie der
 * Server: binär nach dem Bereinigen (die Kennung „m1“ statt „M1“ ist eine Änderung).
 */
export function editFormChanges(device: DeviceDetail, form: DeviceEditForm): DeviceFieldChanges {
    const changes: { -readonly [K in keyof DeviceFieldChanges]: DeviceFieldChanges[K] } = {};
    const identifier = identifierText(form.identifier);
    if (identifier !== device.identifier) {
        changes.identifier = identifier;
    }
    if (form.deviceTypeId !== device.deviceType.id) {
        changes.deviceTypeId = form.deviceTypeId;
    }
    const serialNumber = textValue(form.serialNumber);
    if (serialNumber !== device.serialNumber) {
        changes.serialNumber = serialNumber;
    }
    const manufacturer = textValue(form.manufacturer);
    if (manufacturer !== device.manufacturer) {
        changes.manufacturer = manufacturer;
    }
    const model = textValue(form.model);
    if (model !== device.model) {
        changes.model = model;
    }
    const procuredOn = parseDateInput(form.procuredOn) ?? null;
    if (procuredOn !== device.procuredOn) {
        changes.procuredOn = procuredOn;
    }
    const note = textValue(form.note, true);
    if (note !== device.note) {
        changes.note = note;
    }
    return changes;
}

/**
 * Nach 409 version_conflict: Felder, die der Admin nicht geändert hat, folgen dem neuen Stand; seine
 * eigenen Änderungen bleiben (wie beim Umbuchen).
 */
export function rebaseEditForm(
    form: DeviceEditForm,
    before: DeviceDetail,
    after: DeviceDetail,
): DeviceEditForm {
    const old = editFormFromDevice(before);
    const latest = editFormFromDevice(after);
    const rebased = { ...form };
    for (const key of Object.keys(form) as EditField[]) {
        if (form[key] === old[key]) {
            Object.assign(rebased, { [key]: latest[key] });
        }
    }
    return rebased;
}

/** Eingaben im Fenster „Wieder aufnehmen“. */
export interface ReinstateForm {
    status: BookableStatus;
    locationId: number;
    identifier: string;
    deviceTypeId: number;
    /** false = Scan-Code entfernen (z. B. inzwischen an einem anderen Gerät). */
    keepScanCode: boolean;
}

/** Vorschlag: einsatzbereit, bisheriger Standort, bisherige Kennung, Typ und Scan-Code. */
export function reinstateFormFromDevice(device: DeviceDetail): ReinstateForm {
    return {
        status: 'ready',
        locationId: device.location.id,
        identifier: device.identifier,
        deviceTypeId: device.deviceType.id,
        keepScanCode: true,
    };
}

/** Body von POST devices/{id}/reinstate: nur geänderte Angaben (sonst gelten die bisherigen). */
export function reinstateRequest(
    device: DeviceDetail,
    form: ReinstateForm,
    comment: string,
): ReinstateRequest {
    const identifier = identifierText(form.identifier);
    return {
        version: device.version,
        status: form.status,
        ...(form.locationId !== device.location.id && { locationId: form.locationId }),
        ...(identifier !== device.identifier && { identifier }),
        ...(form.deviceTypeId !== device.deviceType.id && { deviceTypeId: form.deviceTypeId }),
        ...(!form.keepScanCode && device.scanCode !== null && { scanCode: null }),
        ...commentPart(comment),
    };
}
