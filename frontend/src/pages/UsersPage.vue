<script setup lang="ts">
import { TabsContent, TabsList, TabsRoot, TabsTrigger } from 'reka-ui';
import { computed, onMounted, ref, shallowRef, useId, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import type { ApiError, ApiResult } from '../api/client';
import type { AssignableRole } from '../api/roles';
import {
    CLAUDE_ACCESS_LABELS,
    CLAUDE_ACCESS_VALUES,
    type AdminUser,
    type ClaudeAccess,
} from '../api/users';
import { allows } from '../api/permissions';
import { currentPermissions, session } from '../auth/session';
import ConfirmDialog from '../components/ConfirmDialog.vue';
import ErrorNotice from '../components/ErrorNotice.vue';
import FormDialog from '../components/FormDialog.vue';
import InvitationCodesPanel from '../components/InvitationCodesPanel.vue';
import {
    adminLocationStore,
    approveUser,
    assignableRoleStore,
    bulkAssignRole,
    deleteUser,
    deviceTypeStore,
    endClaudeConnections,
    rejectUser,
    saveUser,
    setClaudeAccess,
    setUserLocked,
    userStore,
    type BulkRoleRun,
} from '../data';
import { filterText, roleFilterOptions, roleNameOf, seesText } from '../data/assignableRoles';
import { phpTrim } from '../data/masterData';
import {
    bulkRoleText,
    claudeAccessBlocker,
    claudeChip,
    claudeConnectionText,
    createdText,
    displayNameProblem,
    nameHint,
    pendingText,
    pendingUsers,
    registrationText,
    selectable,
    selectedText,
    statusText,
    userChanges,
    userRows,
    type SortDir,
    type UserSortKey,
} from '../data/users';
import { useNow } from '../runtime/now';
import { tenantState } from '../runtime/tenantState';
import { useUnsavedInput } from '../runtime/unsavedInput';

/**
 * Benutzerverwaltung (Admin, `benutzer`; KONZEPT.md, "Bereiche des Admins", Benutzer; Plan Phase 1,
 * Schritt 1.6, Mockups „Benutzer“ und „Benutzer-Rolle“): oben die wartenden Registrierungen mit
 * „Freischalten“ (Anzeigename und Rolle, die des Einladungscodes vorausgewählt) und „Ablehnen“,
 * darunter alle Konten der Stadt mit Rollenfilter, sortierbar wie im Explorer (am Handy Karten mit
 * Sortier-Knöpfen). Kästchen je Konto (nicht am eigenen) für „Rolle zuweisen …“ (F6). Der Stift
 * öffnet Anzeigename und Rolle, abseits Sperren/Entsperren und Löschen (= anonymisieren). Welche
 * Rollen es gibt, legt der Super-Admin fest; welche das Konto vergeben darf, sagt der Server (F5).
 * Zweiter Reiter „Einladungscodes“ (`?ansicht=einladungscodes`, InvitationCodesPanel).
 * Claude-Zugang (docs/MCP-KONZEPT.md, Abschnitt 2, F12; nur wo die Stadt ihn hat): Chip und
 * Verbindungen in der Liste; im Fenster „Bearbeiten“ Aus/Lesen/Ändern und abseits „Trennen“, beides
 * nur für Konten der Rolle Admin (Admins und das eigene Konto gesperrt mit Hinweis).
 */
const id = useId();
const route = useRoute();
const router = useRouter();

type Tab = 'accounts' | 'codes';
const CODES_VIEW = 'einladungscodes';
/** Reiter in der Adresse (Neuladen und Zurück behalten ihn; gleicher Pfad, kein Update). */
const tab = computed<Tab>({
    get: () => (route.query['ansicht'] === CODES_VIEW ? 'codes' : 'accounts'),
    set: (value) => {
        void router.replace({ query: value === 'codes' ? { ansicht: CODES_VIEW } : {} });
    },
});

function selectTab(value: string | number): void {
    tab.value = value === 'codes' ? 'codes' : 'accounts';
}
const state = computed(() => userStore.state);
const users = computed(() => state.value.users ?? []);
const ownId = computed(() => session.value?.user.id ?? null);
/** Freischalten, Bearbeiten, Rolle zuweisen nur mit „Benutzer: Ändern“ (Plan Phase 1, 1.7b). */
const canWrite = computed(() => allows(currentPermissions.value, 'users', 'write'));
const pending = computed(() => pendingUsers(users.value));
const now = useNow();
/** Claude-Zugang verwalten: Stadt hat ihn (bisher nur die Test-Stadt) und die Sitzung hat die Rolle Admin. */
const managesClaude = computed(
    () =>
        tenantState.value.tenant?.claudeAvailable === true &&
        session.value?.user.role === 'admin' &&
        canWrite.value,
);

const roleState = computed(() => assignableRoleStore.state);
const roles = computed(() => roleState.value.data?.roles ?? []);
const catalog = computed(() => roleState.value.data?.catalog ?? null);
const roleFilter = ref<number | null>(null);
const roleOptions = computed(() => roleFilterOptions(users.value, roles.value));

const query = ref('');
const sort = ref<UserSortKey>('name');
const dir = ref<SortDir>('asc');
const rows = computed(() =>
    userRows(users.value, query.value, sort.value, dir.value, roleFilter.value),
);
const others = computed(() => users.value.filter((user) => user.status !== 'pending').length);

const COLUMNS: readonly { key: UserSortKey; label: string }[] = [
    { key: 'name', label: 'Name' },
    { key: 'email', label: 'E-Mail' },
    { key: 'role', label: 'Rolle' },
    { key: 'status', label: 'Status' },
    { key: 'created', label: 'Registriert' },
];

/** Klick auf den Spaltenkopf: aufsteigend, zweiter Klick absteigend (KONZEPT.md, "Listen"). */
function sortBy(key: UserSortKey): void {
    if (sort.value === key) {
        dir.value = dir.value === 'asc' ? 'desc' : 'asc';
    } else {
        sort.value = key;
        dir.value = 'asc';
    }
}

function ariaSort(key: UserSortKey): 'ascending' | 'descending' | 'none' {
    if (sort.value !== key) {
        return 'none';
    }
    return dir.value === 'asc' ? 'ascending' : 'descending';
}

/** Namen für „Sieht nur …“; die Listen der Konsole lädt die Seite nur, wenn eine Rolle filtert. */
function locationName(locationId: number): string | null {
    for (const location of adminLocationStore.state.locations ?? []) {
        if (location.id === locationId) {
            return location.name;
        }
        const child = location.children.find((sub) => sub.id === locationId);
        if (child !== undefined) {
            return `${location.name} / ${child.name}`;
        }
    }
    return null;
}

function typeName(typeId: number): string | null {
    return deviceTypeStore.state.types?.find((type) => type.id === typeId)?.name ?? null;
}

watch(roles, (list) => {
    if (list.some((role) => role.locations !== null)) {
        void adminLocationStore.refresh('open');
    }
    if (list.some((role) => role.deviceTypes !== null)) {
        void deviceTypeStore.refresh('open');
    }
});

function roleFilterText(role: AssignableRole): string | null {
    return filterText(role, locationName, typeName);
}

function roleSees(role: AssignableRole): string {
    return catalog.value === null ? '' : seesText(catalog.value, role);
}

const busy = ref(false);
const success = ref<string | null>(null);
const pageError = ref<ApiError | null>(null);
/** Knopf, der ein Fenster geöffnet hat (bekommt den Fokus zurück). */
const opener = ref<HTMLElement | null>(null);

function startAction(event: Event): void {
    opener.value = event.currentTarget instanceof HTMLElement ? event.currentTarget : null;
    success.value = null;
    pageError.value = null;
}

// Fenster „Freischalten“ bzw. „Bearbeiten“: gleiche Felder, eigenes Ziel.
type Mode = 'approve' | 'edit';
const mode = ref<Mode>('approve');
const target = ref<AdminUser | null>(null);
const dialogOpen = ref(false);
const displayName = ref('');
const roleId = ref<number | null>(null);
const touched = ref(false);
const nameError = ref<string | null>(null);
const dialogError = ref<ApiError | null>(null);
const claudeAccess = ref<ClaudeAccess>('off');

const isOwn = computed(() => target.value !== null && target.value.id === ownId.value);
/** Anzeigename nur bei aktiven Konten (eingeladene bekommen ihn beim Annehmen der Einladung). */
const editsName = computed(
    () => mode.value === 'approve' || (target.value !== null && target.value.status === 'active'),
);
const initial = computed((): { displayName: string; roleId: number | null } => ({
    displayName:
        mode.value === 'approve'
            ? (target.value?.name ?? '')
            : (target.value?.displayName ?? target.value?.name ?? ''),
    roleId: target.value?.roleId ?? null,
}));
const chosenRole = computed(() => roles.value.find((role) => role.id === roleId.value) ?? null);
const roleChanged = computed(() => roleId.value !== initial.value.roleId);
/** Auswahl des Claude-Zugangs im Fenster „Bearbeiten“ (nur mit managesClaude). */
const showsClaude = computed(() => mode.value === 'edit' && managesClaude.value);
const claudeBlocker = computed(() =>
    target.value === null ? null : claudeAccessBlocker(target.value, ownId.value),
);
const claudeChanged = computed(
    () => showsClaude.value && claudeAccess.value !== (target.value?.claudeAccess ?? 'off'),
);

/** Ungespeicherte Eingaben im Fenster (auch für FormDialog: Klick daneben, Escape). */
const unsaved = computed(
    () =>
        dialogOpen.value &&
        (displayName.value !== initial.value.displayName ||
            roleChanged.value ||
            claudeChanged.value),
);

const liveProblem = computed(() =>
    editsName.value ? displayNameProblem(displayName.value) : null,
);
const shownNameError = computed(
    () => nameError.value ?? (touched.value ? liveProblem.value : null),
);
/** Freischalten braucht eine Rolle (fehlt sie, sind die Rollen nicht geladen). */
const canSubmit = computed(
    () =>
        (!touched.value || liveProblem.value === null) &&
        (mode.value === 'edit' || roleId.value !== null),
);

function openDialog(user: AdminUser, kind: Mode, event: Event): void {
    startAction(event);
    mode.value = kind;
    target.value = user;
    displayName.value = initial.value.displayName;
    roleId.value = initial.value.roleId;
    touched.value = false;
    nameError.value = null;
    dialogError.value = null;
    claudeAccess.value = user.claudeAccess;
    dialogOpen.value = true;
    void assignableRoleStore.refresh('open');
}

const dialogTitle = computed(() => {
    const user = target.value;
    if (user === null) {
        return '';
    }
    return mode.value === 'approve' ? `${user.label} freischalten` : `${user.label} bearbeiten`;
});

const dialogDescription = computed(() => {
    const user = target.value;
    if (user === null) {
        return null;
    }
    const email = user.email ?? '';
    return mode.value === 'approve'
        ? `${email} · ${registrationText(user)}`
        : `${email} · ${statusText(user)}`;
});

/** Eine Rolle ist wählbar, wenn das Konto sie vergeben darf; die bisherige bleibt sichtbar gewählt. */
function roleDisabled(role: AssignableRole): boolean {
    return !role.assignable && role.id !== initial.value.roleId;
}

/** Fehler, die zu keinem Feld gehören; Meldungen des Anzeigenamens (400) stehen am Feld. */
function handleError(error: ApiError): void {
    if (
        error.code === 'invalid_request' &&
        editsName.value &&
        !(error.message ?? '').includes('role_id')
    ) {
        nameError.value = error.message;
    } else {
        dialogError.value = error;
    }
}

async function submit(): Promise<void> {
    const user = target.value;
    touched.value = true;
    nameError.value = null;
    dialogError.value = null;
    if (user === null || liveProblem.value !== null || busy.value) {
        return;
    }
    const name = phpTrim(displayName.value);
    busy.value = true;
    let result: ApiResult<AdminUser> | null;
    if (mode.value === 'approve') {
        if (roleId.value === null) {
            busy.value = false;
            return;
        }
        result = await approveUser(user.id, { displayName: name, roleId: roleId.value });
    } else {
        result = await saveUser(user, userChanges(user, displayName.value, roleId.value));
        // Zugang nach Name und Rolle (ein Wechsel zu Admin setzt ihn ohnehin auf „aus“).
        if ((result === null || result.ok) && claudeChanged.value) {
            result = await setClaudeAccess(user.id, claudeAccess.value);
        }
    }
    busy.value = false;
    if (result === null) {
        dialogOpen.value = false;
        return;
    }
    if (!result.ok) {
        handleError(result.error);
        return;
    }
    const saved = result.data;
    if (mode.value === 'approve') {
        success.value = `${saved.label} ist freigeschaltet (${roleNameOf(saved)}) und erhält eine E-Mail.`;
    } else if (saved.role !== user.role) {
        success.value = `${saved.label} hat jetzt die Rolle „${roleNameOf(saved)}“ und muss sich neu anmelden.`;
    } else if (saved.roleId !== user.roleId) {
        success.value = `${saved.label} hat jetzt die Rolle „${roleNameOf(saved)}“; sie gilt beim nächsten Aufruf der App.`;
    } else if (saved.claudeAccess !== user.claudeAccess) {
        success.value =
            saved.claudeAccess === 'off'
                ? `${saved.label} hat keinen Claude-Zugang mehr; bestehende Verbindungen sind getrennt.`
                : `Claude-Zugang von ${saved.label}: ${CLAUDE_ACCESS_LABELS[saved.claudeAccess]}.`;
    } else {
        success.value = `Änderungen an ${saved.label} gespeichert.`;
    }
    dialogOpen.value = false;
}

// Mehrfachauswahl: nur „Rolle zuweisen …“ (F6).
const selected = shallowRef<ReadonlySet<number>>(new Set());
const selectedUsers = computed(() => users.value.filter((user) => selected.value.has(user.id)));
const visibleSelectable = computed(() =>
    rows.value.filter((user) => selectable(user, ownId.value)),
);
const allVisibleSelected = computed(
    () =>
        visibleSelectable.value.length > 0 &&
        visibleSelectable.value.every((user) => selected.value.has(user.id)),
);
const someVisibleSelected = computed(
    () =>
        !allVisibleSelected.value &&
        visibleSelectable.value.some((user) => selected.value.has(user.id)),
);

function toggle(user: AdminUser): void {
    const next = new Set(selected.value);
    if (next.has(user.id)) {
        next.delete(user.id);
    } else {
        next.add(user.id);
    }
    selected.value = next;
}

function toggleVisible(): void {
    const next = new Set(selected.value);
    for (const user of visibleSelectable.value) {
        if (allVisibleSelected.value) {
            next.delete(user.id);
        } else {
            next.add(user.id);
        }
    }
    selected.value = next;
}

function clearSelection(): void {
    selected.value = new Set();
}

// Konten verschwinden (gelöscht, abgelehnt): aus der Auswahl nehmen.
watch(users, (list) => {
    const ids = new Set(list.map((user) => user.id));
    if ([...selected.value].some((selectedId) => !ids.has(selectedId))) {
        selected.value = new Set([...selected.value].filter((selectedId) => ids.has(selectedId)));
    }
});

const bulkOpen = ref(false);
const bulkRoleId = ref<number | null>(null);
const bulkError = ref<ApiError | null>(null);
const bulkButton = ref<HTMLElement | null>(null);
const bulkUnsaved = computed(() => bulkOpen.value && bulkRoleId.value !== null);
useUnsavedInput(() => unsaved.value || bulkUnsaved.value);
const bulkResult = shallowRef<{
    readonly roleName: string;
    readonly run: BulkRoleRun;
    readonly labels: ReadonlyMap<number, string>;
} | null>(null);
const bulkFailures = computed(() =>
    (bulkResult.value?.run.results ?? [])
        .filter((result) => result.error !== null)
        .map((result) => ({
            id: result.id,
            label:
                result.user?.label ??
                bulkResult.value?.labels.get(result.id) ??
                `#${String(result.id)}`,
            text: result.error?.message ?? '',
        })),
);
const bulkClean = computed(
    () =>
        bulkResult.value !== null &&
        bulkResult.value.run.error === null &&
        bulkFailures.value.length === 0,
);

function openBulk(event: Event): void {
    startAction(event);
    bulkRoleId.value = null;
    bulkError.value = null;
    bulkOpen.value = true;
    void assignableRoleStore.refresh('open');
}

async function submitBulk(): Promise<void> {
    const role = roles.value.find((entry) => entry.id === bulkRoleId.value);
    if (role === undefined || busy.value) {
        return;
    }
    const chosen = selectedUsers.value;
    const labels = new Map(chosen.map((user) => [user.id, user.label]));
    busy.value = true;
    bulkError.value = null;
    const run = await bulkAssignRole(
        chosen.map((user) => user.id),
        role.id,
    );
    busy.value = false;
    if (run.error !== null && run.results.length === 0) {
        // Nichts geändert (z. B. Rolle nicht vergebbar, keine Verbindung): Fenster bleibt offen.
        bulkError.value = run.error;
        return;
    }
    bulkResult.value = { roleName: role.name, run, labels };
    // Geänderte und unveränderte verlassen die Auswahl; Fehler und nicht gesendete bleiben.
    const remaining = new Set(run.unsent);
    for (const result of run.results) {
        if (result.outcome === 'error') {
            remaining.add(result.id);
        }
    }
    selected.value = remaining;
    bulkOpen.value = false;
}

// Rückfragen: Ablehnen, Sperren, Löschen, Claude-Verbindungen trennen.
type ConfirmKind = 'reject' | 'lock' | 'delete' | 'claude';
const confirmKind = ref<ConfirmKind>('reject');
const confirmTarget = ref<AdminUser | null>(null);
const confirmOpen = ref(false);

function ask(kind: ConfirmKind, user: AdminUser, event?: Event): void {
    if (event !== undefined) {
        startAction(event);
    }
    confirmKind.value = kind;
    confirmTarget.value = user;
    dialogOpen.value = false;
    confirmOpen.value = true;
}

const confirmTexts = computed(() => {
    const user = confirmTarget.value;
    const label = user?.label ?? '';
    switch (confirmKind.value) {
        case 'reject':
            return {
                title: `Registrierung von ${label} ablehnen?`,
                description: `Das Konto wird gelöscht. ${user?.email ?? 'Die Person'} erhält eine E-Mail, dass die Registrierung nicht freigegeben wurde.`,
                confirm: 'Ablehnen',
            };
        case 'lock':
            return {
                title: `${label} sperren?`,
                description: `${label} wird auf allen Geräten abgemeldet und kann sich nicht mehr anmelden, bis Sie das Konto entsperren.`,
                confirm: 'Sperren',
            };
        case 'claude':
            return {
                title: `Claude-Verbindungen von ${label} trennen?`,
                description: `Claude kann danach nicht mehr für ${label} auf die Ausrüstung zugreifen, bis ${label} Claude neu verbindet. Der Claude-Zugang bleibt.`,
                confirm: 'Trennen',
            };
        case 'delete':
            return {
                title: `${label} löschen?`,
                description: `E-Mail, Namen und Anmeldungen werden entfernt. Im Verlauf der Ausrüstung steht danach „Gelöschter Benutzer #${String(user?.id ?? '')}“. Das lässt sich nicht rückgängig machen.`,
                confirm: 'Löschen',
            };
    }
});

async function confirmed(): Promise<void> {
    const user = confirmTarget.value;
    if (user === null) {
        return;
    }
    const kind = confirmKind.value;
    busy.value = true;
    let result: ApiResult<AdminUser>;
    if (kind === 'reject') {
        result = await rejectUser(user.id);
    } else if (kind === 'lock') {
        result = await setUserLocked(user.id, true);
    } else if (kind === 'claude') {
        result = await endClaudeConnections(user.id);
    } else {
        result = await deleteUser(user.id);
    }
    busy.value = false;
    if (!result.ok) {
        pageError.value = result.error;
        return;
    }
    const messages: Readonly<Record<ConfirmKind, string>> = {
        reject: `Die Registrierung von ${user.label} ist abgelehnt.`,
        lock: `${user.label} ist gesperrt.`,
        delete: `${user.label} wurde gelöscht.`,
        claude: `Die Claude-Verbindungen von ${user.label} sind getrennt.`,
    };
    success.value = messages[kind];
}

async function unlock(): Promise<void> {
    const user = target.value;
    if (user === null || busy.value) {
        return;
    }
    busy.value = true;
    const result = await setUserLocked(user.id, false);
    busy.value = false;
    if (!result.ok) {
        dialogError.value = result.error;
        return;
    }
    success.value = `${result.data.label} ist entsperrt.`;
    dialogOpen.value = false;
}

function retry(): void {
    void userStore.refresh('changed');
}

function retryRoles(): void {
    void assignableRoleStore.refresh('changed');
}

onMounted(() => {
    void userStore.refresh('open');
    void assignableRoleStore.refresh('open');
});
</script>

<template>
    <div class="page-head">
        <h1>Benutzer</h1>
    </div>

    <TabsRoot :model-value="tab" class="tabs-root" @update:model-value="selectTab">
        <TabsList class="tabs" aria-label="Benutzerverwaltung">
            <TabsTrigger class="tab" value="accounts">
                Konten
                <span v-if="pending.length > 0" class="badge badge-maintenance">{{
                    pending.length
                }}</span>
            </TabsTrigger>
            <TabsTrigger class="tab" value="codes">Einladungscodes</TabsTrigger>
        </TabsList>
        <TabsContent class="tab-panel" value="accounts">
            <p
                v-if="success !== null"
                class="notice notice-success"
                role="status"
                data-testid="success-notice"
            >
                {{ success }}
            </p>
            <ErrorNotice v-if="pageError !== null" :error="pageError" />

            <ErrorNotice v-if="state.error !== null" :error="state.error">
                <button class="btn retry" type="button" :disabled="state.loading" @click="retry">
                    Erneut versuchen
                </button>
            </ErrorNotice>

            <p v-if="state.users === null && state.error === null" class="hint" role="status">
                Wird geladen …
            </p>

            <section
                v-if="pending.length > 0"
                class="pending"
                aria-labelledby="pending-heading"
                data-testid="pending"
            >
                <h2 id="pending-heading">{{ pendingText(pending.length) }}</h2>
                <ul class="pending-list">
                    <li
                        v-for="user in pending"
                        :key="user.id"
                        class="pending-card"
                        :data-testid="`pending-${String(user.id)}`"
                    >
                        <div class="pending-text">
                            <strong class="user-name">{{ user.label }}</strong>
                            <span class="user-email">{{ user.email }}</span>
                            <span class="hint">{{ registrationText(user) }}</span>
                            <span class="hint">Rolle: {{ roleNameOf(user) }}</span>
                        </div>
                        <div v-if="canWrite" class="pending-actions">
                            <button
                                class="btn btn-accent"
                                type="button"
                                :disabled="busy"
                                :aria-label="`${user.label} freischalten`"
                                @click="openDialog(user, 'approve', $event)"
                            >
                                Freischalten …
                            </button>
                            <button
                                class="btn"
                                type="button"
                                :disabled="busy"
                                :aria-label="`Registrierung von ${user.label} ablehnen`"
                                @click="ask('reject', user, $event)"
                            >
                                Ablehnen …
                            </button>
                        </div>
                    </li>
                </ul>
            </section>

            <div v-if="others > 0" class="toolbar">
                <label class="filter-field">
                    <svg
                        width="20"
                        height="20"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        aria-hidden="true"
                    >
                        <circle cx="11" cy="11" r="7" />
                        <path d="M20 20l-4-4" />
                    </svg>
                    <span class="visually-hidden">Benutzer filtern</span>
                    <input v-model="query" type="search" placeholder="Name oder E-Mail" />
                </label>
                <div
                    v-if="roleOptions.length > 2"
                    class="role-filter"
                    role="group"
                    aria-label="Nach Rolle filtern"
                >
                    <span class="sort-label" aria-hidden="true">Rolle:</span>
                    <button
                        v-for="option in roleOptions"
                        :key="option.roleId ?? 'all'"
                        class="btn chip-button"
                        type="button"
                        :aria-pressed="roleFilter === option.roleId"
                        @click="roleFilter = option.roleId"
                    >
                        {{ option.label }} ({{ option.count }})
                    </button>
                </div>
                <div class="sort-buttons" role="group" aria-label="Sortieren">
                    <span class="sort-label" aria-hidden="true">Sortieren:</span>
                    <button
                        v-for="column in COLUMNS"
                        :key="column.key"
                        class="btn sort-button"
                        type="button"
                        :aria-pressed="sort === column.key"
                        @click="sortBy(column.key)"
                    >
                        {{ column.label }}
                        <span class="th-arrow" aria-hidden="true">{{
                            sort === column.key ? (dir === 'asc' ? '▲' : '▼') : ''
                        }}</span>
                    </button>
                </div>
            </div>

            <section v-if="bulkResult !== null" class="bulk-result" data-testid="bulk-result">
                <p
                    class="notice"
                    :class="bulkClean ? 'notice-success' : 'notice-info'"
                    role="status"
                    data-testid="bulk-summary"
                >
                    {{
                        bulkRoleText(
                            bulkResult.roleName,
                            bulkResult.run.results,
                            bulkResult.run.unsent.length,
                        )
                    }}
                </p>
                <ErrorNotice v-if="bulkResult.run.error !== null" :error="bulkResult.run.error" />
                <div
                    v-if="bulkFailures.length > 0"
                    class="notice notice-info"
                    data-testid="bulk-failures"
                >
                    <p>Nicht geändert (bleiben ausgewählt):</p>
                    <ul class="failures">
                        <li v-for="failure in bulkFailures" :key="failure.id">
                            <strong>{{ failure.label }}</strong
                            >: {{ failure.text }}
                        </li>
                    </ul>
                </div>
                <p v-if="bulkResult.run.unsent.length > 0" class="hint">
                    Nicht gesendete Konten bleiben ausgewählt; bitte erneut versuchen.
                </p>
            </section>

            <div v-if="selected.size > 0" class="selection-bar" data-testid="selection-bar">
                <span class="selection-count" role="status">{{ selectedText(selected.size) }}</span>
                <button
                    ref="bulkButton"
                    class="btn btn-accent"
                    type="button"
                    :disabled="busy"
                    @click="openBulk"
                >
                    Rolle zuweisen …
                </button>
                <button class="link-button" type="button" @click="clearSelection">
                    Auswahl aufheben
                </button>
            </div>

            <p v-if="state.users !== null && others > 0 && rows.length === 0" class="hint">
                {{
                    query.trim() === ''
                        ? 'Kein Konto hat diese Rolle.'
                        : `Kein Konto passt zu „${query.trim()}“.`
                }}
            </p>

            <div v-if="rows.length > 0" class="table-card">
                <table class="list-table" data-testid="user-table">
                    <thead>
                        <tr>
                            <th v-if="canWrite" scope="col" class="cell-select">
                                <label class="select-box">
                                    <input
                                        type="checkbox"
                                        :checked="allVisibleSelected"
                                        :indeterminate="someVisibleSelected"
                                        :disabled="visibleSelectable.length === 0"
                                        aria-label="Alle angezeigten Konten auswählen"
                                        @change="toggleVisible"
                                    />
                                </label>
                            </th>
                            <th
                                v-for="column in COLUMNS"
                                :key="column.key"
                                scope="col"
                                :aria-sort="ariaSort(column.key)"
                            >
                                <button class="th" type="button" @click="sortBy(column.key)">
                                    {{ column.label }}
                                    <span class="th-arrow" aria-hidden="true">{{
                                        sort === column.key ? (dir === 'asc' ? '▲' : '▼') : ''
                                    }}</span>
                                </button>
                            </th>
                            <th v-if="canWrite" scope="col" class="col-actions">
                                <span class="visually-hidden">Aktionen</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="user in rows"
                            :key="user.id"
                            :class="{ 'row-locked': user.locked }"
                            :data-testid="`user-${String(user.id)}`"
                        >
                            <td v-if="canWrite" class="cell-select">
                                <label v-if="selectable(user, ownId)" class="select-box">
                                    <input
                                        type="checkbox"
                                        :checked="selected.has(user.id)"
                                        :aria-label="`${user.label} auswählen`"
                                        @change="toggle(user)"
                                    />
                                </label>
                            </td>
                            <td class="col-name">
                                <div class="cell-name">
                                    <strong class="user-name">{{ user.label }}</strong>
                                    <span v-if="user.id === ownId" class="badge badge-ready"
                                        >Sie</span
                                    >
                                </div>
                                <span v-if="nameHint(user) !== null" class="hint">{{
                                    nameHint(user)
                                }}</span>
                            </td>
                            <td class="col-email">{{ user.email }}</td>
                            <td class="col-role">
                                <span
                                    class="role-chip"
                                    :class="{ 'role-chip-admin': user.role === 'admin' }"
                                    >{{ roleNameOf(user) }}</span
                                >
                                <span
                                    v-if="claudeChip(user) !== null"
                                    class="role-chip claude-chip"
                                    :data-testid="`claude-chip-${String(user.id)}`"
                                    >{{ claudeChip(user) }}</span
                                >
                                <span
                                    v-if="claudeConnectionText(user, now) !== null"
                                    class="hint claude-connections"
                                    >{{ claudeConnectionText(user, now) }}</span
                                >
                            </td>
                            <td class="col-status">
                                <span
                                    class="status"
                                    :class="{
                                        'status-locked': user.locked,
                                        'status-open': !user.locked && user.status !== 'active',
                                    }"
                                    >{{ statusText(user) }}</span
                                >
                            </td>
                            <td class="col-created">{{ createdText(user) }}</td>
                            <td v-if="canWrite" class="col-actions">
                                <button
                                    v-if="user.status === 'unverified'"
                                    class="btn small-btn"
                                    type="button"
                                    :disabled="busy"
                                    :aria-label="`Registrierung von ${user.label} ablehnen`"
                                    title="Die E-Mail-Adresse ist noch nicht bestätigt. Ohne Bestätigung wird das Konto nach 7 Tagen gelöscht."
                                    @click="ask('reject', user, $event)"
                                >
                                    Ablehnen …
                                </button>
                                <button
                                    v-else
                                    class="icon-btn"
                                    type="button"
                                    :aria-label="`${user.label} bearbeiten`"
                                    :title="`${user.label} bearbeiten`"
                                    @click="openDialog(user, 'edit', $event)"
                                >
                                    <svg
                                        width="18"
                                        height="18"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                        aria-hidden="true"
                                    >
                                        <path d="M4 20h4L19 9l-4-4L4 16z" />
                                        <path d="M13.5 6.5l4 4" />
                                    </svg>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="state.users !== null" class="hint roles-hint">
                Welche Rollen es gibt und was sie dürfen, legt der Super-Admin fest. Fehlt eine
                Rolle, wenden Sie sich an ihn.
            </p>
        </TabsContent>
        <TabsContent class="tab-panel" value="codes" :unmount-on-hide="false">
            <InvitationCodesPanel :active="tab === 'codes'" />
        </TabsContent>
    </TabsRoot>

    <FormDialog
        v-model:open="dialogOpen"
        :unsaved="unsaved"
        :title="dialogTitle"
        :description="dialogDescription"
        :submit-label="mode === 'approve' ? 'Freischalten' : 'Speichern'"
        :busy="busy"
        :can-submit="canSubmit"
        :error="dialogError"
        :return-focus="opener"
        @submit="submit"
    >
        <p v-if="mode === 'edit' && isOwn" class="notice notice-info" data-testid="own-account">
            Das ist Ihr eigenes Konto. Rolle, Sperren und Löschen sind hier nicht möglich; das
            übernimmt bei Bedarf ein anderer Admin.
        </p>
        <label v-if="editsName" class="field">
            <span class="field-label">Anzeigename</span>
            <input
                v-model="displayName"
                class="input"
                type="text"
                autocomplete="off"
                :aria-invalid="shownNameError !== null"
                :aria-describedby="shownNameError !== null ? `${id}-name-error` : `${id}-name-hint`"
                @input="
                    touched = true;
                    nameError = null;
                "
            />
            <p v-if="shownNameError !== null" :id="`${id}-name-error`" class="field-error">
                {{ shownNameError }}
            </p>
            <p v-else :id="`${id}-name-hint`" class="field-hint">
                So erscheint die Person in der App und im Verlauf der Ausrüstung.
            </p>
        </label>
        <fieldset class="roles" :disabled="mode === 'edit' && isOwn">
            <legend class="field-label">Rolle</legend>
            <ErrorNotice v-if="roleState.error !== null" :error="roleState.error">
                <button
                    class="btn retry"
                    type="button"
                    :disabled="roleState.loading"
                    @click="retryRoles"
                >
                    Erneut versuchen
                </button>
            </ErrorNotice>
            <p v-else-if="roleState.data === null" class="field-hint" role="status">
                Rollen werden geladen …
            </p>
            <label
                v-for="role in roles"
                :key="role.id"
                class="role-option"
                :class="{ 'role-option-disabled': roleDisabled(role) }"
            >
                <input
                    v-model="roleId"
                    type="radio"
                    :aria-label="role.name"
                    :aria-describedby="`${id}-role0-${String(role.id)}`"
                    :name="`${id}-role`"
                    :value="role.id"
                    :disabled="roleDisabled(role)"
                />
                <span :id="`${id}-role0-${String(role.id)}`" class="role-text">
                    <strong>{{ role.name }}</strong>
                    <span v-if="role.description !== null" class="field-hint">{{
                        role.description
                    }}</span>
                    <span class="field-hint">Sieht: {{ roleSees(role) }}</span>
                    <span v-if="roleFilterText(role) !== null" class="field-hint">{{
                        roleFilterText(role)
                    }}</span>
                    <span v-if="roleDisabled(role)" class="field-hint">{{
                        role.assignBlocker
                    }}</span>
                </span>
            </label>
            <p
                v-if="chosenRole?.systemKey === 'admin' && roleChanged"
                class="notice notice-info"
                data-testid="admin-hint"
            >
                Admins haben alle Rechte, auch Benutzer und Einstellungen.
                {{ target?.label }} kann dann selbst Rollen zuweisen.
            </p>
            <p v-if="mode === 'edit' && roleChanged" class="field-hint" data-testid="role-hint">
                {{
                    (chosenRole?.systemKey === 'admin') !== (target?.role === 'admin')
                        ? 'Mit diesem Wechsel wird die Person auf allen Geräten abgemeldet.'
                        : 'Die neue Rolle gilt beim nächsten Aufruf der App. Handys laden dabei ihre Offline-Kopie neu.'
                }}
            </p>
        </fieldset>
        <fieldset
            v-if="showsClaude"
            class="roles claude-access"
            :disabled="claudeBlocker !== null"
            data-testid="claude-access"
        >
            <legend class="field-label">Claude-Zugang</legend>
            <label v-for="value in CLAUDE_ACCESS_VALUES" :key="value" class="role-option">
                <input
                    v-model="claudeAccess"
                    type="radio"
                    :aria-label="`Claude-Zugang: ${CLAUDE_ACCESS_LABELS[value]}`"
                    :name="`${id}-claude`"
                    :value="value"
                />
                <span class="role-text">
                    <strong>{{ CLAUDE_ACCESS_LABELS[value] }}</strong>
                    <span class="field-hint">{{
                        value === 'off'
                            ? 'Keine Verbindung möglich; bestehende werden getrennt.'
                            : value === 'read'
                              ? 'Claude darf ansehen, was die Rolle sieht, aber nichts ändern.'
                              : 'Claude darf, was die Rolle darf – außer Benutzer und Einstellungen.'
                    }}</span>
                </span>
            </label>
            <p v-if="claudeBlocker !== null" class="field-hint" data-testid="claude-blocker">
                {{ claudeBlocker }}
            </p>
        </fieldset>
        <template v-if="mode === 'edit' && target !== null && !isOwn" #extra>
            <button v-if="target.locked" class="btn" type="button" :disabled="busy" @click="unlock">
                {{ target.label }} entsperren
            </button>
            <button v-else class="btn" type="button" :disabled="busy" @click="ask('lock', target)">
                {{ target.label }} sperren …
            </button>
            <button
                v-if="showsClaude && claudeBlocker === null && target.claudeConnections.count > 0"
                class="btn"
                type="button"
                :disabled="busy"
                @click="ask('claude', target)"
            >
                Claude-Verbindungen trennen …
            </button>
            <button
                class="btn btn-danger"
                type="button"
                :disabled="busy"
                @click="ask('delete', target)"
            >
                {{ target.label }} löschen …
            </button>
        </template>
    </FormDialog>

    <FormDialog
        v-model:open="bulkOpen"
        :unsaved="bulkUnsaved"
        :title="`Rolle zuweisen: ${selectedText(selected.size)}`"
        description="Alle ausgewählten Konten bekommen dieselbe Rolle. Wer dabei die Rolle Admin bekommt oder verliert, wird auf allen Geräten abgemeldet."
        submit-label="Rolle zuweisen"
        :busy="busy"
        :can-submit="bulkRoleId !== null"
        :error="bulkError"
        :return-focus="bulkButton"
        @submit="submitBulk"
    >
        <fieldset class="roles">
            <legend class="field-label">Neue Rolle</legend>
            <ErrorNotice v-if="roleState.error !== null" :error="roleState.error">
                <button
                    class="btn retry"
                    type="button"
                    :disabled="roleState.loading"
                    @click="retryRoles"
                >
                    Erneut versuchen
                </button>
            </ErrorNotice>
            <p v-else-if="roleState.data === null" class="field-hint" role="status">
                Rollen werden geladen …
            </p>
            <label
                v-for="role in roles"
                :key="role.id"
                class="role-option"
                :class="{ 'role-option-disabled': !role.assignable }"
            >
                <input
                    v-model="bulkRoleId"
                    type="radio"
                    :aria-label="role.name"
                    :aria-describedby="`${id}-role1-${String(role.id)}`"
                    :name="`${id}-bulk-role`"
                    :value="role.id"
                    :disabled="!role.assignable"
                />
                <span :id="`${id}-role1-${String(role.id)}`" class="role-text">
                    <strong>{{ role.name }}</strong>
                    <span v-if="role.description !== null" class="field-hint">{{
                        role.description
                    }}</span>
                    <span class="field-hint">Sieht: {{ roleSees(role) }}</span>
                    <span v-if="roleFilterText(role) !== null" class="field-hint">{{
                        roleFilterText(role)
                    }}</span>
                    <span v-if="!role.assignable" class="field-hint">{{ role.assignBlocker }}</span>
                </span>
            </label>
        </fieldset>
    </FormDialog>

    <ConfirmDialog
        v-model:open="confirmOpen"
        :title="confirmTexts.title"
        :description="confirmTexts.description"
        :confirm-label="confirmTexts.confirm"
        @confirm="confirmed"
    />
