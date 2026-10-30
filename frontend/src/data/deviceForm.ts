import type {
    BookableStatus,
    DeviceDetail,
    DeviceFieldChanges,
    NewDeviceRequest,
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

/**
 * Eingaben auf „Neues Gerät“ (`geraete/neu`, KONZEPT.md, "Admin-Konsole – Entscheidungen", 32).
 * Gerätetyp und Standort `null` = noch nicht gewählt.
 */
export interface NewDeviceForm {
    identifier: string;
    deviceTypeId: number | null;
    locationId: number | null;
    status: BookableStatus;
    scanCode: string;
    /** Wert des Datumsfelds (JJJJ-MM-TT) oder ''. */
    nextInspectionDue: string;
    serialNumber: string;
    manufacturer: string;
    model: string;
    /** Wert des Datumsfelds (JJJJ-MM-TT) oder ''. */
    procuredOn: string;
    note: string;
    comment: string;
}

export type NewDeviceField = keyof NewDeviceForm;

export function emptyNewDeviceForm(): NewDeviceForm {
    return {
        identifier: '',
        deviceTypeId: null,
        locationId: null,
        status: 'ready',
        scanCode: '',
        nextInspectionDue: '',
        serialNumber: '',
        manufacturer: '',
        model: '',
        procuredOn: '',
        note: '',
        comment: '',
    };
}

export function sameNewDeviceForm(a: NewDeviceForm, b: NewDeviceForm): boolean {
    return (Object.keys(a) as NewDeviceField[]).every((key) => a[key] === b[key]);
}

/** Probleme je Feld (nur Felder mit Problem); der Scan-Code ist freiwillig. */
export function newDeviceProblems(form: NewDeviceForm): Partial<Record<NewDeviceField, string>> {
    const problems: Partial<Record<NewDeviceField, string>> = {};
    const checks: [NewDeviceField, string | null][] = [
        ['identifier', identifierProblem(form.identifier)],
        ['deviceTypeId', form.deviceTypeId === null ? 'Bitte einen Gerätetyp wählen.' : null],
        ['locationId', form.locationId === null ? 'Bitte einen Standort wählen.' : null],
        ['scanCode', trimCode(form.scanCode) === '' ? null : scanCodeProblem(form.scanCode)],
        ['nextInspectionDue', dateProblem(form.nextInspectionDue)],
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

/** Body von POST devices; nur aufrufen, wenn `newDeviceProblems` leer ist. */
export function newDeviceRequest(form: NewDeviceForm): NewDeviceRequest {
    if (form.deviceTypeId === null || form.locationId === null) {
        throw new Error('Gerätetyp und Standort fehlen.');
    }
    const scanCode = trimCode(form.scanCode);
    const optional = {
        scanCode: scanCode === '' ? null : scanCode,
        serialNumber: textValue(form.serialNumber),
        manufacturer: textValue(form.manufacturer),
        model: textValue(form.model),
        procuredOn: parseDateInput(form.procuredOn) ?? null,
        nextInspectionDue: parseDateInput(form.nextInspectionDue) ?? null,
        note: textValue(form.note, true),
    };
    const request: { -readonly [K in keyof NewDeviceRequest]: NewDeviceRequest[K] } = {
        identifier: identifierText(form.identifier),
        deviceTypeId: form.deviceTypeId,
        locationId: form.locationId,
        status: form.status,
        ...commentPart(form.comment),
    };
    for (const [key, value] of Object.entries(optional)) {
        if (value !== null) {
            Object.assign(request, { [key]: value });
        }
    }
    return request;
}

/**
 * Nächste Kennung für „Speichern und nächstes“: Die Zahl am Ende zählt weiter, führende Nullen
 * bleiben („M24“ → „M25“, „PA 009“ → „PA 010“, „M99“ → „M100“). Ohne Zahl am Ende oder zu lang:
 * null (das Feld bleibt leer).
 */
export function nextIdentifier(identifier: string): string | null {
    const match = /^(.*?)(\d+)$/u.exec(identifierText(identifier));
    if (match === null) {
        return null;
    }
    const [, prefix = '', digits = ''] = match;
    const next = Array.from(digits);
    let index = next.length - 1;
    while (index >= 0 && next[index] === '9') {
        next[index] = '0';
        index -= 1;
    }
    if (index < 0) {
        next.unshift('1');
    } else {
        next[index] = String(Number(next[index]) + 1);
    }
    const result = prefix + next.join('');
    return length(result) > IDENTIFIER_MAX_LENGTH ? null : result;
}

/**
 * Formular nach „Speichern und nächstes“: Gerätetyp, Standort und Prüfdatum bleiben, die Kennung
 * zählt weiter, alles andere ist wieder leer (Status einsatzbereit).
 */
export function nextNewDeviceForm(saved: NewDeviceForm): NewDeviceForm {
    return {
        ...emptyNewDeviceForm(),
        identifier: nextIdentifier(saved.identifier) ?? '',
        deviceTypeId: saved.deviceTypeId,
        locationId: saved.locationId,
        nextInspectionDue: saved.nextInspectionDue,
    };
}
