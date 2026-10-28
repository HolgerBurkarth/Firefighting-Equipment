/**
 * Live-Prüfung neuer Passwörter wie PasswordPolicy im Backend, OHNE die Sperrliste (die prüft nur
 * der Server, 422 weak_password). Absenden ist erst nach bestandener Prüfung möglich; deshalb darf
 * sie nie strenger sein als der Server. Belegt durch die gemeinsamen Testfälle
 * tests/Fixtures/password-policy-cases.json (PHPUnit und Vitest) und den Abgleich der Konstanten.
 */

export const MIN_LENGTH = 10;
export const MAX_LENGTH = 128;
export const MIN_FRAGMENT_LENGTH = 4;

/** Wie PasswordPolicy::TENANT_STOPWORDS (in der Vergleichsform). */
export const TENANT_STOPWORDS: readonly string[] = [
    'feuerwehr',
    'feuerwehren',
    'freiwillige',
    'freiwilligen',
    'berufsfeuerwehr',
    'werkfeuerwehr',
    'jugendfeuerwehr',
    'ortsfeuerwehr',
    'ortswehr',
    'stadt',
    'gemeinde',
    'markt',
    'abteilung',
    'löschzug',
    'loeschzug',
    'löschgruppe',
    'loeschgruppe',
    'löschbezirk',
    'loeschbezirk',
    'feuerwache',
    'wache',
    'ff',
    'ffw',
];

export type PasswordResult = 'ok' | 'too_short' | 'too_long' | 'control' | 'personal';

/** Was die Seite über das Konto weiß (unbekanntes weglassen; der Server prüft es ohnehin). */
export interface PasswordContext {
    readonly email: string | null;
    readonly names: readonly string[];
    readonly tenant: { readonly slug: string; readonly name: string } | null;
}

/** Nur zerlegte ä/ö/ü (wie das Backend ohne intl); keine allgemeine NFC-Normalisierung. */
const DECOMPOSED_UMLAUTS: Readonly<Record<string, string>> = {
    ä: 'ä',
    ö: 'ö',
    ü: 'ü',
    Ä: 'Ä',
    Ö: 'Ö',
    Ü: 'Ü',
};

/**
 * Vergleichsform wie PasswordPolicy::fold: zerlegte Umlaute zusammensetzen, Kleinschreibung,
 * ß (auch aus ẞ) = ss. JavaScript kennt kein volles Case-Folding; dadurch kann die Prüfung hier
 * höchstens weniger finden als der Server, nie mehr.
 */
export function fold(text: string): string {
    return text
        .replace(/[aouAOU]̈/g, (pair) => DECOMPOSED_UMLAUTS[pair] ?? pair)
        .toLowerCase()
        .replaceAll('ß', 'ss');
}

/** Länge in Unicode-Zeichen wie mb_strlen (ein Emoji = 1, nicht 2 UTF-16-Einheiten). */
export function passwordLength(password: string): number {
    return Array.from(password).length;
}

function words(text: string): string[] {
    return text.split(/[^\p{L}\p{N}]+/u).filter((word) => word !== '');
}

/** Teile persönlicher Daten wie PasswordPolicy::personalFragments. */
export function personalFragments(context: PasswordContext): string[] {
    const fragments: string[] = [];
    if (context.email !== null) {
        const email = context.email.trim().toLowerCase();
        const at = email.indexOf('@');
        // Ohne @ ist die Adresse (noch) ungültig; dann nichts prüfen (nie strenger als der Server).
        if (at > 0) {
            const localPart = fold(email.slice(0, at));
            fragments.push(localPart, ...words(localPart));
        }
    }
    for (const name of context.names) {
        fragments.push(...words(fold(name)));
    }
    if (context.tenant !== null) {
        const slug = context.tenant.slug;
        fragments.push(slug);
        const tenantWords = [...words(fold(context.tenant.name)), ...words(slug)];
        fragments.push(...tenantWords.filter((word) => !TENANT_STOPWORDS.includes(word)));
    }
    return [...new Set(fragments.filter((f) => passwordLength(f) >= MIN_FRAGMENT_LENGTH))];
}

export interface PasswordChecks {
    readonly length: 'ok' | 'too_short' | 'too_long';
    readonly control: boolean;
    readonly personal: boolean;
}

/** Einzelne Regeln für die Anzeige unter dem Feld. */
export function passwordChecks(password: string, context: PasswordContext): PasswordChecks {
    const length = passwordLength(password);
    const folded = fold(password);
    return {
        length: length < MIN_LENGTH ? 'too_short' : length > MAX_LENGTH ? 'too_long' : 'ok',
        control: /\p{Cc}/u.test(password),
        personal: personalFragments(context).some((fragment) => folded.includes(fragment)),
    };
}

/** Erste verletzte Regel in derselben Reihenfolge wie das Backend, sonst "ok". */
export function checkPassword(password: string, context: PasswordContext): PasswordResult {
    const checks = passwordChecks(password, context);
    if (checks.length !== 'ok') {
        return checks.length;
    }
    if (checks.control) {
        return 'control';
    }
    return checks.personal ? 'personal' : 'ok';
}
