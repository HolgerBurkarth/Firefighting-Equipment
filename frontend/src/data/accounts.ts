import { shallowRef, watch } from 'vue';
import { parseAuthState, userLabel, type AuthState } from '../api/auth';
import { onSessionEnded, session } from '../auth/session';
import { SERVICE_AREA } from '../runtime/appBase';
import type { LocalDb } from '../runtime/localDb';
import { readRememberedTenant } from '../runtime/splash';
import { readStoredQueue } from './bookingQueue';
import { slugProblem } from './tenants';

/**
 * Konten in mehreren Städten auf diesem Gerät („Konto wechseln“, Konzept E14, Plan Phase 1, Schritt
 * 1.9): eine Liste für alle Städte in localStorage `ff-accounts`, je Stadt höchstens ein Eintrag mit
 * Kürzel, Name der Stadt, Konto-ID (zum Zuordnen von Sitzung und Warteschlange), Anzeigename und
 * Rollenname. Nie Tokens, E-Mail oder Rechte. Sitzungen, Offline-Kopien und Warteschlangen bleiben je
 * Stadt getrennt (`ff-session:<kürzel>`, IndexedDB `<kürzel>:<konto-id>`); die Liste liest sie nur.
 */
export const ACCOUNTS_KEY = 'ff-accounts';
/** Formatversion des Eintrags `ff-accounts` (`{format, accounts}`). */
export const ACCOUNTS_FORMAT = 1;

export interface KnownAccount {
    readonly slug: string;
    readonly tenantName: string;
    readonly accountId: number;
    readonly displayName: string;
    readonly roleName: string | null;
}

/** `current` = diese Stadt, `signed-in` = gespeicherte Sitzung desselben Kontos, sonst `expired`. */
export type AccountState = 'current' | 'signed-in' | 'expired';

export interface AccountEntry extends KnownAccount {
    readonly state: AccountState;
    /** Noch nicht beim Server angekommene Buchungen dieses Kontos. */
    readonly unsent: number;
    /** Stadtfarbe aus dem letzten Besuch (`ff-tenant:<kürzel>`), null = Standard. */
    readonly accentColor: string | null;
}

type AccountStorage = Pick<Storage, 'getItem' | 'setItem'>;

const SLUG_PATTERN = /^[a-z0-9]+(-[a-z0-9]+)*$/;

function isKnownAccount(value: unknown): value is KnownAccount {
    if (typeof value !== 'object' || value === null) {
        return false;
    }
    const entry = value as Record<string, unknown>;
    return (
        typeof entry['slug'] === 'string' &&
        SLUG_PATTERN.test(entry['slug']) &&
        entry['slug'] !== SERVICE_AREA &&
        typeof entry['tenantName'] === 'string' &&
        typeof entry['accountId'] === 'number' &&
        Number.isInteger(entry['accountId']) &&
        typeof entry['displayName'] === 'string' &&
        (entry['roleName'] === null || typeof entry['roleName'] === 'string')
    );
}

/** Gemerkte Konten, sortiert nach Name der Stadt; ein unlesbarer Eintrag gilt als leer. */
export function readAccounts(storage: AccountStorage | null): KnownAccount[] {
    try {
        const stored: unknown = JSON.parse(storage?.getItem(ACCOUNTS_KEY) ?? 'null');
        if (
            typeof stored !== 'object' ||
            stored === null ||
            (stored as Record<string, unknown>)['format'] !== ACCOUNTS_FORMAT
        ) {
            return [];
        }
        const accounts = (stored as Record<string, unknown>)['accounts'];
        return Array.isArray(accounts) ? sortAccounts(accounts.filter(isKnownAccount)) : [];
    } catch {
        return [];
    }
}

const COLLATOR = new Intl.Collator('de', { numeric: true, sensitivity: 'base' });

function sortAccounts(accounts: KnownAccount[]): KnownAccount[] {
    return [...accounts].sort(
        (a, b) => COLLATOR.compare(a.tenantName, b.tenantName) || COLLATOR.compare(a.slug, b.slug),
    );
}

