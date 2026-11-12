import type { Page } from '@playwright/test';
import {
    expect,
    HTTP_ERROR_CONSOLE,
    meBody,
    test,
    TEST_TENANT,
    type MockRequest,
    type MockResponse,
} from './fixtures';
import { adminOverview, assignableRolesJson, userJson } from './support/admin';
import { horizontalOverflow, smallTargets, unreachableControls } from './support/layout';
import { delegate } from './support/masterData';

/**
 * Benutzerverwaltung (`benutzer`, KONZEPT.md, "Bereiche des Admins", Benutzer): wartende
 * Registrierungen oben mit Freischalten (Anzeigename, Rolle) und Ablehnen, Liste aller Konten mit
 * Suche, Rollenfilter und Sortierung, Bearbeiten (Anzeigename, Rolle), Rolle mehrerer Konten
 * zuweisen (Plan Phase 1, Schritt 1.6), Sperren, Entsperren, Löschen; das eigene Konto (ID 7 wie
 * meBody) ist geschützt. Rollen wie assignableRolesJson (1 Admin, 2 Benutzer, 3 Gerätewart, 4
 * „Live-Test Filter“, 5 nicht vergebbar). Der simulierte Server merkt sich jede Änderung.
 * Claude-Zugang (docs/PLAN-MCP.md, Schritt M2) nur mit `claude_available` der Stadt.
 */

const ROLE_NAMES: Readonly<Record<number, string>> = {
    1: 'Admin',
    2: 'Benutzer',
    3: 'Gerätewart',
    4: 'Live-Test Filter',
};

/** Rolle wie UserRepository::assignRole: Kontoart folgt der Rolle Admin. */
function withRole(user: Record<string, unknown>, roleId: number): void {
    const admin = roleId === 1;
    Object.assign(user, {
        role: admin ? 'admin' : 'user',
        role_label: admin ? 'Admin' : 'Benutzer',
        role_id: roleId,
        role_name: ROLE_NAMES[roleId],
    });
}

function account(id: number, overrides: Record<string, unknown> = {}): Record<string, unknown> {
    return userJson(id, {
        status: 'active',
        status_label: 'aktiv',
        display_name: `Person ${String(id)}`,
        ...overrides,
    });
}

function initialUsers(): Record<string, unknown>[] {
    return [
        account(7, {
            label: 'Thomas Keller',
            name: 'Thomas Keller',
            display_name: 'Thomas Keller',
            email: 'anna@example.org',
            role: 'admin',
            role_label: 'Admin',
            role_id: 1,
            role_name: 'Admin',
            created_at: '2026-01-05T09:00:00Z',
        }),
        account(8, {
            label: 'Anna Berg',
            name: 'Anna Maria Berg',
            display_name: 'Anna Berg',
            email: 'anna.berg@example.org',
            created_at: '2026-03-01T09:00:00Z',
        }),
        account(9, {
            label: 'Zoë Kraus',
            name: 'Zoë Kraus',
            display_name: 'Zoë Kraus',
            email: 'zk@example.org',
            locked: true,
            created_at: '2026-04-01T09:00:00Z',
        }),
        userJson(21, {
            label: 'Max Neu',
            name: 'Max Neu',
            email: 'max@example.org',
            created_at: '2026-10-20T09:00:00Z',
            role_id: 3,
            role_name: 'Gerätewart',
            invitation_code: { id: 4, label: 'Aushang Wart' },
        }),
        userJson(22, {
            label: 'Erika Neu',
            name: 'Erika Neu',
            email: 'erika@example.org',
            created_at: '2026-10-10T09:00:00Z',
        }),
        userJson(23, {
            label: 'Uwe Offen',
            name: 'Uwe Offen',
            email: 'uwe@example.org',
            status: 'unverified',
            status_label: 'unbestätigt',
            email_verified_at: null,
        }),
    ];
}

type Respond = (request: MockRequest) => MockResponse | null;

