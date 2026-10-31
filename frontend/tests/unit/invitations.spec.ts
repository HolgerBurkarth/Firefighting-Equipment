// @vitest-environment node
import { readFileSync } from 'node:fs';
import { describe, expect, it, vi } from 'vitest';
import { prepareZXingModule, readBarcodes } from 'zxing-wasm/reader';
import { parseInvitationCode } from '../../src/api/invitationCodes';
import { QR_QUIET_ZONE, qrPath, qrShape } from '../../src/components/qrCode';
import {
    DEFAULT_MAX_USES,
    DEFAULT_VALID_DAYS,
    invitationProblems,
    invitationRequest,
    LABEL_MAX_LENGTH,
    MAX_USES,
    MAX_VALID_DAYS,
    newInvitationForm,
    registerAddress,
    registerLink,
    shareContent,
    usesText,
    visibleCodes,
} from '../../src/data/invitations';
import { copyText, shareLink, type ShareNavigator } from '../../src/runtime/share';

const ROOT = new URL('../../../', import.meta.url);
const BASE = 'https://www.prodad.de/firefighting/test/';

function phpInt(file: string, name: string): number {
    const php = readFileSync(new URL(file, ROOT), 'utf8');
    const match = new RegExp(`const int ${name} = (\\d+);`).exec(php);
    if (match?.[1] === undefined) {
        throw new Error(`${name} nicht gefunden`);
    }
    return Number(match[1]);
}

/** Wie InvitationJson::code. */
function codeJson(overrides: Record<string, unknown> = {}): Record<string, unknown> {
    return {
        id: 3,
        label: 'Dienstabend',
        status: 'active',
        status_label: 'aktiv',
        use_count: 2,
        max_uses: 50,
        expires_at: '2026-11-30T13:05:00Z',
        created_at: '2026-10-31T13:05:00Z',
        revoked_at: null,
        created_by: { id: 7, label: 'Thomas Keller' },
        ...overrides,
    };
}

describe('Grenzen wie im Backend', () => {
    it('Gültigkeit, Nutzungen und Bezeichnung', () => {
        const repository = 'src/Invitation/InvitationCodeRepository.php';
        expect(MAX_VALID_DAYS).toBe(phpInt(repository, 'MAX_VALID_DAYS'));
        expect(MAX_USES).toBe(phpInt(repository, 'MAX_USES'));
        expect(LABEL_MAX_LENGTH).toBe(
            phpInt('src/Invitation/InvitationCodeLabel.php', 'MAX_LENGTH'),
        );
        expect(DEFAULT_VALID_DAYS).toBeLessThanOrEqual(MAX_VALID_DAYS);
        expect(DEFAULT_MAX_USES).toBeLessThanOrEqual(MAX_USES);
    });
});

describe('Fenster „Neuer Einladungscode“', () => {
    it('Vorbelegung ist gültig, leere Bezeichnung = keine', () => {
        expect(invitationProblems(newInvitationForm())).toEqual({});
        expect(invitationRequest(newInvitationForm())).toEqual({
            label: null,
            validDays: DEFAULT_VALID_DAYS,
            maxUses: DEFAULT_MAX_USES,
        });
        expect(
            invitationRequest({ label: '  Dienstabend \t', validDays: ' 7 ', maxUses: '1' }),
        ).toEqual({ label: 'Dienstabend', validDays: 7, maxUses: 1 });
    });

    it.each([
        [{ validDays: '0' }, 'validDays'],
        [{ validDays: '91' }, 'validDays'],
        [{ validDays: '1.5' }, 'validDays'],
        [{ validDays: '-1' }, 'validDays'],
        [{ validDays: '' }, 'validDays'],
        [{ maxUses: '501' }, 'maxUses'],
        [{ maxUses: 'zehn' }, 'maxUses'],
        [{ label: 'a\u0007b' }, 'label'],
        [{ label: 'ä'.repeat(LABEL_MAX_LENGTH + 1) }, 'label'],
    ])('Problem bei %j', (change, field) => {
        const form = { ...newInvitationForm(), ...change };
        expect(Object.keys(invitationProblems(form))).toEqual([field]);
        expect(invitationRequest(form)).toBeNull();
    });

    it('Grenzen sind erlaubt', () => {
        const form = {
            label: 'ä'.repeat(LABEL_MAX_LENGTH),
            validDays: String(MAX_VALID_DAYS),
            maxUses: String(MAX_USES),
        };
        expect(invitationProblems(form)).toEqual({});
    });
});

