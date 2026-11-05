// @vitest-environment node
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import {
    parseCatalog,
    parseFilterOptions,
    parseRole,
    type PermissionCatalog,
    type Role,
} from '../../src/api/roles';
import {
    actionState,
    allActions,
    deleteBlocker,
    emptyRoleForm,
    filterSummary,
    hasProblems,
    levelOptions,
    ROLE_DESCRIPTION_MAX_LENGTH,
    ROLE_NAME_MAX_LENGTH,
    roleForm,
    roleFormProblems,
    rolePreview,
    roleRequest,
    roleSummary,
    sameRoleForm,
    type RoleForm,
} from '../../src/data/roles';

const ROOT = new URL('../../../', import.meta.url);

/** Gemeinsame Datei mit PHP (PermissionCatalogFixtureTest): genau GET permission-catalog. */
const CATALOG_JSON: unknown = JSON.parse(
    readFileSync(new URL('tests/Fixtures/permission-catalog.json', ROOT), 'utf8'),
);

function catalog(): PermissionCatalog {
    const parsed = parseCatalog(CATALOG_JSON);
    if (parsed === null) {
        throw new Error('Katalog nicht lesbar');
    }
    return parsed;
}

function phpInt(file: string, name: string): number {
    const php = readFileSync(new URL(file, ROOT), 'utf8');
    const match = new RegExp(`const int ${name} = (\\d+);`).exec(php);
    if (match?.[1] === undefined) {
        throw new Error(`${name} nicht gefunden`);
    }
    return Number(match[1]);
}

/** Wie RoleJson::role. */
function roleJson(overrides: Record<string, unknown> = {}): Record<string, unknown> {
    return {
        id: 7,
        name: 'Gerätewart',
        description: 'Wie Admin, ohne Benutzer',
        system_key: null,
        all_rights: false,
        editable: true,
        deletable: true,
        areas: { search: 'read', devices: 'write' },
        actions: ['book.move', 'device.edit'],
        locations: null,
        device_types: [3],
        version: 2,
        created_at: '2026-11-05T08:00:00Z',
        updated_at: '2026-11-05T09:00:00Z',
        usage: { accounts: 3, invitation_codes: 1 },
        ...overrides,
    };
}

function role(overrides: Record<string, unknown> = {}): Role {
    const parsed = parseRole(roleJson(overrides));
    if (parsed === null) {
        throw new Error('Rolle nicht lesbar');
    }
    return parsed;
}

function form(overrides: Partial<RoleForm> = {}): RoleForm {
    return { ...emptyRoleForm(), name: 'Live-Test', ...overrides };
}

describe('Katalog und Grenzen wie im Backend', () => {
    it('liest die gemeinsame Datei', () => {
        const parsed = catalog();
        expect(parsed.areas.map((area) => area.key)).toEqual([
            'dashboard',
            'search',
            'devices',
            'locations',
            'device_types',
            'due',
            'log',
            'users',
            'settings',
        ]);
        expect(parsed.levelLabels).toEqual({ read: 'Ansehen', write: 'Ändern' });
        expect(allActions(parsed)).toHaveLength(9);
    });

    it('Länge von Name und Kurzbeschreibung wie RoleDraft', () => {
        expect(ROLE_NAME_MAX_LENGTH).toBe(phpInt('src/Role/RoleDraft.php', 'MAX_NAME_LENGTH'));
        expect(ROLE_DESCRIPTION_MAX_LENGTH).toBe(
            phpInt('src/Role/RoleDraft.php', 'MAX_DESCRIPTION_LENGTH'),
        );
    });

    it('lehnt unvollständige Antworten ab', () => {
        expect(parseRole(roleJson({ locations: [] as unknown[] }))).not.toBeNull();
        expect(parseRole(roleJson({ locations: ['1'] }))).toBeNull();
        expect(parseRole(roleJson({ areas: { search: 'none' } }))).toBeNull();
        expect(parseRole(roleJson({ system_key: 'chef' }))).toBeNull();
        expect(parseRole({ ...roleJson(), usage: {} })).toBeNull();
        expect(parseCatalog({ areas: [], levels: [], action_groups: [] })).toBeNull();
        expect(
            parseFilterOptions({
                locations: [
                    {
                        id: 1,
                        name: 'HLF 20',
                        kind: 'vehicle',
                        kind_label: 'Fahrzeug',
                        active: true,
                        children: [{ id: 2, name: 'G1', kind_label: 'Fahrzeug', active: false }],
                    },
                ],
                device_types: [{ id: 3, name: 'Funkgerät', active: true }],
            })?.locations[0]?.children[0]?.active,
        ).toBe(false);
    });
});

