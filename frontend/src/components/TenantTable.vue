<script setup lang="ts">
import { computed, ref } from 'vue';
import type { ManagedTenant } from '../api/tenants';
import {
    TENANT_STATE_LABELS,
    countText,
    tenantPath,
    tenantRows,
    tenantState,
    type SortDir,
    type TenantSortKey,
} from '../data/tenants';

/**
 * Städte des Super-Admins (Mockup „SuperAdmin“, KONZEPT.md, "Super-Admin – Entscheidungen", 42):
 * Name, Adresse, Status, Admins und Benutzer; Spaltenköpfe sortieren wie im Explorer, am Handy
 * Karten mit Sortier-Knöpfen. Gefiltert wird mit `query` (Name und Kürzel).
 */
const props = defineProps<{ tenants: readonly ManagedTenant[]; query: string }>();

const sort = ref<TenantSortKey>('name');
const dir = ref<SortDir>('asc');
const rows = computed(() => tenantRows(props.tenants, props.query, sort.value, dir.value));

const COLUMNS: readonly { key: TenantSortKey; label: string; count?: true }[] = [
    { key: 'name', label: 'Name' },
    { key: 'address', label: 'Adresse' },
    { key: 'status', label: 'Status' },
    { key: 'admins', label: 'Admins', count: true },
    { key: 'users', label: 'Benutzer', count: true },
];

/** Klick auf den Spaltenkopf: aufsteigend, zweiter Klick absteigend (KONZEPT.md, "Listen"). */
function sortBy(key: TenantSortKey): void {
    if (sort.value === key) {
        dir.value = dir.value === 'asc' ? 'desc' : 'asc';
    } else {
        sort.value = key;
        dir.value = 'asc';
    }
}

function ariaSort(key: TenantSortKey): 'ascending' | 'descending' | 'none' {
    if (sort.value !== key) {
        return 'none';
    }
    return dir.value === 'asc' ? 'ascending' : 'descending';
}

const STATE_BADGES = {
    active: { className: 'badge-ready', icon: '✓' },
    invitation: { className: 'badge-maintenance', icon: '◷' },
    locked: { className: 'badge-retired', icon: '✕' },
} as const;
</script>

<template>
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

    <p v-if="tenants.length > 0 && rows.length === 0" class="hint">
        Keine Stadt passt zu „{{ query.trim() }}“.
    </p>

    <div v-if="rows.length > 0" class="table-card">
        <table class="list-table" data-testid="tenant-table">
            <thead>
                <tr>
                    <th
                        v-for="column in COLUMNS"
                        :key="column.key"
                        scope="col"
                        :aria-sort="ariaSort(column.key)"
                        :class="{ 'col-count': column.count === true }"
                    >
                        <button class="th" type="button" @click="sortBy(column.key)">
                            {{ column.label }}
                            <span class="th-arrow" aria-hidden="true">{{
                                sort === column.key ? (dir === 'asc' ? '▲' : '▼') : ''
                            }}</span>
                        </button>
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="tenant in rows"
                    :key="tenant.id"
                    :class="{ 'row-inactive': !tenant.active }"
                    :data-testid="`tenant-${tenant.slug}`"
                >
                    <td class="col-name">
                        <RouterLink
                            class="tenant-name"
                            :to="{ name: 'tenant', params: { id: String(tenant.id) } }"
                            >{{ tenant.name }}</RouterLink
                        >
                    </td>
                    <td class="col-address">{{ tenantPath(tenant.slug) }}</td>
                    <td class="col-status">
                        <span class="badge" :class="STATE_BADGES[tenantState(tenant)].className">
                            <span aria-hidden="true">{{
                                STATE_BADGES[tenantState(tenant)].icon
                            }}</span>
                            {{ TENANT_STATE_LABELS[tenantState(tenant)] }}
                        </span>
                    </td>
                    <td class="col-count">
                        <span class="card-label">Admins: </span>{{ tenant.adminsActive }}
                        <span v-if="tenant.adminsInvited > 0" class="count-hint">
                            ({{ countText(tenant.adminsInvited, 'eingeladen', 'eingeladen') }})
                        </span>
                    </td>
                    <td class="col-count">
                        <span class="card-label">Benutzer: </span>{{ tenant.usersActive }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>

<style scoped>
.hint {
    margin: 0;
    color: var(--muted);
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
    padding: 12px 14px;
    border-top: 1px solid var(--border-subtle);
}

/* Zeilen am PC kräftig hinterlegt mit Randbalken links (KONZEPT.md, "Oberfläche: Grundsätze"). */
.list-table tbody tr:hover td {
    background: var(--surface-hover);
}

.list-table tbody tr:hover td:first-child {
    box-shadow: inset 4px 0 0 var(--line);
}

.tenant-name {
    color: var(--text);
    font-weight: var(--font-weight-bold);
    text-decoration: none;
    overflow-wrap: anywhere;
}

/* Der Name öffnet das Stadtdetail (Entscheidung 44). */
.tenant-name:hover {
    color: var(--line);
    text-decoration: underline;
}

.row-inactive .tenant-name {
    color: var(--muted);
}

.col-address {
    color: var(--text-secondary);
    overflow-wrap: anywhere;
}

.col-count {
    width: 120px;
    white-space: nowrap;
}

.count-hint {
    color: var(--muted);
    font-size: var(--font-size-sm);
}

/* Bezeichnung nur in den Karten am Handy (am PC steht sie im Spaltenkopf). */
.card-label {
    display: none;
}

/*
 * Am PC sortieren die Spaltenköpfe, am Handy Knöpfe (wie die Spaltenköpfe: erneut tippen kehrt die
 * Richtung um). Kein <select> (CSP in Playwright-WebKit, CLAUDE.md "Frontend ausliefern").
 */
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
        gap: 4px 12px;
        padding: 12px 16px;
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

    .col-address {
        grid-column: 1 / -1;
        grid-row: 2;
        font-size: var(--font-size-sm);
    }

    .col-status {
        grid-column: 2;
        grid-row: 1;
    }

    .col-count {
        width: auto;
        color: var(--text-secondary);
        font-size: var(--font-size-md);
    }

    .card-label {
        display: inline;
    }
}
</style>