</template>

<style scoped>
.hint {
    margin: 0;
    color: var(--muted);
    font-size: var(--font-size-sm);
}

.retry {
    align-self: flex-start;
}

/* Wartende Registrierungen: deutlich oben (KONZEPT.md, "Bereiche des Admins", Benutzer). */
.pending {
    display: flex;
    flex-direction: column;
    gap: 12px;
    padding: 16px;
    border: 2px solid var(--status-maintenance-fg);
    border-radius: var(--radius-lg);
    background: var(--status-maintenance-bg);
}

.pending h2 {
    margin: 0;
    color: var(--status-maintenance-fg);
}

.pending-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin: 0;
    padding: 0;
    list-style: none;
}

.pending-card {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 12px 14px;
    border-radius: var(--radius-md);
    background: var(--surface);
}

.pending-text {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}

.pending-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.user-name,
.user-email {
    overflow-wrap: anywhere;
}

.table-card {
    overflow: hidden;
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    background: var(--surface);
}

.list-table {
    width: 100%;
    border-collapse: collapse;
}

.list-table th {
    padding: 0;
    background: var(--surface-hover);
    text-align: left;
}

.th {
    display: flex;
    align-items: center;
    gap: 6px;
    width: 100%;
    min-height: var(--touch-min);
    padding: 12px 14px;
    border: 0;
    background: none;
    color: var(--text-secondary);
    font: inherit;
    font-size: var(--font-size-md);
    font-weight: var(--font-weight-bold);
    text-align: left;
    white-space: nowrap;
    cursor: pointer;
}

