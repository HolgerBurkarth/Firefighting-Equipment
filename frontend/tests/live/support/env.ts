import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

/** Zugangsdaten eines Kontos. Das Passwort steht nie in Ausgaben, Fehlermeldungen oder Protokollen. */
export interface LiveAccount {
    email: string;
    password: string;
}

export interface LiveAccounts {
    user1: LiveAccount;
    user2: LiveAccount;
}

export const ENV_KEYS = [
    'FF_LIVE_USER1_EMAIL',
    'FF_LIVE_USER1_PASSWORD',
    'FF_LIVE_USER2_EMAIL',
    'FF_LIVE_USER2_PASSWORD',
] as const;

/** Die lokale, nicht versionierte Datei (liegt neben diesem Ordner `support/`). */
export const ENV_FILE = join(dirname(fileURLToPath(import.meta.url)), '..', '.env.local');

export class LiveEnvError extends Error {}

/**
 * Liest `KEY=VALUE`-Zeilen (Kommentare mit `#`, optionale Anführungszeichen um den Wert). Nur die
 * erlaubten Schlüssel zählen. Die Fehlermeldungen nennen Schlüssel, nie Werte.
 */
export function parseEnv(text: string): Partial<Record<(typeof ENV_KEYS)[number], string>> {
    const values: Partial<Record<(typeof ENV_KEYS)[number], string>> = {};
    for (const raw of text.split(/\r?\n/)) {
        const line = raw.trim();
        if (line === '' || line.startsWith('#')) {
            continue;
        }
        const at = line.indexOf('=');
        if (at < 1) {
            continue;
        }
        const key = line.slice(0, at).trim();
        let value = line.slice(at + 1).trim();
        if (value.length >= 2 && /^(["']).*\1$/.test(value)) {
            value = value.slice(1, -1);
        }
        const known = ENV_KEYS.find((candidate) => candidate === key);
        if (known) {
            values[known] = value;
        }
    }
    return values;
}

/**
 * Prüft die Datei VOR dem ersten Anmelden vollständig, damit ein Tippfehler nicht die Bremse der
 * Anmeldung auslöst: alle vier Werte da, Adressen mit einem @, zwei verschiedene Konten.
 */
export function accountsFromText(text: string): LiveAccounts {
    const values = parseEnv(text);
    const missing = ENV_KEYS.filter((key) => (values[key] ?? '') === '');
    if (missing.length > 0) {
        throw new LiveEnvError(`In ${ENV_FILE} fehlen Werte: ${missing.join(', ')}.`);
    }
    const user1 = {
        email: values.FF_LIVE_USER1_EMAIL ?? '',
        password: values.FF_LIVE_USER1_PASSWORD ?? '',
    };
    const user2 = {
        email: values.FF_LIVE_USER2_EMAIL ?? '',
        password: values.FF_LIVE_USER2_PASSWORD ?? '',
    };
    for (const [key, account] of [
        ['FF_LIVE_USER1_EMAIL', user1],
        ['FF_LIVE_USER2_EMAIL', user2],
    ] as const) {
        if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(account.email)) {
            throw new LiveEnvError(`${key} ist keine E-Mail-Adresse.`);
        }
    }
    if (user1.email.toLowerCase() === user2.email.toLowerCase()) {
        throw new LiveEnvError('Konto 1 und Konto 2 müssen verschiedene Adressen haben.');
    }
    return { user1, user2 };
}

export function loadLiveAccounts(): LiveAccounts {
    if (!existsSync(ENV_FILE)) {
        throw new LiveEnvError(
            `Die Datei ${ENV_FILE} fehlt. Vorlage: tests/live/.env.local.example ` +
                `(kopieren, die vier Werte eintragen; die Datei wird nie committet).`,
        );
    }
    return accountsFromText(readFileSync(ENV_FILE, 'utf8'));
}
