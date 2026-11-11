import { describe, expect, it } from 'vitest';
import { accountsFromText, LiveEnvError, parseEnv } from '../live/support/env';
import { deviceIdsOfRequest, IDENTIFIER, isAllowedUrl } from '../live/support/guard';
import type { LiveDevice } from '../live/support/browser';
import { pickDevices } from '../live/support/pick';
import { ResultList } from '../live/support/results';

const FULL = [
    '# Kommentar',
    'FF_LIVE_USER1_EMAIL=eins@example.org',
    'FF_LIVE_USER1_PASSWORD="geheim eins"',
    'FF_LIVE_USER2_EMAIL=zwei@example.org',
    'FF_LIVE_USER2_PASSWORD=geheim-zwei',
    'FREMD=egal',
].join('\r\n');

describe('Live-Lauf: Zugangsdaten (nur das Skript liest die Datei)', () => {
    it('liest nur die vier Schlüssel, Anführungszeichen und CRLF', () => {
        expect(parseEnv(FULL)).toEqual({
            FF_LIVE_USER1_EMAIL: 'eins@example.org',
            FF_LIVE_USER1_PASSWORD: 'geheim eins',
            FF_LIVE_USER2_EMAIL: 'zwei@example.org',
            FF_LIVE_USER2_PASSWORD: 'geheim-zwei',
        });
        expect(accountsFromText(FULL).user2.password).toBe('geheim-zwei');
    });

    it('bricht vor dem Anmelden ab, wenn etwas fehlt oder falsch ist – ohne Werte zu nennen', () => {
        const without = FULL.replace(
            'FF_LIVE_USER2_PASSWORD=geheim-zwei',
            'FF_LIVE_USER2_PASSWORD=',
        );
        expect(() => accountsFromText(without)).toThrow(/FF_LIVE_USER2_PASSWORD/);
        expect(() => accountsFromText(FULL.replace('eins@example.org', 'keine-adresse'))).toThrow(
            LiveEnvError,
        );
        expect(() =>
            accountsFromText(FULL.replace('zwei@example.org', 'EINS@example.org')),
        ).toThrow(/verschiedene/);
        for (const text of [without, FULL.replace('eins@example.org', 'keine-adresse')]) {
            try {
                accountsFromText(text);
            } catch (error) {
                expect((error as Error).message).not.toMatch(/geheim/);
            }
        }
    });
});

describe('Live-Lauf: Schutz', () => {
    it('erlaubt nur die Test-Stadt', () => {
        expect(isAllowedUrl('https://www.prodad.de/firefighting/test/suchen')).toBe(true);
        expect(isAllowedUrl('https://www.prodad.de/firefighting/test/api/v1/devices')).toBe(true);
        expect(isAllowedUrl('https://www.prodad.de/firefighting/immendingen/suchen')).toBe(false);
        expect(isAllowedUrl('https://www.prodad.de/firefighting/testing/suchen')).toBe(false);
        expect(isAllowedUrl('https://www.prodad.de/firefighting/service/')).toBe(false);
        expect(isAllowedUrl('http://www.prodad.de/firefighting/test/suchen')).toBe(false);
        expect(isAllowedUrl('https://example.org/firefighting/test/')).toBe(false);
    });

    it('nur Ausrüstung M90–M99', () => {
        for (const id of ['M90', 'M99']) {
            expect(IDENTIFIER.test(id)).toBe(true);
        }
        for (const id of ['M9', 'M100', 'M89', 'M900', 'm90', 'PA 90']) {
            expect(IDENTIFIER.test(id)).toBe(false);
        }
    });

    it('findet die Ausrüstung einer ändernden Anfrage', () => {
        const base = '/firefighting/test/api/v1/';
        expect(deviceIdsOfRequest('GET', `${base}devices/5`, null)).toBeNull();
        expect(deviceIdsOfRequest('POST', `${base}auth/login`, {})).toBeNull();
        expect(deviceIdsOfRequest('POST', `${base}devices/5/state`, {})).toEqual([5]);
        expect(deviceIdsOfRequest('POST', `${base}devices/5/used`, {})).toEqual([5]);
        expect(
            deviceIdsOfRequest('POST', `${base}devices/swap`, { out: { id: 3 }, in: { id: 4 } }),
        ).toEqual([3, 4]);
        // Unklares und Sammelaktionen: nie erlaubt (leere Liste = blockiert).
        expect(deviceIdsOfRequest('POST', `${base}devices/swap`, {})).toEqual([]);
        expect(deviceIdsOfRequest('POST', `${base}devices/bulk/state`, {})).toEqual([]);
        expect(deviceIdsOfRequest('POST', `${base}devices/import`, {})).toEqual([]);
    });
});

function device(id: number, typeId: number, locationId: number, status = 'ready'): LiveDevice {
    return {
        id,
        identifier: `M${String(id)}`,
        version: 1,
        status,
        location: {
            id: locationId,
            name: `L${String(locationId)}`,
            path: `L${String(locationId)}`,
        },
        device_type: { id: typeId, name: `T${String(typeId)}` },
    };
}

describe('Live-Lauf: Auswahl der Ausrüstung', () => {
    it('zwei Paare gleichen Typs an verschiedenen Standorten, dazu zwei einzelne, nie doppelt', () => {
        const all = [
            device(90, 1, 10),
            device(91, 1, 11),
            device(92, 2, 10),
            device(93, 2, 12),
            device(94, 3, 10),
            device(95, 3, 10),
            device(96, 4, 10),
            device(100, 1, 10),
        ];
        const pick = pickDevices(all, new Set(all.map((d) => d.id)));
        expect([pick.swapOut?.id, pick.swapIn?.id]).toEqual([90, 91]);
        expect([pick.lateOut?.id, pick.lateIn?.id]).toEqual([92, 93]);
        expect([pick.booking?.id, pick.used?.id]).toEqual([94, 95]);
        const ids = Object.values(pick).map((d) => d?.id);
        expect(new Set(ids).size).toBe(ids.length);
    });

    it('lässt Unsichtbares, Nicht-Einsatzbereites, Einmalgeräte und andere Nummern aus', () => {
        const single = device(92, 1, 12);
        single.device_type.single_use = true;
        const all = [device(90, 1, 10), device(91, 1, 11, 'defective'), single, device(150, 1, 13)];
        const pick = pickDevices(all, new Set([90, 92, 150]));
        expect(pick.swapOut).toBeNull();
        expect(pick.booking?.id).toBe(90);
        expect(pick.used).toBeNull();
    });
});

describe('Live-Lauf: Ergebnisliste', () => {
    it('☑, ☒ mit erster Fehlerzeile ohne Farbcodes, ☐ mit Grund', async () => {
        const list = new ResultList();
        await list.step('A', 'geht', () => Promise.resolve());
        await list.step('B', 'geht nicht', () =>
            Promise.reject(new Error('\u001b[31mfalsch\u001b[39m\nzweite Zeile')),
        );
        list.skip('C', 'fehlt', 'keine Ausrüstung');
        expect(list.render()).toBe(
            '☑ A geht\n☒ B geht nicht – falsch\n☐ C fehlt – keine Ausrüstung',
        );
        expect(list.failed).toBe(1);
    });
});