.th:hover {
    background: var(--nav-hover);
    color: var(--text);
}

.th-arrow {
    font-size: 12px;
    color: var(--line);
}

.list-table td {
    padding: 8px 14px;
    border-top: 1px solid var(--border-subtle);
    vertical-align: middle;
}

/* Zeilen am PC kräftig hinterlegt mit Randbalken links (KONZEPT.md, "Oberfläche: Grundsätze"). */
.list-table tbody tr:hover td {
    background: var(--surface-hover);
}

.list-table tbody tr:hover td:first-child {
    box-shadow: inset 4px 0 0 var(--line);
}

.cell-name {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
}

.col-email {
    overflow-wrap: anywhere;
}

.col-created {
    white-space: nowrap;
}

.row-locked .user-name {
    color: var(--muted);
}

.status-locked {
    color: var(--status-defective-fg);
    font-weight: var(--font-weight-semibold);
}

.status-open {
    color: var(--status-maintenance-fg);
    font-weight: var(--font-weight-semibold);
}

.col-actions {
    width: 72px;
    text-align: right;
    white-space: nowrap;
}

.small-btn {
    min-height: var(--touch-min);
    padding: 0 12px;
}

.roles {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 4px 20px;
    margin: 0;
    padding: 0;
    border: 0;
}

