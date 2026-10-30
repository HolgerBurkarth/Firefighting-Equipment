<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import type { ApiError } from '../api/client';
import type { DeviceType } from '../api/deviceTypes';
import ConfirmDialog from '../components/ConfirmDialog.vue';
import ErrorNotice from '../components/ErrorNotice.vue';
import FormDialog from '../components/FormDialog.vue';
import { addDeviceType, deviceTypeStore, renameDeviceType, setDeviceTypeActive } from '../data';
import { deviceCountText } from '../data/locationSearch';
import {
    DEVICE_TYPE_NAME_MAX_LENGTH,
    deviceTypeRows,
    nameProblem,
    sameName,
    type DeviceTypeSortKey,
    type SortDir,
} from '../data/masterData';
import { useUnsavedInput } from '../runtime/unsavedInput';

/**
 * Gerätetypen der Stadt (KONZEPT.md, "Bereiche des Admins"): Liste mit Name und Gerätezahl,
 * sortierbar wie im Explorer (am Handy Karten mit Sortier-Knöpfen), Anlegen, Umbenennen, Deaktivieren
 * und Aktivieren. Deaktivierte nur auf Wunsch.
 */
const state = computed(() => deviceTypeStore.state);
const types = computed(() => state.value.types ?? []);
const query = ref('');
const showInactive = ref(false);
const sort = ref<DeviceTypeSortKey>('name');
const dir = ref<SortDir>('asc');
const rows = computed(() =>
    deviceTypeRows(types.value, query.value, showInactive.value, sort.value, dir.value),
);
const inactive = computed(() => types.value.filter((type) => !type.active).length);

const COLUMNS: readonly { key: DeviceTypeSortKey; label: string }[] = [
    { key: 'name', label: 'Name' },
    { key: 'devices', label: 'Geräte' },
];

/** Klick auf den Spaltenkopf: aufsteigend, zweiter Klick absteigend (KONZEPT.md, "Listen"). */
function sortBy(key: DeviceTypeSortKey): void {
    if (sort.value === key) {
        dir.value = dir.value === 'asc' ? 'desc' : 'asc';
    } else {
        sort.value = key;
        dir.value = 'asc';
    }
}

function ariaSort(key: DeviceTypeSortKey): 'ascending' | 'descending' | 'none' {
    if (sort.value !== key) {
        return 'none';
    }
    return dir.value === 'asc' ? 'ascending' : 'descending';
}

const editing = ref<DeviceType | null>(null);
const dialogOpen = ref(false);
const name = ref('');
const touched = ref(false);
const busy = ref(false);
const nameError = ref<string | null>(null);
const dialogError = ref<ApiError | null>(null);
const success = ref<string | null>(null);
const pageError = ref<ApiError | null>(null);

const deactivateTarget = ref<DeviceType | null>(null);
const confirmOpen = ref(false);

const initialName = computed(() => editing.value?.name ?? '');

useUnsavedInput(() => dialogOpen.value && name.value !== initialName.value);

const liveProblem = computed(() => {
    const problem = nameProblem(name.value, DEVICE_TYPE_NAME_MAX_LENGTH);
    if (problem !== null) {
        return problem;
    }
    const taken = types.value.some(
        (type) => type.id !== editing.value?.id && sameName(type.name, name.value),
    );
    return taken ? 'Diesen Gerätetyp gibt es schon.' : null;
});

const shownNameError = computed(
    () => nameError.value ?? (touched.value ? liveProblem.value : null),
);

/** Knopf, der das Fenster geöffnet hat (bekommt den Fokus zurück). */
const opener = ref<HTMLElement | null>(null);

function openDialog(type: DeviceType | null, event: Event): void {
    opener.value = event.currentTarget instanceof HTMLElement ? event.currentTarget : null;
    editing.value = type;
    name.value = type?.name ?? '';
    touched.value = false;
    nameError.value = null;
    dialogError.value = null;
    success.value = null;
    pageError.value = null;
    dialogOpen.value = true;
}

function onNameInput(): void {
    touched.value = true;
    nameError.value = null;
}

