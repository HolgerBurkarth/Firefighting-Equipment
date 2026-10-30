<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import type { ApiError } from '../api/client';
import { downloadDeviceExport, type DeviceSort } from '../api/devices';
import AppSelect, { type SelectOption } from '../components/AppSelect.vue';
import BookingNoticeMessage from '../components/BookingNoticeMessage.vue';
import ErrorNotice from '../components/ErrorNotice.vue';
import StatusBadge from '../components/StatusBadge.vue';
import { adminDeviceList, adminLocationStore, deviceTypeStore } from '../data';
import { takeBookingNotice } from '../data/booking';
import { germanDate, localDateKey } from '../data/changeTime';
import {
    ACTIVE_STATUSES,
    DEFAULT_DEVICE_FILTER,
    DEVICE_QUERY_MAX_LENGTH,
    deviceCountLabel,
    deviceListParams,
    dueState,
    filterFromQuery,
    filterKey,
    filterToQuery,
    hasFilters,
    type ActiveStatus,
    type DeviceListFilter,
} from '../data/deviceList';
import { compareNames } from '../data/masterData';
import { useNow } from '../runtime/now';

/**
 * Geräteliste der Admin-Konsole (Mockups „Geraete“ und „AdminHandy“): Suche, Filter nach Typ,
 * Standort (mit Fächern) und Status, „Ausgesonderte anzeigen“, Sortierung wie im Explorer, Export
 * der gefilterten Liste als CSV. Die Filter stehen in der Adresse (`geraete?status=defective`), so
 * führen Kacheln und Links direkt zur gefilterten Liste; der Server filtert und sortiert.
 */
const route = useRoute();
const router = useRouter();
const now = useNow();
const today = computed(() => localDateKey(now.value));

const state = computed(() => adminDeviceList.state);
const filter = computed(() => filterFromQuery(route.query));
const bookingNotice = takeBookingNotice();

/** Neuer Filter: in die Adresse (ersetzt den Eintrag, damit „Zurück“ die Seite verlässt). */
function setFilter(change: Partial<DeviceListFilter>): void {
    void router.replace({ query: filterToQuery({ ...filter.value, ...change }) });
}

watch(
    () => filterKey(filter.value),
    () => {
        void adminDeviceList.show(filter.value);
    },
    { immediate: true },
);

/** Suche erst 300 ms nach dem letzten Tastendruck (wie „Suchen“ im Modus `server`). */
const SEARCH_DELAY_MS = 300;
const queryInput = ref(filter.value.q);
let searchTimer: ReturnType<typeof setTimeout> | undefined;

function onSearchInput(): void {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        setFilter({ q: queryInput.value });
    }, SEARCH_DELAY_MS);
}

// Andere Adresse (Zurück, Link, „Filter zurücksetzen“): das Suchfeld folgt.
watch(
    () => filter.value.q,
    (q) => {
        if (q.trim() !== queryInput.value.trim()) {
            queryInput.value = q;
        }
    },
);

onBeforeUnmount(() => {
    clearTimeout(searchTimer);
});

const ALL = 'all';

const typeOptions = computed((): SelectOption[] => [
    { value: ALL, label: 'Alle Gerätetypen' },
    ...[...(deviceTypeStore.state.types ?? [])]
        .sort((a, b) => compareNames(a.name, b.name))
        .map((type) => ({
            value: String(type.id),
            label: type.active ? type.name : `${type.name} (deaktiviert)`,
        })),
]);

const locationOptions = computed((): SelectOption[] => {
    const options: SelectOption[] = [{ value: ALL, label: 'Alle Standorte' }];
    for (const location of adminLocationStore.state.locations ?? []) {
        const locked = location.active ? '' : ' (gesperrt)';
        options.push({ value: String(location.id), label: `${location.name}${locked}` });
        for (const child of location.children) {
            options.push({
                value: String(child.id),
                label: `${location.name} / ${child.name}${child.active ? '' : ' (gesperrt)'}`,
                level: 2,
            });
        }
    }
    return options;
});

