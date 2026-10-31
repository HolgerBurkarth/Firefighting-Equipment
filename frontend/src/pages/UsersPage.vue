<script setup lang="ts">
import { TabsContent, TabsList, TabsRoot, TabsTrigger } from 'reka-ui';
import { computed, onMounted, ref, useId } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import type { ApiError, ApiResult } from '../api/client';
import type { AdminUser, ManagedRole } from '../api/users';
import { session } from '../auth/session';
import ConfirmDialog from '../components/ConfirmDialog.vue';
import ErrorNotice from '../components/ErrorNotice.vue';
import FormDialog from '../components/FormDialog.vue';
import InvitationCodesPanel from '../components/InvitationCodesPanel.vue';
import { approveUser, deleteUser, rejectUser, saveUser, setUserLocked, userStore } from '../data';
import { phpTrim } from '../data/masterData';
import {
    createdText,
    displayNameProblem,
    nameHint,
    pendingText,
    pendingUsers,
    ROLE_OPTIONS,
    statusText,
    userChanges,
    userRows,
    type SortDir,
    type UserSortKey,
} from '../data/users';
import { useUnsavedInput } from '../runtime/unsavedInput';

/**
 * Benutzerverwaltung (Admin, `benutzer`; KONZEPT.md, "Bereiche des Admins", Benutzer): oben die
 * wartenden Registrierungen mit „Freischalten“ (Anzeigename und Rolle) und „Ablehnen“, darunter alle
 * Konten der Stadt, sortierbar wie im Explorer (am Handy Karten mit Sortier-Knöpfen). Der Stift
 * öffnet Anzeigename und Rolle, abseits Sperren/Entsperren und Löschen (= anonymisieren). Das eigene
 * Konto lässt sich nicht sperren, herabstufen oder löschen; alle Regeln prüft der Server. Zweiter
 * Reiter „Einladungscodes“ (`?ansicht=einladungscodes`, InvitationCodesPanel).
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
const pending = computed(() => pendingUsers(users.value));

const query = ref('');
const sort = ref<UserSortKey>('name');
const dir = ref<SortDir>('asc');
const rows = computed(() => userRows(users.value, query.value, sort.value, dir.value));
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
const role = ref<ManagedRole>('user');
const touched = ref(false);
const nameError = ref<string | null>(null);
const dialogError = ref<ApiError | null>(null);

const isOwn = computed(() => target.value !== null && target.value.id === ownId.value);
/** Anzeigename nur bei aktiven Konten (eingeladene bekommen ihn beim Annehmen der Einladung). */
const editsName = computed(
    () => mode.value === 'approve' || (target.value !== null && target.value.status === 'active'),
);
const initial = computed((): { displayName: string; role: ManagedRole } => ({
    displayName:
        mode.value === 'approve'
            ? (target.value?.name ?? '')
            : (target.value?.displayName ?? target.value?.name ?? ''),
    role: target.value?.role === 'admin' ? 'admin' : 'user',
}));

useUnsavedInput(
    () =>
        dialogOpen.value &&
        (displayName.value !== initial.value.displayName || role.value !== initial.value.role),
);

const liveProblem = computed(() =>
    editsName.value ? displayNameProblem(displayName.value) : null,
);
const shownNameError = computed(
    () => nameError.value ?? (touched.value ? liveProblem.value : null),
);

function openDialog(user: AdminUser, kind: Mode, event: Event): void {
    startAction(event);
    mode.value = kind;
    target.value = user;
    displayName.value = initial.value.displayName;
    role.value = initial.value.role;
    touched.value = false;
    nameError.value = null;
    dialogError.value = null;
    dialogOpen.value = true;
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
        ? `${email} · registriert am ${createdText(user)}`
        : `${email} · ${statusText(user)}`;
});

/** Fehler, die zu keinem Feld gehören; Meldungen des Anzeigenamens (400) stehen am Feld. */
function handleError(error: ApiError): void {
    if (error.code === 'invalid_request' && editsName.value) {
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
        result = await approveUser(user.id, { displayName: name, role: role.value });
    } else {
        result = await saveUser(user, userChanges(user, displayName.value, role.value));
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
        success.value = `${saved.label} ist freigeschaltet (${saved.roleLabel}) und erhält eine E-Mail.`;
    } else if (saved.role !== user.role) {
        success.value = `${saved.label} ist jetzt ${saved.roleLabel} und muss sich neu anmelden.`;
    } else {
        success.value = `Änderungen an ${saved.label} gespeichert.`;
    }
    dialogOpen.value = false;
}

// Rückfragen: Ablehnen, Sperren, Löschen.
type ConfirmKind = 'reject' | 'lock' | 'delete';
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
        case 'delete':
            return {
                title: `${label} löschen?`,
                description: `E-Mail, Namen und Anmeldungen werden entfernt. Im Verlauf der Geräte steht danach „Gelöschter Benutzer #${String(user?.id ?? '')}“. Das lässt sich nicht rückgängig machen.`,
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

onMounted(() => {
    void userStore.refresh('open');
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
                            <span class="hint">Registriert am {{ createdText(user) }}</span>
                        </div>
                        <div class="pending-actions">
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

            <p v-if="state.users !== null && others > 0 && rows.length === 0" class="hint">
                Kein Konto passt zu „{{ query.trim() }}“.
            </p>

            <div v-if="rows.length > 0" class="table-card">
                <table class="list-table" data-testid="user-table">
                    <thead>
                        <tr>
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
                            <th scope="col" class="col-actions">
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
                            <td class="col-role">{{ user.roleLabel }}</td>
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
                            <td class="col-actions">
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
        </TabsContent>
        <TabsContent class="tab-panel" value="codes" :unmount-on-hide="false">
            <InvitationCodesPanel :active="tab === 'codes'" />
        </TabsContent>
    </TabsRoot>

    <FormDialog
        v-model:open="dialogOpen"
        :title="dialogTitle"
        :description="dialogDescription"
        :submit-label="mode === 'approve' ? 'Freischalten' : 'Speichern'"
        :busy="busy"
        :can-submit="!touched || liveProblem === null"
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
                So erscheint die Person in der App und im Verlauf der Geräte.
            </p>
        </label>
        <fieldset class="roles" :disabled="mode === 'edit' && isOwn">
            <legend class="field-label">Rolle</legend>
            <label v-for="option in ROLE_OPTIONS" :key="option.role" class="check">
                <input v-model="role" type="radio" :name="`${id}-role`" :value="option.role" />
                {{ option.label }}
            </label>
            <p class="field-hint">
                Benutzer buchen um und setzen den Status. Admins verwalten zusätzlich Geräte,
                Standorte und Benutzer.
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
                class="btn btn-danger"
                type="button"
                :disabled="busy"
                @click="ask('delete', target)"
            >
                {{ target.label }} löschen …
            </button>
        </template>
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

    .list-table td:not(.col-actions) {
        grid-column: 1;
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
        grid-column: 2;
        width: auto;
    }
}
</style>
