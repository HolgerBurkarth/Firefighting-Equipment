import type {
    AreaLevel,
    CatalogAction,
    CatalogArea,
    FilterOptions,
    PermissionCatalog,
    Role,
    RoleRequest,
} from '../api/roles';
import { nameProblem, phpTrim } from './masterData';

/**
 * Rollen im Bereich des Super-Admins (Plan Phase 1, Schritt 1.5): Formular des Editors, Prüfung beim
 * Tippen wie RoleDraft (nie strenger), F3 (keine Verwaltung mit „Sieht nur …“) wie der Server, Vorschau
 * „So sieht es die Rolle“ und Kurzfassung für die Tabelle. Verbindlich bleibt der Server.
 */

/** = RoleDraft::MAX_NAME_LENGTH (per Test). */
export const ROLE_NAME_MAX_LENGTH = 50;
/** = RoleDraft::MAX_DESCRIPTION_LENGTH (per Test). */
export const ROLE_DESCRIPTION_MAX_LENGTH = 200;

export type FilterMode = 'all' | 'some';

export interface RoleForm {
    readonly name: string;
    readonly description: string;
    /** Bereich → Stufe; fehlt = kein Zugriff. */
    readonly areas: Readonly<Record<string, AreaLevel>>;
    readonly actions: readonly string[];
    readonly locationsMode: FilterMode;
    readonly locations: readonly number[];
    readonly typesMode: FilterMode;
    readonly deviceTypes: readonly number[];
}

export function emptyRoleForm(): RoleForm {
    return {
        name: '',
        description: '',
        areas: {},
        actions: [],
        locationsMode: 'all',
        locations: [],
        typesMode: 'all',
        deviceTypes: [],
    };
}

export function roleForm(role: Role): RoleForm {
    return {
        name: role.name,
        description: role.description ?? '',
        areas: { ...role.areas },
        actions: [...role.actions],
        locationsMode: role.locations === null ? 'all' : 'some',
        locations: [...(role.locations ?? [])],
        typesMode: role.deviceTypes === null ? 'all' : 'some',
        deviceTypes: [...(role.deviceTypes ?? [])],
    };
}

/** Gleicher Stand (für ungespeicherte Eingaben)? Reihenfolge der Auswahl zählt nicht. */
export function sameRoleForm(a: RoleForm, b: RoleForm): boolean {
    const sorted = (values: readonly (number | string)[]): string =>
        JSON.stringify([...values].map(String).sort());
    const areas = (form: RoleForm): string =>
        JSON.stringify(Object.entries(form.areas).sort(([x], [y]) => x.localeCompare(y)));
    return (
        a.name === b.name &&
        a.description === b.description &&
        areas(a) === areas(b) &&
        sorted(a.actions) === sorted(b.actions) &&
        a.locationsMode === b.locationsMode &&
        a.typesMode === b.typesMode &&
        (a.locationsMode === 'all' || sorted(a.locations) === sorted(b.locations)) &&
        (a.typesMode === 'all' || sorted(a.deviceTypes) === sorted(b.deviceTypes))
    );
}

export function isFiltered(form: RoleForm): boolean {
    return form.locationsMode === 'some' || form.typesMode === 'some';
}

function areaOf(catalog: PermissionCatalog, key: string): CatalogArea | undefined {
    return catalog.areas.find((area) => area.key === key);
}

export function allActions(catalog: PermissionCatalog): CatalogAction[] {
    return catalog.actionGroups.flatMap((group) => group.actions);
}

/** Ändern schließt Ansehen ein. */
export function levelIncludes(level: AreaLevel | undefined, needed: AreaLevel): boolean {
    return level === 'write' || (level === 'read' && needed === 'read');
}

/** Stufe eines Bereichs, die es mit „Sieht nur …“ nicht gibt (F3)? */
function excludedWithFilter(area: CatalogArea, level: AreaLevel): boolean {
    return !area.levelsWithFilter.includes(level);
}

