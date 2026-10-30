import { describe, expect, it } from 'vitest';
import {
    dateProblem,
    editFormChanges,
    editFormFromDevice,
    editFormProblems,
    identifierProblem,
    parseDateInput,
    rebaseEditForm,
    reinstateFormFromDevice,
    reinstateRequest,
    scanCodeProblem,
    textProblem,
    textValue,
} from '../../src/data/deviceForm';
import { detail } from './support/devices';

const GS = String.fromCharCode(0x1d);
const TAB = String.fromCharCode(9);
const ZERO_WIDTH_SPACE = String.fromCharCode(0x200b);
const LINE_SEPARATOR = String.fromCharCode(0x2028);
const COMBINING_ACUTE = String.fromCharCode(0x301);

describe('identifierProblem (wie DeviceIdentifier)', () => {
    it.each(['M1', 'PA 3', 'HLF-20/G1.4', 'Größe 2', 'Ünal'])('„%s“ ist gültig', (value) => {
        expect(identifierProblem(value)).toBeNull();
    });

    it('Leerraum am Rand und doppelte Leerzeichen bereinigt der Server', () => {
        expect(identifierProblem('  PA   3 ')).toBeNull();
    });

    it('zerlegte Zeichen gelten in zusammengesetzter Form', () => {
        expect(identifierProblem(`Me${COMBINING_ACUTE}`)).toBeNull();
    });

    it('Fehler mit verständlicher Meldung', () => {
        expect(identifierProblem('   ')).toBe('Bitte eine Kennung eingeben.');
        expect(identifierProblem('A'.repeat(31))).toBe('Höchstens 30 Zeichen (jetzt 31).');
        expect(identifierProblem('A'.repeat(30))).toBeNull();
        expect(identifierProblem('M_1')).toMatch(/^Nur Buchstaben, Ziffern/);
        expect(identifierProblem('M#1')).toMatch(/^Nur Buchstaben/);
        // Ein kombinierendes Zeichen ohne Grundbuchstaben bleibt auch zusammengesetzt einzeln.
        expect(identifierProblem(`M1${COMBINING_ACUTE}`)).toBe(
            'Bitte den Buchstaben direkt eingeben, nicht den Akzent einzeln.',
        );
    });
});

describe('Texte (wie DeviceText)', () => {
    it('leer = null, Rand wie PHP trim()', () => {
        expect(textValue('  ')).toBeNull();
        expect(textValue(' SN 1 ')).toBe('SN 1');
        expect(textValue('a\r\nb\rc', true)).toBe('a\nb\nc');
    });

    it('Länge in Unicode-Zeichen', () => {
        expect(textProblem('😀'.repeat(100), 100)).toBeNull();
        expect(textProblem('x'.repeat(101), 100)).toBe('Höchstens 100 Zeichen (jetzt 101).');
    });

    it('Steuerzeichen; nur die Notiz darf Zeilenumbrüche enthalten', () => {
        expect(textProblem(`a${TAB}b`, 100)).toBe('Keine Zeilenumbrüche oder Steuerzeichen.');
        expect(textProblem('a\nb', 1000, true)).toBeNull();
        expect(textProblem(`a${TAB}b`, 1000, true)).toMatch(/^Außer Zeilenumbrüchen/);
    });
});

describe('Datum', () => {
    it('JJJJ-MM-TT und TT.MM.JJJJ', () => {
        expect(parseDateInput('')).toBeNull();
        expect(parseDateInput('2026-12-01')).toBe('2026-12-01');
        expect(parseDateInput('1.2.2027')).toBe('2027-02-01');
        expect(parseDateInput('29.02.2027')).toBeUndefined();
        expect(parseDateInput('2026-13-01')).toBeUndefined();
        expect(parseDateInput('01.12.26')).toBeUndefined();
    });

    it('zwischen 1950 und 2099 wie DeviceDate', () => {
        expect(dateProblem('')).toBeNull();
        expect(dateProblem('1950-01-01')).toBeNull();
        expect(dateProblem('2099-12-31')).toBeNull();
        expect(dateProblem('1949-12-31')).toBe('Das Datum muss zwischen 1950 und 2099 liegen.');
        expect(dateProblem('0202-01-01')).toBe('Das Datum muss zwischen 1950 und 2099 liegen.');
        expect(dateProblem('31.11.2026')).toMatch(/^Bitte ein gültiges Datum/);
    });
});

