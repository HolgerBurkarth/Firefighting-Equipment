// @vitest-environment node
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import {
    checkPassword,
    fold,
    MAX_LENGTH,
    MIN_FRAGMENT_LENGTH,
    MIN_LENGTH,
    passwordChecks,
    passwordLength,
    personalFragments,
    TENANT_STOPWORDS,
    type PasswordContext,
} from '../../src/auth/passwordRules';

const ROOT = new URL('../../../', import.meta.url);

interface SharedCase {
    readonly description: string;
    readonly password: string;
    readonly email: string | null;
    readonly names: readonly string[];
    readonly tenant: { readonly slug: string; readonly name: string } | null;
    readonly server: 'ok' | 'too_short' | 'too_long' | 'control' | 'blocklist' | 'personal';
}

const shared = JSON.parse(
    readFileSync(new URL('tests/Fixtures/password-policy-cases.json', ROOT), 'utf8'),
) as { cases: SharedCase[] };

const policyPhp = readFileSync(new URL('src/User/Password/PasswordPolicy.php', ROOT), 'utf8');

function phpInt(name: string): number {
    const match = new RegExp(`const int ${name} = (\\d+);`).exec(policyPhp);
    if (match?.[1] === undefined) {
        throw new Error(`${name} nicht gefunden`);
    }
    return Number(match[1]);
}

describe('gemeinsame Testfälle mit PasswordPolicy (Backend)', () => {
    it('Datei enthält Fälle', () => {
        expect(shared.cases.length).toBeGreaterThan(30);
    });

    // Die Sperrliste prüft nur der Server: dort muss das Frontend "ok" liefern, sonst dasselbe.
    it.each(shared.cases.map((c) => [c.description, c] as const))('%s', (_label, c) => {
        const context: PasswordContext = { email: c.email, names: c.names, tenant: c.tenant };
        const expected = c.server === 'blocklist' ? 'ok' : c.server;
        expect(checkPassword(c.password, context)).toBe(expected);
    });
});

describe('Konstanten wie im Backend', () => {
    it('Längen', () => {
        expect(MIN_LENGTH).toBe(phpInt('MIN_LENGTH'));
        expect(MAX_LENGTH).toBe(phpInt('MAX_LENGTH'));
        expect(MIN_FRAGMENT_LENGTH).toBe(phpInt('MIN_FRAGMENT_LENGTH'));
    });

    it('Stoppwörter', () => {
        const block = /TENANT_STOPWORDS = \[([^\]]*)\]/.exec(policyPhp)?.[1] ?? '';
        const php = [...block.matchAll(/'([^']+)'/g)].map((m) => m[1]);
        expect(php.length).toBeGreaterThan(10);
        expect([...TENANT_STOPWORDS]).toEqual(php);
    });
});

describe('Einzelheiten', () => {
    it('zählt Unicode-Zeichen wie mb_strlen', () => {
        expect(passwordLength('🚒🚒')).toBe(2);
        expect(passwordLength('ä')).toBe(2);
    });

    it('Vergleichsform', () => {
        expect(fold('STRAUẞ Straße')).toBe('strauss strasse');
        expect(fold('Möller')).toBe('möller');
        expect(fold('René')).toBe('rené');
    });

    it('Teile persönlicher Daten', () => {
        expect(
            personalFragments({
                email: 'Anna.Beispiel@example.org',
                names: ['Anna Beispiel', 'Udo'],
                tenant: { slug: 'ff-tuttlingen', name: 'Freiwillige Feuerwehr Stadt Tuttlingen' },
            }),
        ).toEqual(['anna.beispiel', 'anna', 'beispiel', 'ff-tuttlingen', 'tuttlingen']);
    });

    it('unvollständige E-Mail wird nicht geprüft', () => {
        expect(personalFragments({ email: 'anna.beispiel', names: [], tenant: null })).toEqual([]);
    });

    it('einzelne Regeln für die Anzeige', () => {
        const context: PasswordContext = { email: null, names: ['Anna Beispiel'], tenant: null };
        expect(passwordChecks('Anna\t', context)).toEqual({
            length: 'too_short',
            control: true,
            personal: true,
        });
        expect(passwordChecks('Kxq7-Lmw2!', context)).toEqual({
            length: 'ok',
            control: false,
            personal: false,
        });
    });
});