const typeValue = computed({
    get: () => (filter.value.deviceTypeId === null ? ALL : String(filter.value.deviceTypeId)),
    set: (value: string) => {
        setFilter({ deviceTypeId: value === ALL ? null : Number(value) });
    },
});

const locationValue = computed({
    get: () => (filter.value.locationId === null ? ALL : String(filter.value.locationId)),
    set: (value: string) => {
        setFilter({ locationId: value === ALL ? null : Number(value) });
    },
});

const STATUS_LABELS: Readonly<Record<ActiveStatus, string>> = {
    ready: 'einsatzbereit',
    maintenance: 'in Wartung',
    defective: 'defekt',
};

function toggleStatus(status: ActiveStatus): void {
    const current = filter.value.statuses;
    setFilter({
        statuses: current.includes(status)
            ? current.filter((entry) => entry !== status)
            : [...current, status],
    });
}

function resetFilters(): void {
    queryInput.value = '';
    setFilter({ ...DEFAULT_DEVICE_FILTER, sort: filter.value.sort, dir: filter.value.dir });
}

const COLUMNS: readonly { key: DeviceSort; label: string }[] = [
    { key: 'identifier', label: 'Kennung' },
    { key: 'device_type', label: 'Gerätetyp' },
    { key: 'location', label: 'Standort' },
    { key: 'status', label: 'Status' },
    { key: 'next_inspection_due', label: 'Nächste Prüfung' },
];

/** Klick auf den Spaltenkopf: aufsteigend, zweiter Klick absteigend (KONZEPT.md, "Listen"). */
function sortBy(key: DeviceSort): void {
    setFilter(
        filter.value.sort === key
            ? { dir: filter.value.dir === 'asc' ? 'desc' : 'asc' }
            : { sort: key, dir: 'asc' },
    );
}

function ariaSort(key: DeviceSort): 'ascending' | 'descending' | 'none' {
    if (filter.value.sort !== key) {
        return 'none';
    }
    return filter.value.dir === 'asc' ? 'ascending' : 'descending';
}

/** Am Handy: Spalte über eine Auswahl, Richtung über einen Knopf. */
const sortOptions: readonly SelectOption[] = COLUMNS.map((column) => ({
    value: column.key,
    label: column.label,
}));

const sortValue = computed({
    get: () => filter.value.sort,
    set: (value: string) => {
        const column = COLUMNS.find((entry) => entry.key === value);
        if (column !== undefined) {
            setFilter({ sort: column.key, dir: 'asc' });
        }
    },
});

function toggleDir(): void {
    setFilter({ dir: filter.value.dir === 'asc' ? 'desc' : 'asc' });
}

const filtered = computed(() => hasFilters(filter.value));
const countText = computed(() =>
    state.value.total === null ? '' : deviceCountLabel(state.value.total, filtered.value),
);
const remaining = computed(() =>
    state.value.total === null ? 0 : state.value.total - state.value.devices.length,
);
const loadingFirst = computed(() => state.value.total === null && state.value.error === null);

/** Zeilen mit dem Stand des Prüfdatums (überfällig rot, in 30 Tagen fällig gelb). */
const rows = computed(() =>
    state.value.devices.map((device) => ({
        device,
        due: dueState(device.nextInspectionDue, today.value, device.status),
    })),
);

function loadMore(): void {
    void adminDeviceList.more();
}

function dueText(due: string | null): string {
    return due === null ? '–' : germanDate(due);
}

const DUE_NOTES = { overdue: 'überfällig', soon: 'bald fällig' } as const;

function retry(): void {
    void adminDeviceList.refresh('changed');
}

const exporting = ref(false);
const exportError = ref<ApiError | null>(null);