async function submit(): Promise<void> {
    const current = editing.value;
    touched.value = true;
    nameError.value = null;
    dialogError.value = null;
    if (liveProblem.value !== null || busy.value) {
        return;
    }
    if (current !== null && name.value === current.name) {
        dialogOpen.value = false;
        return;
    }
    busy.value = true;
    const result =
        current === null
            ? await addDeviceType(name.value)
            : await renameDeviceType(current.id, name.value);
    busy.value = false;
    if (!result.ok) {
        if (result.error.code === 'name_taken') {
            nameError.value = result.error.message;
        } else {
            dialogError.value = result.error;
        }
        return;
    }
    success.value =
        current === null
            ? `„${result.data.name}“ wurde angelegt.`
            : `„${current.name}“ heißt jetzt „${result.data.name}“.`;
    dialogOpen.value = false;
}

async function changeActive(type: DeviceType, active: boolean): Promise<void> {
    busy.value = true;
    success.value = null;
    pageError.value = null;
    const result = await setDeviceTypeActive(type.id, active);
    busy.value = false;
    if (!result.ok) {
        if (active) {
            dialogError.value = result.error;
        } else {
            pageError.value = result.error;
        }
        return;
    }
    success.value = active ? `„${type.name}“ ist wieder aktiv.` : `„${type.name}“ ist deaktiviert.`;
    dialogOpen.value = false;
}

function askDeactivate(): void {
    deactivateTarget.value = editing.value;
    dialogOpen.value = false;
    confirmOpen.value = true;
}

const deactivateDescription = computed(() => {
    const type = deactivateTarget.value;
    if (type === null) {
        return '';
    }
    const kept =
        type.deviceCount === 0
            ? ''
            : ` Die ${deviceCountText(type.deviceCount)} dieses Typs behalten ihn.`;
    return `Neue Geräte können „${type.name}“ dann nicht mehr bekommen.${kept} Sie können den Typ jederzeit wieder aktivieren.`;
});

function confirmDeactivate(): void {
    if (deactivateTarget.value !== null) {
        void changeActive(deactivateTarget.value, false);
    }
}

function activateEdited(): void {
    if (editing.value !== null) {
        void changeActive(editing.value, true);
    }
}

function retry(): void {
    void deviceTypeStore.refresh('changed');
}

onMounted(() => {
    void deviceTypeStore.refresh('open');
});
</script>

