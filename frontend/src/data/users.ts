import type { AdminUser, ManagedRole } from '../api/users';
import { searchKey, searchTokens } from './deviceSearch';
import { compareNames, nameProblem, phpTrim } from './masterData';

/**
 * Benutzerverwaltung des Admins (`benutzer`): Gruppen, Suche, Sortierung, Texte und Prüfung des
 * Anzeigenamens. Verbindlich bleibt der Server (Regeln in UserRepository und UserAdministration).
 */

/** Höchstlänge wie PersonName in PHP. */
export const PERSON_NAME_MAX_LENGTH = 100;

/** Anzeigename wie PersonName::fromString (Rand wie PHP `trim()`, Länge, keine Steuerzeichen). */
export function displayNameProblem(value: string): string | null {
    return nameProblem(value, PERSON_NAME_MAX_LENGTH);
}

export const ROLE_OPTIONS: readonly { readonly role: ManagedRole; readonly label: string }[] = [
    { role: 'user', label: 'Benutzer' },
    { role: 'admin', label: 'Admin' },
];

/** Registrierungen, die auf Freischaltung warten, älteste zuerst. */
export function pendingUsers(users: readonly AdminUser[]): AdminUser[] {
    return users
        .filter((user) => user.status === 'pending')
        .sort((a, b) => a.createdAt.localeCompare(b.createdAt) || a.id - b.id);
}

export type UserSortKey = 'name' | 'email' | 'role' | 'status' | 'created';
export type SortDir = 'asc' | 'desc';

/** Status für Anzeige und Sortierung; gesperrt geht vor dem Status des Kontos. */
export function statusText(user: AdminUser): string {
    if (user.locked) {
        return 'gesperrt';
    }
    switch (user.status) {
        case 'active':
            return 'aktiv';
        case 'invited':
            return 'eingeladen';
        case 'unverified':
            return 'E-Mail unbestätigt';
        case 'pending':
            return 'wartet auf Freischaltung';
        default:
            return user.statusLabel;
    }
}

const STATUS_ORDER = ['aktiv', 'eingeladen', 'wartet auf Freischaltung', 'E-Mail unbestätigt'];

function statusRank(user: AdminUser): number {
    const index = STATUS_ORDER.indexOf(statusText(user));
    return index === -1 ? STATUS_ORDER.length : index;
}

function compare(a: AdminUser, b: AdminUser, key: UserSortKey): number {
    switch (key) {
        case 'name':
            return compareNames(a.label, b.label);
        case 'email':
            return compareNames(a.email ?? '', b.email ?? '');
        case 'role':
            // Admins zuerst.
            return (a.role === 'admin' ? 0 : 1) - (b.role === 'admin' ? 0 : 1);
        case 'status':
            return statusRank(a) - statusRank(b);
        case 'created':
            return a.createdAt.localeCompare(b.createdAt);
    }
}

/**
 * Liste unter den Freischaltungen: alle Konten außer `pending` (die stehen oben), gefiltert nach
 * Wörtern mit UND in Anzeigename, Name und E-Mail (Vergleichsform `searchKey`), sortiert; bei
 * Gleichstand nach Name, dann ID.
 */
export function userRows(
    users: readonly AdminUser[],
    query: string,
    sort: UserSortKey,
    dir: SortDir,
): AdminUser[] {
    const tokens = searchTokens(query);
    return users
        .filter((user) => user.status !== 'pending')
        .filter((user) => {
            const text = userSearchKey(user);
            return tokens.every((token) => text.includes(token));
        })
        .sort((a, b) => {
            const primary = compare(a, b, sort);
            return (
                (dir === 'asc' ? primary : -primary) ||
                compareNames(a.label, b.label) ||
                a.id - b.id
            );
        });
}

/** Zweite Zeile unter dem Anzeigenamen: der Name aus der Registrierung, wenn er abweicht. */
export function nameHint(user: AdminUser): string | null {
    return user.name !== null && user.name !== user.displayName ? user.name : null;
}

/** Was im Fenster „Bearbeiten“ gesendet wird (nur Änderungen); Name getrimmt wie der Server. */
export function userChanges(
    user: AdminUser,
    displayName: string,
    role: ManagedRole,
): { displayName?: string; role?: ManagedRole } {
    const name = phpTrim(displayName);
    return {
        ...(user.status === 'active' && name !== (user.displayName ?? '') && { displayName: name }),
        ...(role !== user.role && { role }),
    };
}

/** Datum der Registrierung in Ortszeit („12.10.2026“). */
export function createdText(user: AdminUser): string {
    return new Date(user.createdAt).toLocaleDateString('de-DE', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    });
}

/** „2 neue Anmeldungen warten auf Freischaltung“. */
export function pendingText(count: number): string {
    return count === 1
        ? '1 neue Anmeldung wartet auf Freischaltung'
        : `${String(count)} neue Anmeldungen warten auf Freischaltung`;
}

/** Text, in dem die Suche sucht: Anzeigename, Name und E-Mail in der Vergleichsform. */
function userSearchKey(user: AdminUser): string {
    return searchKey([user.displayName, user.name, user.email].filter((v) => v !== null).join(' '));
}
