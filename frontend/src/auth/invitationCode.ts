/**
 * Einladungscode wie InvitationCodeValue::fromInput im Backend: 12 Zeichen aus ALPHABET (ohne
 * 0/O/1/I/L), Groß-/Kleinschreibung, Leerraum und Bindestriche zählen nicht. Absenden erst mit
 * gültigem Code; daher nie strenger als der Server (gemeinsame Testfälle
 * tests/Fixtures/invitation-code-cases.json, PHPUnit und Vitest).
 */

export const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
export const CODE_LENGTH = 12;
/** Wie InvitationCodeValue::MAX_INPUT_LENGTH (Bytes in UTF-8, wie strlen). */
export const MAX_INPUT_BYTES = 64;

const VALID = new RegExp(`^[${ALPHABET}]{${String(CODE_LENGTH)}}$`);

/** Normalisierte Form (12 Zeichen) oder null, wenn die Eingabe kein gültiger Code ist. */
export function normalizeInvitationCode(input: string): string | null {
    if (new TextEncoder().encode(input).length > MAX_INPUT_BYTES) {
        return null;
    }
    // Leerraum wie \s in PHP mit /u (Unicode-Kategorie Z, Tab, LF, VT, FF, CR, NEL); \s in JavaScript
    // zählt zusätzlich U+FEFF. Groß nur ASCII wie strtoupper; andere Zeichen scheitern an der Prüfung.
    const value = input
        .replace(/[\p{Z}\t\n\v\f\r\u0085-]+/gu, '')
        .replace(/[a-z]/g, (letter) => letter.toUpperCase());
    return VALID.test(value) ? value : null;
}

/** Anzeige als XXXX-XXXX-XXXX (nur für normalisierte Codes). */
export function formatInvitationCode(normalized: string): string {
    return normalized.match(/.{1,4}/g)?.join('-') ?? normalized;
}