describe('Link, Texte und Liste', () => {
    it('Link mit dem Code im Fragment, Adresse ohne Schema und Code', () => {
        expect(registerLink('ABCD-EFGH-JKMN', BASE)).toBe(
            'https://www.prodad.de/firefighting/test/registrieren#code=ABCD-EFGH-JKMN',
        );
        expect(registerAddress(BASE)).toBe('www.prodad.de/firefighting/test/registrieren');
        const content = shareContent(
            'Feuerwehr Test',
            'ABCD-EFGH-JKMN',
            codeJson()['expires_at'] as string,
            BASE,
        );
        expect(content.url).toBe(registerLink('ABCD-EFGH-JKMN', BASE));
        expect(content.title).toBe('Registrierung Feuerwehr Test');
        expect(content.text).toContain('Einladungscode ABCD-EFGH-JKMN');
    });

    it('Nutzungen und sichtbare Codes', () => {
        const active = parseInvitationCode(codeJson());
        const expired = parseInvitationCode(codeJson({ id: 2, status: 'expired' }));
        if (active === null || expired === null) {
            throw new Error('ungültig');
        }
        expect(usesText(active)).toBe('2 von 50 Registrierungen');
        expect(visibleCodes([active, expired], false)).toEqual([active]);
        expect(visibleCodes([active, expired], true)).toEqual([active, expired]);
    });
});

describe('Teilen und Kopieren', () => {
    const DATA = { title: 'T', text: 'Text', url: 'https://example.org/x#code=A' };

    function fakeNav(share?: ShareNavigator['share'], writeText?: (text: string) => Promise<void>) {
        const copied: string[] = [];
        const nav: ShareNavigator = {
            ...(share !== undefined && { share }),
            clipboard: {
                writeText:
                    writeText ??
                    ((text) => {
                        copied.push(text);
                        return Promise.resolve();
                    }),
            },
        };
        return { nav, copied };
    }

    it('teilt über die Web Share API', async () => {
        const share = vi.fn<NonNullable<ShareNavigator['share']>>(() => Promise.resolve());
        const { nav, copied } = fakeNav(share);
        expect(await shareLink(DATA, nav)).toBe('shared');
        expect(share).toHaveBeenCalledWith(DATA);
        expect(copied).toEqual([]);
    });

    it('Abbrechen tut nichts weiter, andere Fehler kopieren den Link', async () => {
        const aborted = fakeNav(() => Promise.reject(new DOMException('x', 'AbortError')));
        expect(await shareLink(DATA, aborted.nav)).toBe('cancelled');
        expect(aborted.copied).toEqual([]);
        const denied = fakeNav(() => Promise.reject(new DOMException('x', 'NotAllowedError')));
        expect(await shareLink(DATA, denied.nav)).toBe('copied');
        expect(denied.copied).toEqual([DATA.url]);
    });

    it('ohne Web Share API kopiert, ohne Zwischenablage nicht möglich', async () => {
        const { nav, copied } = fakeNav();
        expect(await shareLink(DATA, nav)).toBe('copied');
        expect(copied).toEqual([DATA.url]);
        const failing = fakeNav(undefined, () => Promise.reject(new Error('verboten')));
        expect(await shareLink(DATA, failing.nav)).toBe('failed');
        expect(await copyText('x', {})).toBe(false);
    });

    it('canShare false: kopieren', async () => {
        const share = vi.fn<NonNullable<ShareNavigator['share']>>(() => Promise.resolve());
        const { nav, copied } = fakeNav(share);
        expect(await shareLink(DATA, { ...nav, canShare: () => false })).toBe('copied');
        expect(share).not.toHaveBeenCalled();
        expect(copied).toEqual([DATA.url]);
    });
});

describe('QR-Code', () => {
    it('ein Rechteck je Lauf dunkler Module', () => {
        expect(
            qrPath([
                [true, true, false, true],
                [false, false, false, false],
                [false, true, true, true],
            ]),
        ).toBe('M0 0h2v1h-2zM3 0h1v1h-1zM1 2h3v1h-3z');
    });

    it('der gezeichnete Code enthält genau den Link (gelesen mit zxing-wasm)', async () => {
        const file = readFileSync(
            new URL('../../node_modules/zxing-wasm/dist/reader/zxing_reader.wasm', import.meta.url),
        );
        await prepareZXingModule({
            overrides: {
                wasmBinary: file.buffer.slice(file.byteOffset, file.byteOffset + file.byteLength),
            },
            fireImmediately: true,
        });
        const link = registerLink(
            'ABCD-EFGH-JKMN',
            'https://www.prodad.de/firefighting/feuerwehr-musterstadt-west/',
        );
        const { size, path } = qrShape(link);
        // Pfad wie im SVG rastern: 4 Pixel je Modul, Ruhezone hell.
        const scale = 4;
        const edge = (size + 2 * QR_QUIET_ZONE) * scale;
        const data = new Uint8ClampedArray(edge * edge * 4).fill(255);
        for (const match of path.matchAll(/M(\d+) (\d+)h(\d+)v1h-\d+z/g)) {
            const [x0, y0, width] = [Number(match[1]), Number(match[2]), Number(match[3])];
            for (let y = 0; y < scale; y += 1) {
                for (let x = 0; x < width * scale; x += 1) {
                    const px = (x0 + QR_QUIET_ZONE) * scale + x;
                    const py = (y0 + QR_QUIET_ZONE) * scale + y;
                    data.fill(0, (py * edge + px) * 4, (py * edge + px) * 4 + 3);
                }
            }
        }
        const results = await readBarcodes(
            { data, width: edge, height: edge, colorSpace: 'srgb' },
            { formats: ['QRCode'] },
        );
        expect(results.map((result) => result.text)).toEqual([link]);
    });
});