/** Body für POST: Name und Kurzbeschreibung getrimmt wie PHP, Reihenfolge des Katalogs. */
export function roleRequest(form: RoleForm, catalog: PermissionCatalog): RoleRequest {
    const areas: Record<string, AreaLevel> = {};
    for (const area of catalog.areas) {
        const level = form.areas[area.key];
        if (level !== undefined) {
            areas[area.key] = level;
        }
    }
    const description = phpTrim(form.description);
    return {
        name: phpTrim(form.name),
        description: description === '' ? null : description,
        areas,
        actions: allActions(catalog)
            .map((action) => action.key)
            .filter((key) => form.actions.includes(key)),
        locations: form.locationsMode === 'all' ? null : [...form.locations].sort((x, y) => x - y),
        deviceTypes: form.typesMode === 'all' ? null : [...form.deviceTypes].sort((x, y) => x - y),
    };
}

export interface RoleFormProblems {
    readonly name: string | null;
    readonly description: string | null;
    readonly locations: string | null;
    readonly deviceTypes: string | null;
    /** F3: Rechte, die es mit „Sieht nur …“ nicht gibt. */
    readonly conflicts: string | null;
}

/** Bezeichnungen der Rechte, die mit Datenfilter nicht gehen (wie RoleDraft::filterConflicts). */
export function filterConflicts(form: RoleForm, catalog: PermissionCatalog): string[] {
    if (!isFiltered(form)) {
        return [];
    }
    const labels: string[] = [];
    for (const area of catalog.areas) {
        const level = form.areas[area.key];
        if (level !== undefined && excludedWithFilter(area, level)) {
            labels.push(`„${area.label}: ${catalog.levelLabels[level]}“`);
        }
    }
    for (const action of allActions(catalog)) {
        if (!action.withFilter && form.actions.includes(action.key)) {
            labels.push(`„${action.label}“`);
        }
    }
    return labels;
}

export function roleFormProblems(form: RoleForm, catalog: PermissionCatalog): RoleFormProblems {
    const description = phpTrim(form.description);
    const descriptionLength = Array.from(description).length;
    const conflicts = filterConflicts(form, catalog);
    return {
        name: nameProblem(form.name, ROLE_NAME_MAX_LENGTH),
        description:
            descriptionLength > ROLE_DESCRIPTION_MAX_LENGTH
                ? `Höchstens ${String(ROLE_DESCRIPTION_MAX_LENGTH)} Zeichen (jetzt ${String(descriptionLength)}).`
                : /\p{Cc}/u.test(description)
                  ? 'Keine Zeilenumbrüche oder Steuerzeichen.'
                  : null,
        locations:
            form.locationsMode === 'some' && form.locations.length === 0
                ? 'Bitte mindestens einen Standort wählen oder „Alle Standorte“.'
                : null,
        deviceTypes:
            form.typesMode === 'some' && form.deviceTypes.length === 0
                ? 'Bitte mindestens einen Ausrüstungstyp wählen oder „Alle Ausrüstungstypen“.'
                : null,
        conflicts:
            conflicts.length === 0
                ? null
                : `Mit „Sieht nur …“ darf die Rolle nicht verwalten: ${conflicts.join(', ')}. Bitte diese Rechte entfernen oder „Alle Standorte“ und „Alle Ausrüstungstypen“ wählen.`,
    };
}

export function hasProblems(problems: RoleFormProblems): boolean {
    return Object.values(problems).some((problem) => problem !== null);
}

/** Knopf einer Stufe (null = „Kein Zugriff“). */
export interface LevelOption {
    readonly level: AreaLevel | null;
    readonly label: string;
    readonly pressed: boolean;
    /** Mit „Sieht nur …“ nicht wählbar (gewählt bleibt er bedienbar, um ihn zu ändern). */
    readonly disabled: boolean;
}

export function levelOptions(
    catalog: PermissionCatalog,
    area: CatalogArea,
    form: RoleForm,
): LevelOption[] {
    const current = form.areas[area.key] ?? null;
    const filtered = isFiltered(form);
    return [null, ...area.levels].map((level) => ({
        level,
        label: level === null ? 'Kein Zugriff' : catalog.levelLabels[level],
        pressed: current === level,
        disabled:
            level !== null && filtered && excludedWithFilter(area, level) && current !== level,
    }));
}