/** Export mit denselben Filtern und derselben Sortierung wie die Liste, ohne Seiten. */
async function exportList(): Promise<void> {
    exporting.value = true;
    exportError.value = null;
    const result = await downloadDeviceExport(deviceListParams(filter.value));
    exporting.value = false;
    if (!result.ok) {
        exportError.value = result.error;
        return;
    }
    const url = URL.createObjectURL(result.data.blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = result.data.fileName;
    link.click();
    // Erst nach dem Start des Downloads freigeben (Firefox braucht die Adresse noch kurz).
    setTimeout(() => {
        URL.revokeObjectURL(url);
    }, 10_000);
}

onMounted(() => {
    void deviceTypeStore.refresh('open');
    void adminLocationStore.refresh('open');
});
</script>

<template>
    <div class="page-head">
        <h1>Geräte</h1>
        <button class="btn" type="button" :disabled="exporting" @click="exportList">
            {{ exporting ? 'Export läuft …' : 'Export (CSV)' }}
        </button>
    </div>

    <BookingNoticeMessage v-if="bookingNotice !== null" :notice="bookingNotice" />
    <ErrorNotice v-if="exportError !== null" :error="exportError" />

    <div class="filters">
        <label class="filter-field search-field">
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
            <span class="visually-hidden">Geräte filtern</span>
            <input
                v-model="queryInput"
                type="search"
                placeholder="Kennung, Seriennummer, Hersteller …"
                :maxlength="DEVICE_QUERY_MAX_LENGTH"
                @input="onSearchInput"
            />
        </label>
        <AppSelect
            v-model="typeValue"
            class="filter-select"
            label="Gerätetyp"
            :options="typeOptions"
        />
        <AppSelect
            v-model="locationValue"
            class="filter-select"
            label="Standort"
            :options="locationOptions"
        />
    </div>

    <div class="toolbar">
        <div class="status-filter" role="group" aria-label="Status">
            <button
                v-for="status in ACTIVE_STATUSES"
                :key="status"
                class="btn status-chip"
                :class="`status-chip-${status}`"
                type="button"
                :aria-pressed="filter.statuses.includes(status)"
                @click="toggleStatus(status)"
            >
                {{ STATUS_LABELS[status] }}
            </button>
        </div>
        <label class="check">
            <input
                type="checkbox"
                :checked="filter.showRetired"
                @change="setFilter({ showRetired: !filter.showRetired })"
            />
            Ausgesonderte anzeigen
        </label>
        <button v-if="filtered" class="link-button" type="button" @click="resetFilters">
            Filter zurücksetzen
        </button>
    </div>

    <ErrorNotice v-if="state.error !== null" :error="state.error">
        <button class="btn retry" type="button" :disabled="state.loading" @click="retry">
            Erneut versuchen
        </button>
    </ErrorNotice>

    <div class="list-head">
        <span class="count" role="status" data-testid="device-count">{{
            loadingFirst ? 'Wird geladen …' : countText
        }}</span>
        <div class="sort-mobile">
            <AppSelect
                v-model="sortValue"
                label="Sortieren nach"
                hide-label
                :options="sortOptions"
            />
            <button
                class="icon-btn"
                type="button"
                :aria-label="
                    filter.dir === 'asc' ? 'Absteigend sortieren' : 'Aufsteigend sortieren'
                "
                @click="toggleDir"
            >
                <span aria-hidden="true">{{ filter.dir === 'asc' ? '▲' : '▼' }}</span>
            </button>
        </div>
    </div>

    <p v-if="state.total === 0" class="hint">
        {{ filtered ? 'Kein Gerät passt zu den Filtern.' : 'Noch keine Geräte.' }}
    </p>

    <div v-if="state.devices.length > 0" class="table-card">
        <table class="list-table" data-testid="device-table">
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
                                filter.sort === column.key ? (filter.dir === 'asc' ? '▲' : '▼') : ''
                            }}</span>
                        </button>
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="{ device, due } in rows"
                    :key="device.id"
                    :class="{ 'row-retired': device.status === 'retired' }"
                    :data-testid="`device-row-${String(device.id)}`"
                >
                    <td class="cell-identifier">
                        <RouterLink
                            class="device-link"
                            :to="{ name: 'device', params: { id: device.id } }"
                        >
                            {{ device.identifier }}
                        </RouterLink>
                    </td>
                    <td class="cell-type">{{ device.deviceType.name }}</td>
                    <td class="cell-location">{{ device.location.path }}</td>
                    <td class="cell-status">
                        <StatusBadge :status="device.status" :label="device.statusLabel" />
                    </td>
                    <td class="cell-due" :class="`due-${due ?? 'none'}`">
                        <span class="due-label">Prüfung: </span>
                        {{ dueText(device.nextInspectionDue) }}
                        <span v-if="due !== null" class="due-note">{{ DUE_NOTES[due] }}</span>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div v-if="remaining > 0" class="more">
        <span class="hint">{{ state.devices.length }} von {{ state.total }} angezeigt</span>
        <button class="btn" type="button" :disabled="state.loadingMore" @click="loadMore">
            {{ state.loadingMore ? 'Wird geladen …' : 'Weitere laden' }}
        </button>
    </div>
