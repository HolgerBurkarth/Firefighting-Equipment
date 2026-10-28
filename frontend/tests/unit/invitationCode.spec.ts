// @vitest-environment node
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import {
    ALPHABET,
    CODE_LENGTH,
    formatInvitationCode,
    MAX_INPUT_BYTES,
    normalizeInvitationCode,
} from '../../src/auth/invitationCode';

const ROOT = new URL('../../../', import.meta.url);

interface SharedCase {
    readonly description: string;
    readonly input: string;
    readonly server: string | null;
}

const shared = JSON.parse(
    readFileSync(new URL('tests/Fixtures/invitation-code-cases.json', ROOT), 'utf8'),
) as { cases: SharedCase[] };

const valuePhp = readFileSync(new URL('src/Invitation/InvitationCodeValue.php', ROOT), 'utf8');

describe('gemeinsame Testfälle mit InvitationCodeValue (nie strenger als der Server)', () => {
    it.each(shared.cases.map((c) => [c.description, c] as const))('%s', (_, c) => {
        const result = normalizeInvitationCode(c.input);
        if (c.server !== null) {
            // Was der Server annimmt, nimmt das Frontend genauso an.
            expect(result).toBe(c.server);
        } else {
            // Was der Server ablehnt, lehnt das Frontend ebenso ab (Meldung gleich am Feld).
            expect(result).toBeNull();
        }
    });
});

describe('Konstanten wie im Backend', () => {
    it('ALPHABET, LENGTH, MAX_INPUT_LENGTH', () => {
        expect(valuePhp).toContain(`public const string ALPHABET = '${ALPHABET}';`);
        expect(valuePhp).toContain(`public const int LENGTH = ${String(CODE_LENGTH)};`);
        expect(valuePhp).toContain(
            `private const int MAX_INPUT_LENGTH = ${String(MAX_INPUT_BYTES)};`,
        );
    });
});

describe('formatInvitationCode', () => {
    it('Anzeige als XXXX-XXXX-XXXX', () => {
        expect(formatInvitationCode('ABCDEFGHJKMN')).toBe('ABCD-EFGH-JKMN');
    });
});