/** GET users (mit `status` gefiltert) und alle Aktionen der Konten wie UserActionHandler. */
function userServer(respond: Respond = () => null) {
    const users = initialUsers();
    const find = (id: number) => users.find((user) => user['id'] === id);
    const ok = (user: Record<string, unknown> | undefined): MockResponse =>
        user === undefined
            ? { status: 404, body: { error: 'not_found', message: 'Nicht gefunden.' } }
            : { status: 200, body: { user } };
    const change =
        (
            id: number,
            apply: (user: Record<string, unknown>, body: Record<string, unknown>) => void,
        ) =>
        (request: MockRequest): MockResponse => {
            const replaced = respond(request);
            if (replaced !== null) {
                return replaced;
            }
            const user = find(id);
            if (user !== undefined) {
                apply(user, request.body as Record<string, unknown>);
                // Wie User::label(): anonymisiert „Gelöschter Benutzer #ID“.
                user['label'] =
                    user['status'] === 'deleted'
                        ? `Gelöschter Benutzer #${String(id)}`
                        : (user['display_name'] ?? user['name']);
            }
            return ok(user);
        };
    const remove = (id: number) => (request: MockRequest) =>
        change(id, (user) => {
            Object.assign(user, {
                status: 'deleted',
                display_name: null,
                name: null,
                email: null,
            });
        })(request);

    const api: Record<string, (request: MockRequest) => MockResponse> = {
        'GET api/v1/roles': () => ({ status: 200, body: assignableRolesJson() }),
        // Wie UserBulkRoleHandler: eigenes Konto und „Live-Test Filter“ für Zoë (9) scheitern.
        'POST api/v1/users/bulk/role': (request) => {
            const replaced = respond(request);
            if (replaced !== null) {
                return replaced;
            }
            const body = request.body as { user_ids: number[]; role_id: number };
            const results = body.user_ids.map((id) => {
                const user = find(id);
                if (user === undefined) {
                    return {
                        id,
                        outcome: 'error',
                        user: null,
                        error: { error: 'not_found', message: 'Kein Konto.' },
                    };
                }
                if (id === 7 || (id === 9 && body.role_id === 4)) {
                    return {
                        id,
                        outcome: 'error',
                        user,
                        error: { error: 'rule_violation', message: 'Nicht erlaubt.' },
                    };
                }
                const outcome = user['role_id'] === body.role_id ? 'unchanged' : 'applied';
                withRole(user, body.role_id);
                return { id, outcome, user, error: null };
            });
            return { status: 200, body: { results } };
        },
        'GET api/v1/users': (request) => {
            const status = new URL(request.url).searchParams.get('status');
            return {
                status: 200,
                body: {
                    users: users.filter((user) =>
                        status === null ? user['status'] !== 'deleted' : user['status'] === status,
                    ),
                },
            };
        },
    };
    for (const user of initialUsers()) {
        const id = Number(user['id']);
        const path = `api/v1/users/${String(id)}`;
        api[`POST ${path}/approve`] = change(id, (target, body) => {
            Object.assign(target, {
                status: 'active',
                status_label: 'aktiv',
                display_name: body['display_name'],
            });
            withRole(target, body['role_id'] as number);
        });
        api[`POST ${path}/reject`] = remove(id);
        api[`POST ${path}/lock`] = change(id, (target) => {
            target['locked'] = true;
        });
        api[`POST ${path}/unlock`] = change(id, (target) => {
            target['locked'] = false;
        });
        api[`POST ${path}/role`] = change(id, (target, body) => {
            withRole(target, body['role_id'] as number);
        });
        api[`POST ${path}/display-name`] = change(id, (target, body) => {
            target['display_name'] = body['display_name'];
        });
        api[`DELETE ${path}`] = remove(id);
        // Wie UserRepository::setClaudeAccess bzw. endClaudeConnections.
        api[`POST ${path}/claude-access`] = change(id, (target, body) => {
            target['claude_access'] = body['access'];
            if (body['access'] === 'off') {
                target['claude_connections'] = { count: 0, last_used_at: null };
            }
        });
        api[`POST ${path}/claude-connections/end`] = change(id, (target) => {
            target['claude_connections'] = { count: 0, last_used_at: null };
        });
    }
    return { api, users };
}

let server = userServer();

function useServer(respond?: Respond): void {
    test.beforeEach(() => {
        server = userServer(respond);
    });
}

test.use({
    api: {
        'GET api/v1/auth/me': { status: 200, body: meBody('admin', 'Thomas Keller') },
        ...adminOverview(),
        ...delegate(() => server.api),
    },
});

function sent(requests: readonly MockRequest[], method: string, path: string): unknown[] {
    return requests.filter((r) => r.method === method && r.path === path).map((r) => r.body);
}