function writeAccounts(storage: AccountStorage | null, accounts: readonly KnownAccount[]): void {
    try {
        storage?.setItem(
            ACCOUNTS_KEY,
            JSON.stringify({ format: ACCOUNTS_FORMAT, accounts: sortAccounts([...accounts]) }),
        );
    } catch {
        // Ohne Speicher keine Liste; die Anmeldung selbst ist davon nicht betroffen.
    }
}

/** Bestätigte Sitzung einer Stadt merken (ersetzt einen Eintrag derselben Stadt). */
export function rememberAccount(storage: AccountStorage | null, state: AuthState): void {
    const tenant = state.tenant;
    if (tenant === null) {
        return;
    }
    const entry: KnownAccount = {
        slug: tenant.slug,
        tenantName: tenant.name,
        accountId: state.user.id,
        displayName: userLabel(state.user),
        roleName: state.user.roleName,
    };
    const accounts = readAccounts(storage);
    const previous = accounts.find((account) => account.slug === entry.slug);
    if (previous !== undefined && JSON.stringify(previous) === JSON.stringify(entry)) {
        return;
    }
    writeAccounts(storage, [...accounts.filter((account) => account.slug !== entry.slug), entry]);
}

/** Abmelden in einer Stadt: nur deren Eintrag entfernen. */
export function forgetAccount(storage: AccountStorage | null, slug: string): void {
    const accounts = readAccounts(storage);
    if (accounts.some((account) => account.slug === slug)) {
        writeAccounts(
            storage,
            accounts.filter((account) => account.slug !== slug),
        );
    }
}

function storedAccountId(storage: AccountStorage | null, slug: string): number | null {
    try {
        const text = storage?.getItem(`ff-session:${slug}`) ?? null;
        return text === null ? null : (parseAuthState(JSON.parse(text))?.user.id ?? null);
    } catch {
        return null;
    }
}

async function unsentCount(db: LocalDb | null, account: KnownAccount): Promise<number> {
    if (db === null) {
        return 0;
    }
    try {
        const stored = await db.get('queue', `${account.slug}:${String(account.accountId)}`);
        return readStoredQueue(stored).bookings.filter((booking) => booking.state !== 'done')
            .length;
    } catch {
        return 0;
    }
}

/** Einträge mit Kennzeichen, nicht gesendeten Buchungen und Farbe für die Anzeige. */
export async function accountEntries(
    storage: AccountStorage | null,
    db: LocalDb | null,
    currentSlug: string,
): Promise<AccountEntry[]> {
    const result: AccountEntry[] = [];
    for (const account of readAccounts(storage)) {
        const state: AccountState =
            account.slug === currentSlug
                ? 'current'
                : storedAccountId(storage, account.slug) === account.accountId
                  ? 'signed-in'
                  : 'expired';
        result.push({
            ...account,
            state,
            unsent: await unsentCount(db, account),
            accentColor:
                readRememberedTenant(storage as Storage | null, account.slug)?.accentColor ?? null,
        });
    }
    return result;
}

/** Hinweise unter einem Eintrag (abgelaufene Anmeldung, nicht gesendete Buchungen). */
export function accountHints(entry: AccountEntry): string[] {
    const hints: string[] = [];
    if (entry.state === 'expired') {
        hints.push('Anmeldung abgelaufen – beim Antippen öffnet sich die Anmeldung dieser Stadt.');
    }
    if (entry.unsent > 0 && entry.state !== 'current') {
        hints.push(
            entry.unsent === 1
                ? '1 nicht gesendete Buchung – wird nach dem Wechsel gesendet.'
                : `${String(entry.unsent)} nicht gesendete Buchungen – werden nach dem Wechsel gesendet.`,
        );
    }
    return hints;
}

const STATE_LABELS: Readonly<Record<AccountState, string>> = {
    current: 'aktuell',
    'signed-in': 'angemeldet',
    expired: 'neu anmelden',
};