.roles legend {
    width: 100%;
    margin-bottom: 4px;
    padding: 0;
}

.field-hint {
    width: 100%;
    margin: 0;
    font-size: var(--font-size-sm);
    color: var(--muted);
}

.roles-hint {
    margin-top: 4px;
}

.role-filter {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
}

.chip-button[aria-pressed='true'] {
    border-color: var(--line);
    background: var(--selected-bg);
}

.role-chip {
    display: inline-block;
    padding: 2px 10px;
    border: 1px solid var(--border);
    border-radius: 999px;
    background: var(--surface-hover);
    font-size: var(--font-size-sm);
    white-space: nowrap;
}

.role-chip-admin {
    border-color: var(--line);
    font-weight: var(--font-weight-semibold);
}

.claude-chip {
    margin-left: 6px;
}

.claude-connections {
    display: block;
    margin-top: 4px;
}

.role-option {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    width: 100%;
    min-height: var(--touch-min);
    padding: 8px 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    cursor: pointer;
}

.role-option:has(input:checked) {
    border-color: var(--line);
    background: var(--selected-bg);
}

.role-option input {
    width: 20px;
    height: 20px;
    margin: 2px 0 0;
    flex: none;
    accent-color: var(--accent);
}

.role-option-disabled {
    color: var(--muted);
    cursor: not-allowed;
}