async function open(page: Page): Promise<void> {
    await page.goto('/firefighting/test/benutzer');
    await expect(page.getByRole('heading', { level: 1, name: 'Benutzer' })).toBeVisible();
}

function names(page: Page) {
    return page.getByTestId('user-table').locator('tbody .user-name');
}

test.describe('Benutzer', () => {
    useServer();

    test('Freischaltungen oben, Liste darunter, Menü „Benutzer“', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await open(page);
        const pending = page.getByTestId('pending');
        await expect(pending.getByRole('heading')).toHaveText(
            '2 neue Anmeldungen warten auf Freischaltung',
        );
        // Älteste zuerst.
        await expect(pending.locator('.user-name')).toHaveText(['Erika Neu', 'Max Neu']);
        await expect(pending).toContainText('erika@example.org');
        await expect(page.getByTestId('pending-21')).toContainText(
            'mit dem Aushang „Aushang Wart“; die Rolle ist vorausgewählt.',
        );
        await expect(page.getByTestId('pending-21')).toContainText('Rolle: Gerätewart');
        await expect(page.getByTestId('user-7').locator('.col-role')).toHaveText('Admin');
        await expect(page.getByTestId('user-8').locator('.col-role')).toHaveText('Benutzer');
        await expect(page.getByText('legt der Super-Admin fest')).toBeVisible();
        await expect(names(page)).toHaveText([
            'Anna Berg',
            'Thomas Keller',
            'Uwe Offen',
            'Zoë Kraus',
        ]);
        await expect(page.getByTestId('user-7')).toContainText('Sie');
        await expect(page.getByTestId('user-8')).toContainText('Anna Maria Berg');
        await expect(page.getByTestId('user-9')).toContainText('gesperrt');
        await expect(page.getByTestId('user-23')).toContainText('E-Mail unbestätigt');
        await expect(
            page
                .getByRole('navigation', { name: 'Hauptmenü' })
                .getByRole('link', { name: /Benutzer/ }),
        ).toHaveAttribute('aria-current', 'page');
    });

    test('Suche und Sortierung über die Spaltenköpfe', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await open(page);
        await page.getByRole('searchbox', { name: 'Benutzer filtern' }).fill('zoe');
        await expect(names(page)).toHaveText(['Zoë Kraus']);
        await page.getByRole('searchbox', { name: 'Benutzer filtern' }).fill('nobody');
        await expect(page.getByText('Kein Konto passt zu „nobody“.')).toBeVisible();
        await page.getByRole('searchbox', { name: 'Benutzer filtern' }).fill('');

        await page.getByRole('button', { name: 'Rolle' }).click();
        await expect(page.getByRole('columnheader', { name: 'Rolle' })).toHaveAttribute(
            'aria-sort',
            'ascending',
        );
        await expect(names(page).first()).toHaveText('Thomas Keller');
        await page.getByRole('button', { name: 'Name' }).click();
        await page.getByRole('button', { name: 'Name' }).click();
        await expect(names(page)).toHaveText([
            'Zoë Kraus',
            'Uwe Offen',
            'Thomas Keller',
            'Anna Berg',
        ]);
    });

    test('Rollenfilter mit Anzahl', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await open(page);
        const filter = page.getByRole('group', { name: 'Nach Rolle filtern' });
        await expect(filter.getByRole('button')).toHaveText([
            'Alle (4)',
            'Admin (1)',
            'Benutzer (3)',
        ]);
        await expect(filter.getByRole('button', { name: 'Alle (4)' })).toHaveAttribute(
            'aria-pressed',
            'true',
        );
        await filter.getByRole('button', { name: 'Admin (1)' }).click();
        await expect(names(page)).toHaveText(['Thomas Keller']);
        await expect(filter.getByRole('button', { name: 'Admin (1)' })).toHaveAttribute(
            'aria-pressed',
            'true',
        );
        await filter.getByRole('button', { name: 'Alle (4)' }).click();
        await expect(names(page)).toHaveCount(4);
    });

    test('Freischalten mit Anzeigename und Rolle', async ({ page, apiRequests }) => {
        await open(page);
        await page.getByRole('button', { name: 'Max Neu freischalten' }).click();
        const dialog = page.getByRole('dialog', { name: 'Max Neu freischalten' });
        await expect(dialog).toContainText('max@example.org');
        // Rolle des Einladungscodes vorausgewählt.
        await expect(dialog.getByRole('radio', { name: 'Gerätewart' })).toBeChecked();
        await expect(dialog.getByRole('radio', { name: 'Kasse' })).toBeDisabled();
        await expect(dialog).toContainText('Sie können sie daher nicht vergeben.');
        await expect(dialog).toContainText('Sieht: Dashboard, Suchen, Ausrüstung, Termine, Profil');
        const name = dialog.getByLabel('Anzeigename');
        await expect(name).toHaveValue('Max Neu');
        await name.fill('  ');
        await expect(dialog.getByText('Bitte einen Namen eingeben.')).toBeVisible();
        await expect(dialog.getByRole('button', { name: 'Freischalten' })).toBeDisabled();
        await name.fill(' Max ');
        await dialog.getByRole('radio', { name: 'Admin' }).check();
        await dialog.getByRole('button', { name: 'Freischalten' }).click();

        await expect(page.getByTestId('success-notice')).toHaveText(
            'Max ist freigeschaltet (Admin) und erhält eine E-Mail.',
        );
        expect(sent(apiRequests, 'POST', 'api/v1/users/21/approve')).toEqual([
            { display_name: 'Max', role_id: 1 },
        ]);
        await expect(page.getByTestId('pending').getByRole('heading')).toHaveText(
            '1 neue Anmeldung wartet auf Freischaltung',
        );
        await expect(page.getByTestId('user-21')).toContainText('Admin');
        // Das Dashboard (Zähler im Menü) lädt neu.
        expect(sent(apiRequests, 'GET', 'api/v1/users').length).toBeGreaterThanOrEqual(2);
    });

    test('Ablehnen nach Rückfrage, Abbrechen ändert nichts', async ({ page, apiRequests }) => {
        await open(page);
        await page.getByRole('button', { name: 'Registrierung von Erika Neu ablehnen' }).click();
        const confirm = page.getByRole('alertdialog', {
            name: 'Registrierung von Erika Neu ablehnen?',
        });
        await expect(confirm).toContainText('erika@example.org erhält eine E-Mail');
        await expect(confirm.getByRole('button', { name: 'Abbrechen' })).toBeFocused();
        await page.keyboard.press('Escape');
        expect(sent(apiRequests, 'POST', 'api/v1/users/22/reject')).toEqual([]);

        await page.getByRole('button', { name: 'Registrierung von Erika Neu ablehnen' }).click();
        await confirm.getByRole('button', { name: 'Ablehnen' }).click();
        await expect(page.getByTestId('success-notice')).toHaveText(
            'Die Registrierung von Erika Neu ist abgelehnt.',
        );
        await expect(page.getByTestId('pending-22')).toHaveCount(0);
        expect(sent(apiRequests, 'POST', 'api/v1/users/22/reject')).toEqual([{}]);
    });

    test('unbestätigte Registrierung ablehnen', async ({ page, apiRequests }) => {
        await open(page);
        await page.getByRole('button', { name: 'Registrierung von Uwe Offen ablehnen' }).click();
        await page.getByRole('alertdialog').getByRole('button', { name: 'Ablehnen' }).click();
        await expect(page.getByTestId('user-23')).toHaveCount(0);
        expect(sent(apiRequests, 'POST', 'api/v1/users/23/reject')).toEqual([{}]);
    });

    test('Anzeigename und Rolle ändern, nur Geändertes senden', async ({ page, apiRequests }) => {
        await open(page);
        await page.getByRole('button', { name: 'Anna Berg bearbeiten' }).click();
        const dialog = page.getByRole('dialog', { name: 'Anna Berg bearbeiten' });
        await expect(dialog.getByLabel('Anzeigename')).toHaveValue('Anna Berg');
        await expect(dialog.getByRole('radio', { name: 'Benutzer' })).toBeChecked();
        // Stadt ohne Claude-Zugang: keine Auswahl.
        await expect(dialog.getByTestId('claude-access')).toHaveCount(0);
        // Unverändert: schließt ohne Anfrage.
        await dialog.getByRole('button', { name: 'Speichern' }).click();
        await expect(dialog).toBeHidden();
        expect(sent(apiRequests, 'POST', 'api/v1/users/8/display-name')).toEqual([]);

        await page.getByRole('button', { name: 'Anna Berg bearbeiten' }).click();
        await dialog.getByLabel('Anzeigename').fill('Anna B.');
        await dialog.getByRole('button', { name: 'Speichern' }).click();
        await expect(page.getByTestId('success-notice')).toHaveText(
            'Änderungen an Anna B. gespeichert.',
        );
        expect(sent(apiRequests, 'POST', 'api/v1/users/8/display-name')).toEqual([
            { display_name: 'Anna B.' },
        ]);
        expect(sent(apiRequests, 'POST', 'api/v1/users/8/role')).toEqual([]);

        await page.getByRole('button', { name: 'Anna B. bearbeiten' }).click();
        await page.getByRole('dialog').getByRole('radio', { name: 'Gerätewart' }).check();
        await expect(page.getByRole('dialog').getByTestId('role-hint')).toHaveText(
            'Die neue Rolle gilt beim nächsten Aufruf der App. Handys laden dabei ihre Offline-Kopie neu.',
        );
        await page.getByRole('dialog').getByRole('radio', { name: 'Live-Test Filter' }).check();
        await expect(page.getByRole('dialog')).toContainText('Sieht nur:');
        await page.getByRole('dialog').getByRole('radio', { name: 'Gerätewart' }).check();
        await page.getByRole('dialog').getByRole('button', { name: 'Speichern' }).click();
        await expect(page.getByTestId('success-notice')).toHaveText(
            'Anna B. hat jetzt die Rolle „Gerätewart“; sie gilt beim nächsten Aufruf der App.',
        );
        await expect(page.getByTestId('user-8').locator('.col-role')).toHaveText('Gerätewart');

        await page.getByRole('button', { name: 'Anna B. bearbeiten' }).click();
        await page.getByRole('dialog').getByRole('radio', { name: 'Admin' }).check();
        await expect(page.getByRole('dialog').getByTestId('admin-hint')).toContainText(
            'Anna B. kann dann selbst Rollen zuweisen.',
        );
        await expect(page.getByRole('dialog').getByTestId('role-hint')).toHaveText(
            'Mit diesem Wechsel wird die Person auf allen Geräten abgemeldet.',
        );
        await page.getByRole('dialog').getByRole('button', { name: 'Speichern' }).click();
        await expect(page.getByTestId('success-notice')).toHaveText(
            'Anna B. hat jetzt die Rolle „Admin“ und muss sich neu anmelden.',
        );
        expect(sent(apiRequests, 'POST', 'api/v1/users/8/role')).toEqual([
            { role_id: 3 },
            { role_id: 1 },
        ]);
    });

    test('Rolle mehrerer Konten zuweisen, Ergebnis je Konto', async ({ page, apiRequests }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await open(page);
        await expect(page.getByRole('checkbox', { name: 'Thomas Keller auswählen' })).toHaveCount(
            0,
        );
        await page.getByRole('checkbox', { name: 'Anna Berg auswählen' }).check();
        await page.getByRole('checkbox', { name: 'Zoë Kraus auswählen' }).check();
        const bar = page.getByTestId('selection-bar');
        await expect(bar).toContainText('2 Konten ausgewählt');

        await bar.getByRole('button', { name: 'Rolle zuweisen …' }).click();
        const dialog = page.getByRole('dialog', { name: 'Rolle zuweisen: 2 Konten ausgewählt' });
        await expect(dialog.getByRole('button', { name: 'Rolle zuweisen' })).toBeDisabled();
        await dialog.getByRole('radio', { name: 'Live-Test Filter' }).check();
        await dialog.getByRole('button', { name: 'Rolle zuweisen' }).click();

        await expect(page.getByTestId('bulk-summary')).toHaveText(
            'Rolle „Live-Test Filter“: 1 Konto geändert, 1 nicht möglich.',
        );
        await expect(page.getByTestId('bulk-failures')).toContainText('Zoë Kraus: Nicht erlaubt.');
        await expect(bar).toContainText('1 Konto ausgewählt');
        await expect(page.getByRole('checkbox', { name: 'Zoë Kraus auswählen' })).toBeChecked();
        await expect(page.getByTestId('user-8').locator('.col-role')).toHaveText(
            'Live-Test Filter',
        );

        await bar.getByRole('button', { name: 'Auswahl aufheben' }).click();
        await expect(bar).toBeHidden();
        await page.getByRole('checkbox', { name: 'Alle angezeigten Konten auswählen' }).check();
        await expect(page.getByTestId('selection-bar')).toContainText('3 Konten ausgewählt');
        await page
            .getByTestId('selection-bar')
            .getByRole('button', { name: 'Rolle zuweisen …' })
            .click();
        await page.getByRole('dialog').getByRole('radio', { name: 'Benutzer' }).check();
        await page.getByRole('dialog').getByRole('button', { name: 'Rolle zuweisen' }).click();
        await expect(page.getByTestId('bulk-summary')).toHaveText(
            'Rolle „Benutzer“: 1 Konto geändert, 2 unverändert.',
        );
        await expect(page.getByTestId('selection-bar')).toBeHidden();
        expect(sent(apiRequests, 'POST', 'api/v1/users/bulk/role')).toEqual([
            { user_ids: [8, 9], role_id: 4 },
            { user_ids: [8, 9, 23], role_id: 2 },
        ]);
    });

    test('Sperren nach Rückfrage, Entsperren direkt', async ({ page, apiRequests }) => {
        await open(page);
        await page.getByRole('button', { name: 'Anna Berg bearbeiten' }).click();
        await page.getByRole('button', { name: 'Anna Berg sperren …' }).click();
        const confirm = page.getByRole('alertdialog', { name: 'Anna Berg sperren?' });
        await expect(confirm).toContainText('auf allen Geräten abgemeldet');
        await confirm.getByRole('button', { name: 'Sperren' }).click();
        await expect(page.getByTestId('success-notice')).toHaveText('Anna Berg ist gesperrt.');
        await expect(page.getByTestId('user-8')).toContainText('gesperrt');

        await page.getByRole('button', { name: 'Zoë Kraus bearbeiten' }).click();
        await page.getByRole('button', { name: 'Zoë Kraus entsperren' }).click();
        await expect(page.getByTestId('success-notice')).toHaveText('Zoë Kraus ist entsperrt.');
        await expect(page.getByTestId('user-9')).toContainText('aktiv');
        expect(sent(apiRequests, 'POST', 'api/v1/users/8/lock')).toEqual([{}]);
        expect(sent(apiRequests, 'POST', 'api/v1/users/9/unlock')).toEqual([{}]);
    });

    test('Löschen nach Rückfrage', async ({ page, apiRequests }) => {
        await open(page);
        await page.getByRole('button', { name: 'Zoë Kraus bearbeiten' }).click();
        await page.getByRole('button', { name: 'Zoë Kraus löschen …' }).click();
        const confirm = page.getByRole('alertdialog', { name: 'Zoë Kraus löschen?' });
        await expect(confirm).toContainText('„Gelöschter Benutzer #9“');
        await confirm.getByRole('button', { name: 'Löschen' }).click();
        await expect(page.getByTestId('success-notice')).toHaveText('Zoë Kraus wurde gelöscht.');
        await expect(page.getByTestId('user-9')).toHaveCount(0);
        expect(sent(apiRequests, 'DELETE', 'api/v1/users/9')).toEqual([{}]);
    });

    test('eigenes Konto: Anzeigename ja, Rolle, Sperren und Löschen nein', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await open(page);
        await page.getByRole('button', { name: 'Thomas Keller bearbeiten' }).click();
        const dialog = page.getByRole('dialog', { name: 'Thomas Keller bearbeiten' });
        await expect(dialog.getByTestId('own-account')).toBeVisible();
        await expect(dialog.getByRole('radio', { name: 'Benutzer' })).toBeDisabled();
        await expect(dialog.getByRole('button', { name: /sperren|löschen/ })).toHaveCount(0);
        await dialog.getByLabel('Anzeigename').fill('Tom Keller');
        await dialog.getByRole('button', { name: 'Speichern' }).click();
        await expect(page.getByTestId('success-notice')).toHaveText(
            'Änderungen an Tom Keller gespeichert.',
        );
        // Die Sitzung übernimmt den neuen Namen (Menü unten).
        await expect(
            page.getByRole('navigation', { name: 'Hauptmenü' }).getByTestId('account-name'),
        ).toHaveText('Tom Keller');
    });
});