/** Hinweis an einem Bereich, der mit „Sieht nur …“ eingeschränkt ist. */
export function filterNote(area: CatalogArea, catalog: PermissionCatalog): string | null {
    const missing = area.levels.filter((level) => excludedWithFilter(area, level));
    if (missing.length === 0) {
        return null;
    }
    if (area.levelsWithFilter.length === 0) {
        return 'Mit „Sieht nur …“ kein Zugriff.';
    }
    const highest = area.levelsWithFilter.includes('write') ? 'write' : 'read';
    return `Mit „Sieht nur …“ höchstens „${catalog.levelLabels[highest]}“.`;
}

export interface ActionState {
    readonly checked: boolean;
    readonly disabled: boolean;
    /** Warum grau: „braucht „Bereich: Stufe““ bzw. F3. */
    readonly note: string | null;
}

export function actionState(
    catalog: PermissionCatalog,
    form: RoleForm,
    action: CatalogAction,
): ActionState {
    const checked = form.actions.includes(action.key);
    const blockedByFilter = isFiltered(form) && !action.withFilter;
    if (blockedByFilter) {
        // Angehakt bleibt es bedienbar, damit es sich entfernen lässt.
        return { checked, disabled: !checked, note: 'Nicht mit „Sieht nur …“.' };
    }
    const required = action.requires;
    if (required !== null && !levelIncludes(form.areas[required.area], required.level)) {
        const area = areaOf(catalog, required.area);
        return {
            checked,
            disabled: true,
            note: `braucht „${area?.label ?? required.area}: ${catalog.levelLabels[required.level]}“`,
        };
    }
    return { checked, disabled: false, note: null };
}

/** Aktionen, die mit den gewählten Bereichen und dem Filter wirken. */
export function effectiveActions(catalog: PermissionCatalog, form: RoleForm): CatalogAction[] {
    return allActions(catalog).filter((action) => {
        const state = actionState(catalog, form, action);
        return state.checked && state.note === null;
    });
}

/** Kurzname eines Bereichs im Menü bzw. in der Tabelle (Begriffe nach Konzept Abschnitt 9). */
const AREA_MENU_LABELS: Readonly<Record<string, string>> = {
    dashboard: 'Dashboard',
    search: 'Suchen',
    devices: 'Ausrüstung',
    locations: 'Standorte',
    device_types: 'Ausrüstungstypen',
    due: 'Termine',
    log: 'Protokoll',
    users: 'Benutzer',
    settings: 'Einstellungen',
};

/** Bereiche mit Handy-Seiten („Suchen“, „Termine“). */
const HANDY_AREAS: readonly string[] = ['search', 'due'];

function menuLabel(area: CatalogArea): string {
    return AREA_MENU_LABELS[area.key] ?? area.label;
}

/** Wirksame Stufe (F3 wie Permissions::level). */
function effectiveLevel(area: CatalogArea, form: RoleForm): AreaLevel | null {
    const level = form.areas[area.key];
    if (level === undefined) {
        return null;
    }
    if (!isFiltered(form) || !excludedWithFilter(area, level)) {
        return level;
    }
    return area.levelsWithFilter.includes('read') ? 'read' : null;
}

function countLabel(count: number, one: string, many: string): string {
    return `${String(count)} ${count === 1 ? one : many}`;
}

/** Wie viele Standorte (Ebene 1 und Fächer) die Auswahl nennt bzw. es gibt. */
function locationCount(options: FilterOptions): number {
    return options.locations.reduce((sum, location) => sum + 1 + location.children.length, 0);
}

export interface RolePreview {
    /** „Menü am PC“: Seiten der Konsole; leer = keine Konsole. */
    readonly menu: readonly string[];
    /** „Leiste am Handy“. */
    readonly bar: readonly string[];
    /** „Darf“. */
    readonly may: string;
    /** „Sieht Ausrüstung“. */
    readonly sees: string;
}