.role-text {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}

.selection-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px 12px;
    padding: 10px 14px;
    border: 1px solid var(--line);
    border-radius: var(--radius-lg);
    background: var(--selected-bg);
}

.selection-count {
    flex-grow: 1;
    font-weight: var(--font-weight-bold);
}

.bulk-result {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.failures {
    margin: 4px 0 0;
    padding-left: 20px;
}

.list-table th.cell-select,
.list-table td.cell-select {
    width: 1%;
    padding: 0 0 0 6px;
}

.select-box {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: var(--touch-min);
    min-height: var(--touch-min);
    cursor: pointer;
}

.select-box input {
    width: 20px;
    height: 20px;
    margin: 0;
    accent-color: var(--accent);
}

/* Am Handy sortieren Knöpfe statt Spaltenköpfe (kein <select>, siehe CLAUDE.md, CSP). */
.sort-buttons {
    display: none;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
}

.sort-label {
    color: var(--muted);
    font-size: var(--font-size-md);
}

.sort-button[aria-pressed='true'] {
    border-color: var(--line);
    background: var(--selected-bg);
}

/* Am Handy werden die Zeilen zu Karten (KONZEPT.md, "Listen"). */
@media (max-width: 767.98px) {
    .sort-buttons {
        display: flex;
    }

    .pending-actions,
    .pending-actions .btn {
        flex: 1 1 100%;
    }

    .table-card {
        overflow: visible;
        border: 0;
        background: none;
    }

    .list-table thead {
        display: none;
    }

    .list-table,
    .list-table tbody {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .list-table tbody tr {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        align-items: center;
        gap: 2px 12px;
        padding: 10px 12px 10px 16px;
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        background: var(--surface);
    }

    .list-table td {
        padding: 0;
        border: 0;
    }

    .list-table tbody tr:hover td,
    .list-table tbody tr:hover td:first-child {
        background: none;
        box-shadow: none;
    }

    .list-table tbody tr {
        grid-template-columns: auto minmax(0, 1fr) auto;
    }

    .list-table td.cell-select {
        grid-row: 1 / span 5;
        grid-column: 1;
        padding: 0;
    }

    .list-table td:not(.col-actions, .cell-select) {
        grid-column: 2;
    }

    .col-email,
    .col-created {
        color: var(--muted);
        font-size: var(--font-size-sm);
    }

    .col-role,
    .col-status {
        display: inline;
        font-size: var(--font-size-sm);
    }

    .col-actions {
        grid-row: 1 / span 5;
        grid-column: 3;
        width: auto;
    }
}
</style>