describe('Formular', () => {
    it('übernimmt eine Rolle und vergleicht ohne Reihenfolge', () => {
        const current = roleForm(role());
        expect(current.locationsMode).toBe('all');
        expect(current.typesMode).toBe('some');
        expect(current.deviceTypes).toEqual([3]);
        expect(sameRoleForm(current, { ...current, actions: ['device.edit', 'book.move'] })).toBe(
            true,
        );
        expect(sameRoleForm(current, { ...current, areas: { search: 'read' } })).toBe(false);
        // Die Auswahl bei „Alle“ zählt nicht.
        expect(sameRoleForm(current, { ...current, locations: [9] })).toBe(true);
    });

    it('Body: getrimmt, Reihenfolge des Katalogs, Filter null bei „Alle“', () => {
        expect(
            roleRequest(
                form({
                    name: '  Wart ',
                    description: '  ',
                    areas: { devices: 'write', dashboard: 'read' },
                    actions: ['device.edit', 'book.move'],
                    locationsMode: 'some',
                    locations: [9, 2],
                    deviceTypes: [5],
                }),
                catalog(),
            ),
        ).toEqual({
            name: 'Wart',
            description: null,
            areas: { dashboard: 'read', devices: 'write' },
            actions: ['book.move', 'device.edit'],
            locations: [2, 9],
            deviceTypes: null,
        });
    });

    it('Prüfung beim Tippen mit den Meldungen des Servers', () => {
        const problems = roleFormProblems(
            form({
                name: ' ',
                description: 'x'.repeat(201),
                locationsMode: 'some',
                typesMode: 'some',
            }),
            catalog(),
        );
        expect(problems.name).toBe('Bitte einen Namen eingeben.');
        expect(problems.description).toBe('Höchstens 200 Zeichen (jetzt 201).');
        expect(problems.locations).toBe(
            'Bitte mindestens einen Standort wählen oder „Alle Standorte“.',
        );
        expect(problems.deviceTypes).toBe(
            'Bitte mindestens einen Ausrüstungstyp wählen oder „Alle Ausrüstungstypen“.',
        );
        expect(hasProblems(roleFormProblems(form(), catalog()))).toBe(false);
    });

    it('F3: mit „Sieht nur …“ keine Verwaltung, wie RoleDraft::filterConflicts', () => {
        const filtered = form({
            areas: { users: 'read', locations: 'write', device_types: 'read', devices: 'write' },
            actions: ['csv.import', 'device.edit'],
            typesMode: 'some',
            deviceTypes: [3],
        });
        expect(roleFormProblems(filtered, catalog()).conflicts).toBe(
            'Mit „Sieht nur …“ darf die Rolle nicht verwalten: „Standorte: Ändern“, „Benutzer: Ansehen“, „CSV-Import“. Bitte diese Rechte entfernen oder „Alle Standorte“ und „Alle Ausrüstungstypen“ wählen.',
        );
        expect(roleFormProblems({ ...filtered, typesMode: 'all' }, catalog()).conflicts).toBeNull();

        const users = catalog().areas.find((area) => area.key === 'users');
        const locations = catalog().areas.find((area) => area.key === 'locations');
        if (users === undefined || locations === undefined) {
            throw new Error('Bereich fehlt');
        }
        // Gewählte bleiben bedienbar (zum Ändern), andere verbotene sind grau.
        expect(levelOptions(catalog(), users, filtered).map((o) => [o.label, o.disabled])).toEqual([
            ['Kein Zugriff', false],
            ['Ansehen', false],
            ['Ändern', true],
        ]);
        expect(
            levelOptions(catalog(), locations, filtered).map((o) => [o.pressed, o.disabled]),
        ).toEqual([
            [false, false],
            [false, false],
            [true, false],
        ]);

        const csv = allActions(catalog()).find((action) => action.key === 'csv.import');
        if (csv === undefined) {
            throw new Error('Aktion fehlt');
        }
        expect(actionState(catalog(), filtered, csv)).toEqual({
            checked: true,
            disabled: false,
            note: 'Nicht mit „Sieht nur …“.',
        });
        expect(actionState(catalog(), { ...filtered, actions: [] }, csv).disabled).toBe(true);
    });

    it('Aktion ohne ihren Bereich ist grau und bleibt angehakt', () => {
        const move = allActions(catalog()).find((action) => action.key === 'book.move');
        if (move === undefined) {
            throw new Error('Aktion fehlt');
        }
        expect(actionState(catalog(), form({ actions: ['book.move'] }), move)).toEqual({
            checked: true,
            disabled: true,
            note: 'braucht „Suchen und Ausrüstung ansehen: Ansehen“',
        });
        expect(
            actionState(catalog(), form({ areas: { search: 'read' }, actions: [] }), move).disabled,
        ).toBe(false);
    });
});

