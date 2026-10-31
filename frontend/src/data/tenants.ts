import type { CreatedTenant, InvitationResult, ManagedTenant } from '../api/tenants';
import type { AdminUser } from '../api/users';
import { searchKey, searchTokens } from './deviceSearch';
import { compareNames, phpTrim } from './masterData';

/** Regeln des Kürzels wie TenantSlug in PHP (per Test gleich). */
export const TENANT_SLUG_PATTERN = /^[a-z0-9]+(-[a-z0-9]+)*$/;
export const TENANT_SLUG_MAX_LENGTH = 50;
export const TENANT_SLUG_RESERVED: readonly string[] = ['service'];
/** Höchstlänge des Namens wie TenantName::MAX_LENGTH. */
export const TENANT_NAME_MAX_LENGTH = 100;
/** Wartezeit nach dem letzten Tastendruck, bevor die App fragt, ob ein Kürzel frei ist. */
export const SLUG_CHECK_DELAY_MS = 300;
/** Höchstlänge der E-Mail wie EmailAddress::MAX_LENGTH. */
export const EMAIL_MAX_LENGTH = 254;

/**
 * Prüft ein Kürzel in derselben Reihenfolge wie TenantSlug::fromString (bestimmt die Meldung):
 * leer → Länge → reserviert → Großbuchstaben (mit Vorschlag) → Format. null = in Ordnung; ob es frei
 * ist, sagt nur der Server.
 */
export function slugProblem(value: string): string | null {
    if (value === '') {
        return 'Bitte ein Kürzel eingeben.';
    }
    const length = Array.from(value).length;
    if (length > TENANT_SLUG_MAX_LENGTH) {
        return `Höchstens ${String(TENANT_SLUG_MAX_LENGTH)} Zeichen (jetzt ${String(length)}).`;
    }
    // Wie PHP strtolower: nur A–Z.
    const lower = value.replace(/[A-Z]/g, (letter) => letter.toLowerCase());
    if (TENANT_SLUG_RESERVED.includes(lower)) {
        return `Das Kürzel „${lower}“ ist reserviert.`;
    }
    if (lower !== value && TENANT_SLUG_PATTERN.test(lower)) {
        return `Bitte nur Kleinbuchstaben, z. B. „${lower}“.`;
    }
    if (!TENANT_SLUG_PATTERN.test(value)) {
        return 'Nur Kleinbuchstaben a–z, Ziffern und Bindestrich; der Bindestrich nicht am Anfang oder Ende und nicht doppelt.';
    }
    return null;
}

/**
 * Wörter am Anfang des Namens, die nicht ins Kürzel kommen (vorläufig, KONZEPT.md, Entscheidung 43):
 * „Feuerwehr Bad Dürrheim“ → `bad-duerrheim`. Bleibt danach nichts übrig, zählt der ganze Name.
 */
const SLUG_SKIPPED_PREFIXES: readonly string[] = [
    'freiwillige feuerwehr',
    'feuerwehr',
    'ff',
    'stadt',
    'gemeinde',
];

const TRANSLITERATION: Readonly<Record<string, string>> = {
    ä: 'ae',
    ö: 'oe',
    ü: 'ue',
    ß: 'ss',
    ẞ: 'ss',
};

/** Kürzel aus dem Namen: Umlaute umgewandelt, andere Akzente ohne Zeichen, sonst Bindestrich. */
export function suggestSlug(name: string): string {
    let text = name.trim().toLowerCase();
    for (let changed = true; changed;) {
        changed = false;
        for (const prefix of SLUG_SKIPPED_PREFIXES) {
            const rest = text.startsWith(`${prefix} `) ? text.slice(prefix.length).trim() : null;
            if (rest !== null && rest !== '') {
                text = rest;
                changed = true;
            }
        }
    }
    const ascii = Array.from(text.normalize('NFC'))
        .map((char) => TRANSLITERATION[char] ?? char)
        .join('')
        .normalize('NFD')
        .replace(/\p{M}/gu, '');
    const slug = ascii.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
    return slug.slice(0, TENANT_SLUG_MAX_LENGTH).replace(/-+$/, '');
}

/** Name einer Stadt wie TenantName (Rand weg, nicht leer, höchstens 100, keine Steuerzeichen). */
export function tenantNameProblem(value: string): string | null {
    const name = phpTrim(value);
    if (name === '') {
        return 'Bitte den Namen der Stadt eingeben.';
    }
    const length = Array.from(name).length;
    if (length > TENANT_NAME_MAX_LENGTH) {
        return `Höchstens ${String(TENANT_NAME_MAX_LENGTH)} Zeichen (jetzt ${String(length)}).`;
    }
    return /\p{Cc}/u.test(name) ? 'Keine Zeilenumbrüche oder Steuerzeichen.' : null;
}

const EMAIL_PATTERN = /^[^@\s\p{Z}\p{C}]+@([^@\s\p{Z}\p{C}]+)$/u;
const EMAIL_DOMAIN_PATTERN = /^[^.]+(\.[^.]+)+$/u;

/** E-Mail wie EmailAddress::fromString (nie strenger; verbindlich bleibt der Server). */
export function emailProblem(value: string): string | null {
    const email = phpTrim(value).toLowerCase();
    if (email === '') {
        return 'Bitte eine E-Mail-Adresse eingeben.';
    }
    if (Array.from(email).length > EMAIL_MAX_LENGTH) {
        return `Höchstens ${String(EMAIL_MAX_LENGTH)} Zeichen.`;
    }
    const match = EMAIL_PATTERN.exec(email);
    if (match === null || !EMAIL_DOMAIN_PATTERN.test(match[1] ?? '')) {
        return 'Bitte eine vollständige E-Mail-Adresse eingeben, z. B. name@beispiel.de';
    }
    return null;
}