describe('scanCodeProblem (wie ScanCode)', () => {
    it('GS1 mit Trennzeichen und Leerzeichen im Inhalt sind gültig', () => {
        expect(scanCodeProblem(`0104006381333931 10ABC${GS}21SN7\r\n`)).toBeNull();
    });

    it('Fehler', () => {
        expect(scanCodeProblem(' \r\n')).toBe('Bitte einen Code scannen oder eingeben.');
        expect(scanCodeProblem('x'.repeat(201))).toBe('Höchstens 200 Zeichen (jetzt 201).');
        expect(scanCodeProblem(`AB${TAB}CD`)).toMatch(/^Keine Steuerzeichen/);
        expect(scanCodeProblem(`AB\nCD`)).toMatch(/^Keine Steuerzeichen/);
        expect(scanCodeProblem(`AB${ZERO_WIDTH_SPACE}CD`)).toMatch(/Formatzeichen/);
        expect(scanCodeProblem(`AB${LINE_SEPARATOR}CD`)).toMatch(/Formatzeichen/);
    });
});

describe('Bearbeiten', () => {
    const device = detail(5, {
        identifier: 'M5',
        serial_number: 'SN-5',
        manufacturer: 'Dräger',
        procured_on: '2020-03-01',
        note: 'alt',
        version: 3,
    });

    it('ohne Änderung nichts zu senden', () => {
        const form = editFormFromDevice(device);
        expect(form).toEqual({
            identifier: 'M5',
            deviceTypeId: 1,
            serialNumber: 'SN-5',
            manufacturer: 'Dräger',
            model: '',
            procuredOn: '2020-03-01',
            note: 'alt',
        });
        expect(editFormProblems(form)).toEqual({});
        expect(editFormChanges(device, form)).toEqual({});
        // Nur Leerraum am Rand ist keine Änderung.
        expect(editFormChanges(device, { ...form, serialNumber: ' SN-5 ' })).toEqual({});
    });

    it('nur geänderte Felder; leer = null; Schreibweise der Kennung zählt', () => {
        const form = {
            ...editFormFromDevice(device),
            identifier: 'm5',
            deviceTypeId: 2,
            manufacturer: '  ',
            model: 'FPS 7000',
            procuredOn: '',
            note: 'neu\r\nzweite Zeile',
        };
        expect(editFormChanges(device, form)).toEqual({
            identifier: 'm5',
            deviceTypeId: 2,
            manufacturer: null,
            model: 'FPS 7000',
            procuredOn: null,
            note: 'neu\nzweite Zeile',
        });
    });

    it('Probleme je Feld', () => {
        const form = {
            ...editFormFromDevice(device),
            identifier: '',
            model: 'x'.repeat(101),
            procuredOn: '1900-01-01',
        };
        expect(Object.keys(editFormProblems(form))).toEqual(['identifier', 'model', 'procuredOn']);
    });

    it('Konflikt: eigene Änderungen bleiben, der Rest folgt dem neuen Stand', () => {
        const form = { ...editFormFromDevice(device), model: 'FPS 7000' };
        const latest = detail(5, {
            identifier: 'M5',
            serial_number: 'SN-9',
            manufacturer: 'MSA',
            model: 'G1',
            procured_on: '2020-03-01',
            note: 'alt',
            version: 4,
        });
        expect(rebaseEditForm(form, device, latest)).toEqual({
            ...editFormFromDevice(latest),
            model: 'FPS 7000',
        });
    });
});

describe('Wieder aufnehmen', () => {
    const device = detail(5, {
        status: 'retired',
        status_label: 'ausgesondert',
        scan_code: 'ABC',
        version: 7,
    });

    it('Vorschlag: einsatzbereit an bisherigem Standort, nur der Status wird gesendet', () => {
        const form = reinstateFormFromDevice(device);
        expect(reinstateRequest(device, form, '')).toEqual({ version: 7, status: 'ready' });
    });

    it('geänderte Angaben, Scan-Code entfernen, Kommentar', () => {
        const form = {
            ...reinstateFormFromDevice(device),
            status: 'maintenance' as const,
            locationId: 20,
            identifier: ' M 5 ',
            deviceTypeId: 2,
            keepScanCode: false,
        };
        expect(reinstateRequest(device, form, ' aus dem Lager\n')).toEqual({
            version: 7,
            status: 'maintenance',
            locationId: 20,
            identifier: 'M 5',
            deviceTypeId: 2,
            scanCode: null,
            comment: 'aus dem Lager',
        });
    });

    it('ohne Scan-Code nichts zu entfernen', () => {
        const without = detail(6, { status: 'retired', status_label: 'ausgesondert' });
        const form = { ...reinstateFormFromDevice(without), keepScanCode: false };
        expect(reinstateRequest(without, form, '')).toEqual({ version: 1, status: 'ready' });
    });
});