describe('Vorschau und Tabelle', () => {
    it('„So sieht es die Rolle“', () => {
        expect(
            rolePreview(
                catalog(),
                form({ areas: { search: 'read' }, actions: ['book.move'] }),
                null,
            ),
        ).toEqual({
            menu: [],
            bar: ['Suchen', 'Profil'],
            may: 'Umbuchen',
            sees: 'alle Standorte, alle Ausrüstungstypen',
        });
        const options = {
            locations: [
                {
                    id: 1,
                    name: 'HLF 20',
                    kindLabel: 'Fahrzeug',
                    active: true,
                    children: [
                        { id: 2, name: 'G1', kindLabel: 'Fahrzeug', active: true, children: [] },
                    ],
                },
            ],
            deviceTypes: [{ id: 3, name: 'Funkgerät', active: true }],
        };
        expect(
            rolePreview(
                catalog(),
                form({
                    areas: { dashboard: 'read', due: 'write', users: 'read' },
                    locationsMode: 'some',
                    locations: [2],
                }),
                options,
            ),
        ).toEqual({
            // Benutzer gibt es mit „Sieht nur …“ nicht (F3).
            menu: ['Dashboard', 'Termine'],
            bar: ['Termine', 'Dashboard', 'Profil'],
            may: 'nichts ändern, nur ansehen',
            sees: '1 von 2 Standorten, alle Ausrüstungstypen',
        });
    });

    it('Kurzfassung je Rolle', () => {
        expect(roleSummary(catalog(), role({ all_rights: true, system_key: 'admin' }))).toEqual({
            sees: 'alles',
            may: 'alles',
            filter: 'alle Standorte, alle Ausrüstungstypen',
        });
        expect(roleSummary(catalog(), role())).toEqual({
            sees: 'Suchen, Ausrüstung, Profil',
            may: 'Umbuchen, Stammdaten bearbeiten',
            filter: 'alle Standorte, 1 Ausrüstungstyp',
        });
        expect(filterSummary([], null, null)).toBe('kein Standort, alle Ausrüstungstypen');
    });

    it('Löschen nur ohne Nutzung', () => {
        expect(deleteBlocker(role())).toBe(
            'Löschen geht erst, wenn niemand die Rolle hat (3 Personen, 1 offener Einladungscode).',
        );
        expect(deleteBlocker(role({ usage: { accounts: 0, invitation_codes: 0 } }))).toBeNull();
        expect(deleteBlocker(role({ system_key: 'user', deletable: false }))).toBe(
            'Die Standard-Rolle lässt sich nicht löschen.',
        );
    });
});