<template>
    <div class="page-head">
        <h1>Gerätetypen</h1>
        <button class="btn btn-accent" type="button" @click="openDialog(null, $event)">
            Neuer Gerätetyp
        </button>
    </div>

    <p
        v-if="success !== null"
        class="notice notice-success"
        role="status"
        data-testid="success-notice"
    >
        {{ success }}
    </p>
    <ErrorNotice v-if="pageError !== null" :error="pageError" />

    <div class="toolbar">
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
            <span class="visually-hidden">Gerätetypen filtern</span>
            <input v-model="query" type="search" placeholder="Filtern, z. B. Funk" />
        </label>
        <label v-if="inactive > 0" class="check">
            <input v-model="showInactive" type="checkbox" />
            Deaktivierte anzeigen ({{ inactive }})
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

    <ErrorNotice v-if="state.error !== null" :error="state.error">
        <button class="btn retry" type="button" :disabled="state.loading" @click="retry">
            Erneut versuchen
        </button>
    </ErrorNotice>

    <p v-if="state.types === null && state.error === null" class="hint" role="status">
        Wird geladen …
    </p>
    <p v-else-if="state.types !== null && types.length === 0" class="hint">
        Noch keine Gerätetypen. Legen Sie mit „Neuer Gerätetyp“ den ersten an.
    </p>
    <p v-else-if="state.types !== null && rows.length === 0" class="hint">
        Kein Gerätetyp passt zu „{{ query.trim() }}“.
    </p>

    <div v-if="rows.length > 0" class="table-card">
        <table class="list-table" data-testid="device-type-table">
            <thead>
                <tr>
                    <th
                        v-for="column in COLUMNS"
                        :key="column.key"
                        scope="col"
                        :aria-sort="ariaSort(column.key)"
                        :class="{ 'col-count': column.key === 'devices' }"
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
                    v-for="type in rows"
                    :key="type.id"
                    :class="{ 'row-inactive': !type.active }"
                    :data-testid="`device-type-${String(type.id)}`"
                >
                    <td>
                        <div class="cell-name">
                            <span class="type-name">{{ type.name }}</span>
                            <span v-if="!type.active" class="badge badge-retired">
                                deaktiviert
                            </span>
                        </div>
                    </td>
                    <td class="col-count">{{ deviceCountText(type.deviceCount) }}</td>
                    <td class="col-actions">
                        <button
                            class="icon-btn"
                            type="button"
                            :aria-label="`${type.name} bearbeiten`"
                            :title="`${type.name} bearbeiten`"
                            @click="openDialog(type, $event)"
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

    <FormDialog
        v-model:open="dialogOpen"
        :title="editing === null ? 'Neuer Gerätetyp' : `${editing.name} bearbeiten`"
        :submit-label="editing === null ? 'Anlegen' : 'Speichern'"
        :busy="busy"
        :can-submit="!touched || liveProblem === null"
        :error="dialogError"
        :return-focus="opener"
        @submit="submit"
    >
        <label class="field">
            <span class="field-label">Name</span>
            <input
                v-model="name"
                class="input"
                type="text"
                autocomplete="off"
                :aria-invalid="shownNameError !== null"
                :aria-describedby="shownNameError !== null ? 'device-type-name-error' : undefined"
                @input="onNameInput"
            />
            <p v-if="shownNameError !== null" id="device-type-name-error" class="field-error">
                {{ shownNameError }}
            </p>
        </label>
        <template v-if="editing !== null" #extra>
            <template v-if="editing.active">
                <p class="extra-text">
                    Deaktivierte Typen bekommen keine neuen Geräte; vorhandene behalten ihn.
                </p>
                <button
                    class="btn btn-danger"
                    type="button"
                    :disabled="busy"
                    @click="askDeactivate"
                >
                    {{ editing.name }} deaktivieren
                </button>
            </template>
            <button v-else class="btn" type="button" :disabled="busy" @click="activateEdited">
                {{ editing.name }} aktivieren
            </button>
        </template>
    </FormDialog>

    <ConfirmDialog
        v-model:open="confirmOpen"
        :title="`${deactivateTarget?.name ?? ''} deaktivieren?`"
        :description="deactivateDescription"
        confirm-label="Deaktivieren"
        @confirm="confirmDeactivate"
    />
</template>

<style scoped>
.hint {
    margin: 0;
    color: var(--muted);
}

.retry {
    align-self: flex-start;
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
    min-height: 44px;
}

.type-name {
    font-weight: var(--font-weight-bold);
    overflow-wrap: anywhere;
}

.row-inactive .type-name {
    color: var(--muted);
}

.col-count {
    width: 140px;
    white-space: nowrap;
}

.col-actions {
    width: 72px;
    text-align: right;
}

.extra-text {
    margin: 0;
    font-size: var(--font-size-sm);
    color: var(--muted);
}

/*
 * Am PC sortieren die Spaltenköpfe, am Handy zwei Knöpfe (wie die Spaltenköpfe: erneut tippen
 * kehrt die Richtung um). Kein <select>: Playwright-WebKit meldet dafür eine CSP-Verletzung
 * (style-src), obwohl die Seite keinen Inline-Stil setzt.
 */
.sort-buttons {
    display: none;
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

/* Am Handy werden die Zeilen zu Karten, sortiert über die Knöpfe oben (KONZEPT.md, "Listen"). */
@media (max-width: 767.98px) {
    .sort-buttons {
        display: flex;
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
        grid-template-columns: 1fr auto;
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

    .cell-name {
        min-height: 0;
    }

    .col-count {
        grid-row: 2;
        width: auto;
        color: var(--muted);
    }

    .col-actions {
        grid-row: 1 / span 2;
        grid-column: 2;
        width: auto;
    }
}
</style>