test.describe('Claude-Zugang', () => {
    useServer();

    test.beforeEach(() => {
        const anna = server.users.find((user) => user['id'] === 8);
        Object.assign(anna ?? {}, {
            claude_access: 'write',
            claude_connections: {
                count: 2,
                last_used_at: new Date(Date.now() - 5 * 60_000)
                    .toISOString()
                    .replace(/\.\d+Z$/, 'Z'),
            },
        });
    });

    test.use({ tenant: { ...TEST_TENANT, body: { ...TEST_TENANT.body, claude_available: true } } });

    test('Chip und Verbindungen in der Liste', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await open(page);
        const anna = page.getByTestId('user-8');
        await expect(anna.getByTestId('claude-chip-8')).toHaveText('Claude: Ändern');
        await expect(anna).toContainText(/2 Verbindungen · zuletzt genutzt vor \d+ Min\./);
        await expect(page.getByTestId('user-9').getByTestId('claude-chip-9')).toHaveCount(0);
    });

    test('Zugang im Fenster „Bearbeiten“ ändern, nur der Zugang wird gesendet', async ({
        page,
        apiRequests,
    }) => {
        await open(page);
        await page.getByRole('button', { name: 'Anna Berg bearbeiten' }).click();
        const dialog = page.getByRole('dialog', { name: 'Anna Berg bearbeiten' });
        const access = dialog.getByTestId('claude-access');
        await expect(access.getByRole('radio', { name: 'Claude-Zugang: Ändern' })).toBeChecked();
        await access.getByRole('radio', { name: 'Claude-Zugang: Lesen' }).check();
        await dialog.getByRole('button', { name: 'Speichern' }).click();
        await expect(page.getByTestId('success-notice')).toHaveText(
            'Claude-Zugang von Anna Berg: Lesen.',
        );
        await expect(page.getByTestId('claude-chip-8')).toHaveText('Claude: Lesen');
        expect(sent(apiRequests, 'POST', 'api/v1/users/8/claude-access')).toEqual([
            { access: 'read' },
        ]);
        expect(sent(apiRequests, 'POST', 'api/v1/users/8/display-name')).toEqual([]);
        expect(sent(apiRequests, 'POST', 'api/v1/users/8/role')).toEqual([]);

        // „Aus“: der Chip verschwindet, die Verbindungen sind getrennt.
        await page.getByRole('button', { name: 'Anna Berg bearbeiten' }).click();
        await dialog.getByRole('radio', { name: 'Claude-Zugang: Aus' }).check();
        await dialog.getByRole('button', { name: 'Speichern' }).click();
        await expect(page.getByTestId('success-notice')).toHaveText(
            'Anna Berg hat keinen Claude-Zugang mehr; bestehende Verbindungen sind getrennt.',
        );
        await expect(page.getByTestId('claude-chip-8')).toHaveCount(0);
        await expect(page.getByTestId('user-8')).not.toContainText('Verbindungen');
    });

    test('Verbindungen trennen nach Rückfrage, der Zugang bleibt', async ({
        page,
        apiRequests,
    }) => {
        await open(page);
        await page.getByRole('button', { name: 'Anna Berg bearbeiten' }).click();
        await page.getByRole('button', { name: 'Claude-Verbindungen trennen …' }).click();
        const confirm = page.getByRole('alertdialog', {
            name: 'Claude-Verbindungen von Anna Berg trennen?',
        });
        await expect(confirm).toContainText('Der Claude-Zugang bleibt.');
        await confirm.getByRole('button', { name: 'Trennen' }).click();
        await expect(page.getByTestId('success-notice')).toHaveText(
            'Die Claude-Verbindungen von Anna Berg sind getrennt.',
        );
        await expect(page.getByTestId('user-8')).not.toContainText('Verbindungen');
        await expect(page.getByTestId('claude-chip-8')).toHaveText('Claude: Ändern');
        expect(sent(apiRequests, 'POST', 'api/v1/users/8/claude-connections/end')).toEqual([{}]);

        // Ohne Verbindung gibt es den Knopf nicht.
        await page.getByRole('button', { name: 'Anna Berg bearbeiten' }).click();
        await expect(
            page.getByRole('dialog').getByRole('button', { name: 'Claude-Verbindungen trennen …' }),
        ).toHaveCount(0);
    });

    test('Handy: Chip und Verbindungen ohne waagerechten Überstand', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await open(page);
        await expect(page.getByTestId('claude-chip-8')).toBeVisible();
        expect(await horizontalOverflow(page)).toBe(0);
    });

    test('eigenes Konto und nicht aktive Konten: Auswahl gesperrt mit Hinweis', async ({
        page,
    }) => {
        await open(page);
        await page.getByRole('button', { name: 'Thomas Keller bearbeiten' }).click();
        const own = page.getByRole('dialog', { name: 'Thomas Keller bearbeiten' });
        await expect(own.getByTestId('claude-blocker')).toHaveText(
            'Den eigenen Claude-Zugang kann man nicht ändern.',
        );
        await expect(own.getByRole('radio', { name: 'Claude-Zugang: Lesen' })).toBeDisabled();
        await own.getByRole('button', { name: 'Abbrechen' }).click();

        await page.getByRole('button', { name: 'Zoë Kraus bearbeiten' }).click();
        const locked = page.getByRole('dialog', { name: 'Zoë Kraus bearbeiten' });
        await expect(locked.getByRole('radio', { name: 'Claude-Zugang: Lesen' })).toBeEnabled();
    });
});