export function rolePreview(
    catalog: PermissionCatalog,
    form: RoleForm,
    options: FilterOptions | null,
): RolePreview {
    const visible = catalog.areas.filter((area) => effectiveLevel(area, form) !== null);
    // Suchen und Termine gibt es auch als Handy-Seiten; eine Konsole (und „Dashboard“ in der Leiste,
    // F8) erst mit einem anderen Bereich, wie heute bei der Standard-Rolle Benutzer.
    const hasConsole = visible.some((area) => !HANDY_AREAS.includes(area.key));
    const menu = hasConsole ? visible.filter((area) => area.key !== 'search').map(menuLabel) : [];
    const bar: string[] = [];
    if (visible.some((area) => area.key === 'search')) {
        bar.push('Suchen');
    }
    if (visible.some((area) => area.key === 'due')) {
        bar.push('Termine');
    }
    if (menu.length > 0) {
        bar.push('Dashboard');
    }
    bar.push('Profil');
    const actions = effectiveActions(catalog, form).map((action) => action.label);
    return {
        menu,
        bar,
        may: actions.length === 0 ? 'nichts ändern, nur ansehen' : actions.join(', '),
        sees: filterSummary(
            form.locationsMode === 'all' ? null : form.locations,
            form.typesMode === 'all' ? null : form.deviceTypes,
            options,
        ),
    };
}

/**
 * „alle Standorte, alle Ausrüstungstypen“ bzw. „2 von 9 Standorten, …“ (ohne die Auswahl der Stadt
 * nur die Anzahl).
 */
export function filterSummary(
    locations: readonly number[] | null,
    deviceTypes: readonly number[] | null,
    options: FilterOptions | null,
): string {
    const locationText =
        locations === null
            ? 'alle Standorte'
            : locations.length === 0
              ? 'kein Standort'
              : options === null
                ? countLabel(locations.length, 'Standort', 'Standorte')
                : `${String(locations.length)} von ${String(locationCount(options))} Standorten`;
    const typeText =
        deviceTypes === null
            ? 'alle Ausrüstungstypen'
            : deviceTypes.length === 0
              ? 'kein Ausrüstungstyp'
              : options === null
                ? countLabel(deviceTypes.length, 'Ausrüstungstyp', 'Ausrüstungstypen')
                : `${String(deviceTypes.length)} von ${String(options.deviceTypes.length)} Ausrüstungstypen`;
    return `${locationText}, ${typeText}`;
}

/** Zeile der Tabelle „Rollen“: Sieht, Darf, Sieht nur …. */
export interface RoleSummary {
    readonly sees: string;
    readonly may: string;
    readonly filter: string;
}

export function roleSummary(catalog: PermissionCatalog, role: Role): RoleSummary {
    if (role.allRights) {
        return { sees: 'alles', may: 'alles', filter: filterSummary(null, null, null) };
    }
    const preview = rolePreview(catalog, roleForm(role), null);
    const areas = catalog.areas
        .filter((area) => effectiveLevel(area, roleForm(role)) !== null)
        .map(menuLabel);
    return {
        sees: [...areas, 'Profil'].join(', '),
        may: preview.may,
        filter: filterSummary(role.locations, role.deviceTypes, null),
    };
}

/** „1 Person“ / „3 Personen“. */
export function peopleText(count: number): string {
    return countLabel(count, 'Person', 'Personen');
}

/** Warum eine Rolle nicht löschbar ist; null = löschbar. */
export function deleteBlocker(role: Role): string | null {
    if (role.systemKey === 'admin') {
        return 'Die Rolle „Admin“ lässt sich nicht löschen.';
    }
    if (role.systemKey === 'user') {
        return 'Die Standard-Rolle lässt sich nicht löschen.';
    }
    const parts: string[] = [];
    if (role.accounts > 0) {
        parts.push(peopleText(role.accounts));
    }
    if (role.invitationCodes > 0) {
        parts.push(
            countLabel(role.invitationCodes, 'offener Einladungscode', 'offene Einladungscodes'),
        );
    }
    return parts.length === 0
        ? null
        : `Löschen geht erst, wenn niemand die Rolle hat (${parts.join(', ')}).`;
}