/** Kennzeichen am Eintrag; am Handy heißt „angemeldet“ wie im Mockup „wechseln“. */
export function accountStateLabel(state: AccountState, compact = false): string {
    return compact && state === 'signed-in' ? 'wechseln' : STATE_LABELS[state];
}

/** Allgemeine Wörter im Namen der Stadt, die für das Kürzel im Symbol nicht zählen. */
const NAME_STOPWORDS = new Set(['ff', 'feuerwehr', 'freiwillige', 'stadt', 'gemeinde', 'der']);

/** Zwei Buchstaben für das Symbol, z. B. „FF Immendingen“ → „IM“. */
export function accountInitials(tenantName: string): string {
    const words = tenantName.split(/[\s-]+/u).filter((word) => /\p{L}/u.test(word));
    const word = words.find((w) => !NAME_STOPWORDS.has(w.toLowerCase())) ?? words[0] ?? '?';
    return Array.from(word.replace(/[^\p{L}\p{N}]/gu, ''))
        .slice(0, 2)
        .join('')
        .toUpperCase();
}

/**
 * Eingabe „Adresse oder Kürzel der Stadt“: eine Adresse (mit oder ohne Schema) liefert das Segment
 * nach `/firefighting/`, sonst gilt die Eingabe als Kürzel. Geprüft wie TenantSlug (`slugProblem`).
 */
export function cityFromInput(
    input: string,
    currentSlug: string,
): { readonly slug: string } | { readonly problem: string } {
    const text = input.trim();
    if (text === '') {
        return { problem: 'Bitte die Adresse oder das Kürzel der Stadt eingeben.' };
    }
    const fromAddress = /\/firefighting\/([^/?#]*)/u.exec(text);
    const slug = fromAddress?.[1] ?? text;
    const problem = slugProblem(slug);
    if (problem !== null) {
        return { problem };
    }
    if (slug === currentSlug) {
        return { problem: 'In dieser Stadt sind Sie gerade angemeldet.' };
    }
    return { slug };
}

/** Adresse einer anderen Stadt relativ zum Basispfad, z. B. `../hattingen/anmelden`. */
export function cityUrl(slug: string, page = '', baseUri: string = document.baseURI): string {
    return new URL(`../${slug}/${page}`, baseUri).href;
}

/**
 * Hält die Liste für die Anzeige aktuell (`AccountSwitcher`); `refresh` liest Liste, Sitzungen und
 * Warteschlangen neu.
 */
export class AccountList {
    private readonly current = shallowRef<readonly AccountEntry[]>([]);
    private generation = 0;

    constructor(
        private readonly options: {
            readonly storage: AccountStorage | null;
            readonly db: LocalDb | null;
            readonly area: () => string;
        },
    ) {}

    get entries(): readonly AccountEntry[] {
        return this.current.value;
    }

    remember(state: AuthState): void {
        rememberAccount(this.options.storage, state);
    }

    forget(slug: string): void {
        forgetAccount(this.options.storage, slug);
    }

    async refresh(): Promise<void> {
        this.generation += 1;
        const generation = this.generation;
        const entries = await accountEntries(
            this.options.storage,
            this.options.db,
            this.options.area(),
        );
        if (generation === this.generation) {
            this.current.value = entries;
        }
    }
}

/**
 * main.ts, nur unter Städten: jede bekannte Sitzung dieser Stadt kommt in die Liste (ein anderes
 * Konto ersetzt den Eintrag); Abmelden beim Server entfernt nur den Eintrag dieser Stadt. Eine
 * abgelaufene Sitzung (401) bleibt eingetragen und zeigt dann „neu anmelden“.
 */
export function installAccountList(list: AccountList): () => void {
    const stopWatch = watch(
        session,
        (state) => {
            if (state !== null) {
                list.remember(state);
            }
        },
        { immediate: true },
    );
    const stopEnded = onSessionEnded((ended, reason) => {
        if (reason === 'logout' && ended.tenant !== null) {
            list.forget(ended.tenant.slug);
        }
    });
    return () => {
        stopWatch();
        stopEnded();
    };
}