test.describe('Regel verletzt', () => {
    useServer((request) =>
        request.path === 'api/v1/users/8/role'
            ? {
                  status: 409,
                  body: {
                      error: 'rule_violation',
                      message: 'Der letzte aktive Admin der Stadt kann nicht herabgestuft werden.',
                  },
              }
            : null,
    );
    test.use({ allowedConsoleError: HTTP_ERROR_CONSOLE });

    test('Meldung des Servers im Fenster, Fenster bleibt offen', async ({ page }) => {
        await open(page);
        await page.getByRole('button', { name: 'Anna Berg bearbeiten' }).click();
        const dialog = page.getByRole('dialog', { name: 'Anna Berg bearbeiten' });
        await dialog.getByRole('radio', { name: 'Admin' }).check();
        await dialog.getByRole('button', { name: 'Speichern' }).click();
        await expect(dialog.getByRole('alert')).toContainText(
            'Der letzte aktive Admin der Stadt kann nicht herabgestuft werden.',
        );
        await expect(dialog).toBeVisible();
    });
});

test.describe('Handy', () => {
    useServer();

    test('Karten mit Sortier-Knöpfen, kein waagerechter Überstand', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await open(page);
        await expect(page.getByRole('group', { name: 'Sortieren' })).toBeVisible();
        await expect(page.getByRole('columnheader')).toHaveCount(0);
        await expect(names(page)).toHaveCount(4);
        expect(await horizontalOverflow(page)).toBe(0);
        await page
            .getByRole('group', { name: 'Sortieren' })
            .getByRole('button', { name: 'Status' })
            .click();
        await expect(names(page).last()).toHaveText('Zoë Kraus');
        const approve = await page
            .getByRole('button', { name: 'Max Neu freischalten' })
            .boundingBox();
        const reject = await page
            .getByRole('button', { name: 'Registrierung von Max Neu ablehnen' })
            .boundingBox();
        expect(approve?.width).toBe(reject?.width);
    });
});

test.describe('Breiten (Runde 14, Punkt 2)', () => {
    useServer();

    for (const width of [390, 768, 1024]) {
        test(`${String(width)} px: jedes Bedienelement jeder Zeile erreichbar`, async ({
            page,
        }) => {
            await page.setViewportSize({ width, height: 900 });
            await open(page);
            await expect(names(page)).toHaveCount(4);
            expect(await horizontalOverflow(page)).toBeLessThanOrEqual(0);
            expect(await unreachableControls(page, '[data-testid="user-table"] tbody tr')).toEqual(
                [],
            );
            expect(await unreachableControls(page, '.pending-card')).toEqual([]);
            // U3: Kästchen der Liste mindestens 44 px.
            expect(await smallTargets(page, '[data-testid="user-table"]')).toEqual([]);
            await page.getByRole('button', { name: 'Max Neu freischalten' }).click();
            await expect(page.getByRole('dialog', { name: 'Max Neu freischalten' })).toBeVisible();
            expect(await smallTargets(page, '[data-form-dialog]')).toEqual([]);
        });
    }
});