/** Adresse der Stadt ohne Schema (nur als Text, Entscheidung 47). */
export function tenantAddress(slug: string): string {
    return `www.prodad.de/firefighting/${slug}/`;
}

/** Pfad wie in der Liste des Mockups. */
export function tenantPath(slug: string): string {
    return `/firefighting/${slug}`;
}

export type TenantState = 'active' | 'invitation' | 'locked';

/**
 * Status in der Liste (Entscheidung 42): gesperrt; „Einladung offen“, solange kein aktiver Admin,
 * aber eine offene Einladung da ist; sonst aktiv.
 */
export function tenantState(tenant: ManagedTenant): TenantState {
    if (!tenant.active) {
        return 'locked';
    }
    return tenant.adminsActive === 0 && tenant.adminsInvited > 0 ? 'invitation' : 'active';
}

export const TENANT_STATE_LABELS: Readonly<Record<TenantState, string>> = {
    active: 'aktiv',
    invitation: 'Einladung offen',
    locked: 'gesperrt',
};

export type TenantSortKey = 'name' | 'address' | 'status' | 'admins' | 'users';
export type SortDir = 'asc' | 'desc';

const STATE_ORDER: Readonly<Record<TenantState, number>> = { active: 0, invitation: 1, locked: 2 };

function compareBy(key: TenantSortKey, a: ManagedTenant, b: ManagedTenant): number {
    switch (key) {
        case 'name':
            return compareNames(a.name, b.name);
        case 'address':
            return compareNames(a.slug, b.slug);
        case 'status':
            return STATE_ORDER[tenantState(a)] - STATE_ORDER[tenantState(b)];
        case 'admins':
            return a.adminsActive - b.adminsActive;
        case 'users':
            return a.usersActive - b.usersActive;
    }
}

/** Städte der Liste: Wörter mit UND in Name und Kürzel, sortiert; bei Gleichstand nach Name. */
export function tenantRows(
    tenants: readonly ManagedTenant[],
    query: string,
    sort: TenantSortKey,
    dir: SortDir,
): ManagedTenant[] {
    const tokens = searchTokens(query);
    const sign = dir === 'asc' ? 1 : -1;
    return tenants
        .filter((tenant) => {
            const key = `${searchKey(tenant.name)} ${tenant.slug}`;
            return tokens.every((token) => key.includes(token));
        })
        .sort((a, b) => {
            const primary = compareBy(sort, a, b);
            return primary === 0 ? compareNames(a.name, b.name) : sign * primary;
        });
}

/** Zahlen der Kacheln des Dashboards (Entscheidung 41). */
export interface ServiceTiles {
    readonly active: number;
    readonly locked: number;
    readonly invited: number;
    readonly users: number;
}

export function serviceTiles(tenants: readonly ManagedTenant[]): ServiceTiles {
    let active = 0;
    let invited = 0;
    let users = 0;
    for (const tenant of tenants) {
        active += tenant.active ? 1 : 0;
        invited += tenant.adminsInvited;
        users += tenant.usersActive;
    }
    return { active, locked: tenants.length - active, invited, users };
}

/** Eine offene Einladung eines Admins (Dashboard). */
export interface OpenInvitation {
    readonly tenant: ManagedTenant;
    readonly user: AdminUser;
}

/** Offene Einladungen, die erneut gesendet werden können (nicht gesperrt), älteste zuerst. */
export function openInvitations(
    entries: readonly { tenant: ManagedTenant; users: readonly AdminUser[] }[],
): OpenInvitation[] {
    return entries
        .flatMap(({ tenant, users }) =>
            users
                .filter(
                    (user) => user.status === 'invited' && user.role === 'admin' && !user.locked,
                )
                .map((user) => ({ tenant, user })),
        )
        .sort((a, b) =>
            a.user.createdAt === b.user.createdAt
                ? a.user.id - b.user.id
                : a.user.createdAt < b.user.createdAt
                  ? -1
                  : 1,
        );
}

export function countText(count: number, one: string, many: string): string {
    return count === 1 ? `1 ${one}` : `${String(count)} ${many}`;
}

/** Konten einer Stadt (nur ansehen): Wörter mit UND in Anzeigename, Name und E-Mail. */
export function tenantUserRows(users: readonly AdminUser[], query: string): AdminUser[] {
    const tokens = searchTokens(query);
    return users
        .filter((user) => {
            const key = searchKey([user.label, user.name ?? '', user.email ?? ''].join(' '));
            return tokens.every((token) => key.includes(token));
        })
        .sort((a, b) => compareNames(a.label, b.label) || a.id - b.id);
}

/** Meldung nach „Stadt anlegen und einladen“ (Entscheidung 43). */
export function createdTenantText(result: CreatedTenant): { text: string; ok: boolean } {
    const email = result.user.email ?? result.user.label;
    return result.mailSent
        ? {
              text: `„${result.tenant.name}“ ist angelegt. Die Einladung an ${email} ist unterwegs (Link 24 Stunden gültig).`,
              ok: true,
          }
        : {
              text: `„${result.tenant.name}“ ist angelegt, aber die Einladung an ${email} konnte nicht gesendet werden. Bitte unter „Offene Admin-Einladungen“ erneut senden.`,
              ok: false,
          };
}

/** Meldung nach „Admin einladen“ bzw. „Einladung erneut senden“ (Entscheidung 44). */
export function invitationText(result: InvitationResult): { text: string; ok: boolean } {
    const email = result.user.email ?? result.user.label;
    return result.mailSent
        ? { text: `Einladung an ${email} gesendet (Link 24 Stunden gültig).`, ok: true }
        : {
              text: `Die Einladung an ${email} konnte nicht gesendet werden. Bitte später erneut senden.`,
              ok: false,
          };
}