</template>

<style scoped>
.hint {
    margin: 0;
    color: var(--muted);
}

.retry {
    align-self: flex-start;
}

.filters {
    display: grid;
    grid-template-columns: minmax(0, 2fr) minmax(0, 1fr) minmax(0, 1fr);
    align-items: end;
    gap: 12px;
}

.search-field {
    flex: none;
}

.status-filter {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.status-chip[aria-pressed='true'] {
    border-color: var(--line);
    background: var(--selected-bg);
}

.status-chip-ready[aria-pressed='true'] {
    border-color: var(--status-ready-fg);
    background: var(--status-ready-bg);
    color: var(--status-ready-fg);
}

.status-chip-maintenance[aria-pressed='true'] {
    border-color: var(--status-maintenance-fg);
    background: var(--status-maintenance-bg);
    color: var(--status-maintenance-fg);
}

.status-chip-defective[aria-pressed='true'] {
    border-color: var(--status-defective-fg);
    background: var(--status-defective-bg);
    color: var(--status-defective-fg);
}

.list-head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.count {
    font-size: var(--font-size-md);
    color: var(--muted);
}

.table-card {
    overflow-x: auto;
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
    white-space: nowrap;
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
    padding: 10px 14px;
    border-top: 1px solid var(--border-subtle);
}

/* Zeilen am PC kräftig hinterlegt mit Randbalken links (KONZEPT.md, "Oberfläche: Grundsätze"). */
.list-table tbody tr:hover td {
    background: var(--surface-hover);
}

.list-table tbody tr:hover td:first-child {
    box-shadow: inset 4px 0 0 var(--line);
}

.device-link {
    display: inline-flex;
    align-items: center;
    min-height: var(--touch-min);
    color: var(--text);
    font-weight: var(--font-weight-bold);
    text-decoration: none;
    overflow-wrap: anywhere;
}

.device-link:hover {
    color: var(--line);
    text-decoration: underline;
}

.row-retired .device-link,
.row-retired .cell-type,
.row-retired .cell-location {
    color: var(--muted);
}

.due-label {
    display: none;
}

.due-overdue {
    color: var(--status-defective-fg);
    font-weight: var(--font-weight-bold);
}

.due-soon {
    color: var(--status-maintenance-fg);
    font-weight: var(--font-weight-semibold);
}

.due-note {
    margin-left: 6px;
    font-size: var(--font-size-sm);
}

.more {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px;
}

/* Am PC sortieren die Spaltenköpfe; Auswahl und Richtung gibt es nur am Handy. */
.sort-mobile {
    display: none;
    align-items: center;
    gap: 8px;
}

@media (max-width: 1023.98px) {
    .filters {
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    }

    .search-field {
        grid-column: 1 / -1;
    }
}

/* Am Handy werden die Zeilen zu Karten (Mockup „AdminHandy“, KONZEPT.md, "Listen"). */
@media (max-width: 767.98px) {
    .filters {
        grid-template-columns: minmax(0, 1fr);
    }

    .sort-mobile {
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
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 0 12px;
        padding: 6px 14px 10px;
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

    .cell-status {
        grid-row: 1;
        grid-column: 2;
        align-self: center;
    }

    .cell-type,
    .cell-location,
    .cell-due {
        grid-column: 1 / -1;
        font-size: var(--font-size-sm);
    }

    .cell-type,
    .cell-location {
        display: inline;
        color: var(--text-secondary);
    }

    .due-label {
        display: inline;
    }
}
</style>
