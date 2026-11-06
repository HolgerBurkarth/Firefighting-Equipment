<script setup lang="ts">
import { computed, ref, shallowRef, useId, watch } from 'vue';
import { RouterLink, useRouter } from 'vue-router';
import type { ApiError } from '../api/client';
import { fetchTenantRoles, type PermissionCatalog, type Role } from '../api/roles';
import { phpTrim } from '../data/masterData';
import { deleteBlocker, roleSummary, ROLE_NAME_MAX_LENGTH } from '../data/roles';
import { nameProblem } from '../data/masterData';
import {
    copyRole,
    importRole,
    permissionCatalog,
    removeRole,
    setRoleNotice,
    takeRoleNotice,
    tenantList,
} from '../data/service';
import { useUnsavedInput } from '../runtime/unsavedInput';
import AppSelect from './AppSelect.vue';
import ConfirmDialog from './ConfirmDialog.vue';
import ErrorNotice from './ErrorNotice.vue';
import FormDialog from './FormDialog.vue';
import { vRowLink } from './rowLink';

/**
 * Reiter „Rollen“ im Stadtdetail des Super-Admins (Plan Phase 1, Schritt 1.5; Mockup „Main“):
 * Tabelle Rolle (Name, „fest“/„Standard“, Kurzbeschreibung), Sieht, Darf, Sieht nur …, Personen und
 * Aktionen (Admin nur „Ansehen“; sonst „Bearbeiten“, „Kopieren“, „Löschen …“ nur ohne Nutzung),
 * „Neue Rolle“, „Rolle aus anderer Stadt übernehmen …“ und der Kasten „Regeln“. Geladen, sobald der
 * Reiter sichtbar ist.
 */
const props = defineProps<{ tenantId: number; tenantName: string; active: boolean }>();
const id = useId();
const router = useRouter();

const roles = shallowRef<Role[] | null>(null);
const catalog = shallowRef<PermissionCatalog | null>(null);
const loadError = shallowRef<ApiError | null>(null);
const pageError = shallowRef<ApiError | null>(null);
const success = ref<string | null>(takeRoleNotice());
const busyId = ref<number | null>(null);

async function load(): Promise<void> {
    loadError.value = null;
    const [rolesResult, catalogResult] = await Promise.all([
        fetchTenantRoles(props.tenantId),
        permissionCatalog(),
    ]);
    if (!rolesResult.ok) {
        loadError.value = rolesResult.error;
        return;
    }
    if (!catalogResult.ok) {
        loadError.value = catalogResult.error;
        return;
    }
    roles.value = rolesResult.data;
    catalog.value = catalogResult.data;
}

const rows = computed(() => {
    const current = catalog.value;
    return current === null
        ? []
        : (roles.value ?? []).map((role) => ({ role, summary: roleSummary(current, role) }));
});

function chip(role: Role): string | null {
    return role.systemKey === 'admin' ? 'fest' : role.systemKey === 'user' ? 'Standard' : null;
}

async function copy(role: Role): Promise<void> {
    pageError.value = null;
    success.value = null;
    busyId.value = role.id;
    const result = await copyRole(props.tenantId, role.id);
    busyId.value = null;
    if (!result.ok) {
        pageError.value = result.error;
        await load();
        return;
    }
    setRoleNotice(`Kopie „${result.data.name}“ angelegt. Sie können sie jetzt anpassen.`);
    await router.push({ name: 'role', params: { id: props.tenantId, role: result.data.id } });
}

// Löschen
// Die gewählte Rolle bleibt nach dem Schließen stehen (die Rückfrage schließt vor „confirm“).
const deleteTarget = shallowRef<Role | null>(null);
const deleteOpen = ref(false);

function askDelete(role: Role): void {
    deleteTarget.value = role;
    deleteOpen.value = true;
}

async function confirmDelete(): Promise<void> {
    const role = deleteTarget.value;
    if (role === null) {
        return;
    }
    pageError.value = null;
    success.value = null;
    busyId.value = role.id;
    const result = await removeRole(props.tenantId, role.id);
    busyId.value = null;
    if (result.ok) {
        success.value = `Rolle „${role.name}“ gelöscht.`;
    } else if (result.error.code !== 'not_found') {
        pageError.value = result.error;
    }
    await load();
}

// Rolle aus anderer Stadt übernehmen
const importOpen = ref(false);
const sourceTenant = ref('');
const sourceRole = ref('');
const importName = ref('');
const sourceRoles = shallowRef<Role[] | null>(null);
const sourceError = shallowRef<ApiError | null>(null);
const importError = shallowRef<ApiError | null>(null);
const importNameError = ref<string | null>(null);
const importBusy = ref(false);
const opener = ref<HTMLElement | null>(null);

const unsaved = computed(
    () =>
        importOpen.value &&
        (sourceTenant.value !== '' || sourceRole.value !== '' || importName.value !== ''),
);
useUnsavedInput(() => unsaved.value);

const tenantOptions = computed(() =>
    (tenantList.state.items ?? [])
        .filter((tenant) => tenant.id !== props.tenantId)
        .map((tenant) => ({ value: String(tenant.id), label: tenant.name })),
);
const sourceRoleOptions = computed(() =>
    (sourceRoles.value ?? []).map((role) => ({ value: String(role.id), label: role.name })),
);
const importNameProblem = computed(() =>
    phpTrim(importName.value) === '' ? null : nameProblem(importName.value, ROLE_NAME_MAX_LENGTH),
);

function openImport(event: Event): void {
    opener.value = event.currentTarget instanceof HTMLElement ? event.currentTarget : null;
    sourceTenant.value = '';
    sourceRole.value = '';
    importName.value = '';
    sourceRoles.value = null;
    sourceError.value = null;
    importError.value = null;
    importNameError.value = null;
    importOpen.value = true;
    void tenantList.refresh('open');
}

watch(sourceTenant, async (value) => {
    sourceRole.value = '';
    sourceRoles.value = null;
    sourceError.value = null;
    if (value === '') {
        return;
    }
    const result = await fetchTenantRoles(Number(value));
    if (sourceTenant.value !== value) {
        return;
    }
    if (result.ok) {
        sourceRoles.value = result.data;
    } else {
        sourceError.value = result.error;
    }
});

async function submitImport(): Promise<void> {
    importError.value = null;
    importNameError.value = null;
    if (sourceRole.value === '' || importNameProblem.value !== null || importBusy.value) {
        return;
    }
    const name = phpTrim(importName.value);
    const source = sourceRoles.value?.find((role) => String(role.id) === sourceRole.value);
    const city = tenantOptions.value.find((option) => option.value === sourceTenant.value)?.label;
    importBusy.value = true;
    const result = await importRole(
        props.tenantId,
        Number(sourceRole.value),
        name === '' ? null : name,
    );
    importBusy.value = false;
    if (!result.ok) {
        if (result.error.code === 'name_taken' || result.error.code === 'invalid_request') {
            importNameError.value = result.error.message;
        } else {
            importError.value = result.error;
        }
        return;
    }
    importOpen.value = false;
    sourceTenant.value = '';
    sourceRole.value = '';
    importName.value = '';
    setRoleNotice(
        `Rolle „${result.data.name}“ aus „${city ?? 'anderer Stadt'}“ übernommen${
            source !== undefined && source.name !== result.data.name
                ? ` (Vorlage „${source.name}“)`
                : ''
        }: nur die Rechte, „Sieht nur …“ ist auf „alle“ gesetzt.`,
    );
    await router.push({ name: 'role', params: { id: props.tenantId, role: result.data.id } });
}

watch(
    () => props.active,
    (active) => {
        if (active) {
            void load();
        }
    },
    { immediate: true },
);
</script>

<template>
    <div class="roles">
        <p class="hint">
            Eine Rolle legt fest, was Personen dieser Stadt sehen und tun dürfen. Rollen anlegen und
            ändern kann nur der Super-Admin; welche Person welche Rolle hat, legt der Admin der
            Stadt unter „Benutzer“ fest.
        </p>
        <div class="toolbar-row">
            <button class="btn" type="button" @click="openImport">
                Rolle aus anderer Stadt übernehmen …
            </button>
            <RouterLink class="btn btn-accent" :to="{ name: 'role-new', params: { id: tenantId } }">
                Neue Rolle
            </RouterLink>
        </div>

        <p
            v-if="success !== null"
            class="notice notice-success"
            role="status"
            data-testid="roles-notice"
        >
            {{ success }}
        </p>
        <ErrorNotice v-if="pageError !== null" :error="pageError" />
        <ErrorNotice v-if="loadError !== null" :error="loadError">
            <button class="btn retry" type="button" @click="load">Erneut versuchen</button>
        </ErrorNotice>
        <p v-else-if="roles === null" class="hint" role="status">Rollen werden geladen …</p>

        <div v-else class="table-card">
            <table class="role-table" data-testid="role-table">
                <thead>
                    <tr>
                        <th scope="col">Rolle</th>
                        <th scope="col">Sieht</th>
                        <th scope="col">Darf</th>
                        <th scope="col">Sieht nur …</th>
                        <th scope="col" class="col-count">Personen</th>
                        <th scope="col"><span class="visually-hidden">Aktionen</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="{ role, summary } in rows"
                        :key="role.id"
                        v-row-link
                        :data-testid="`role-${String(role.id)}`"
                    >
                        <td>
                            <span class="role-name">{{ role.name }}</span>
                            <span v-if="chip(role) !== null" class="chip">{{ chip(role) }}</span>
                            <span v-if="role.description !== null" class="hint role-description">{{
                                role.description
                            }}</span>
                        </td>
                        <td>
                            <span class="cell-label" aria-hidden="true">Sieht</span
                            >{{ summary.sees }}
                        </td>
                        <td>
                            <span class="cell-label" aria-hidden="true">Darf</span>{{ summary.may }}
                        </td>
                        <td>
                            <span class="cell-label" aria-hidden="true">Sieht nur …</span
                            >{{ summary.filter }}
                        </td>
                        <td class="col-count">
                            <span class="cell-label" aria-hidden="true">Personen</span
                            >{{ role.accounts }}
                        </td>
                        <td class="col-actions">
                            <RouterLink
                                class="btn"
                                :class="{ 'btn-quiet': !role.editable }"
                                data-row-link
                                :to="{ name: 'role', params: { id: tenantId, role: role.id } }"
                                :aria-label="`${role.editable ? 'Bearbeiten' : 'Ansehen'}: ${role.name}`"
                            >
                                {{ role.editable ? 'Bearbeiten' : 'Ansehen' }}
                            </RouterLink>
                            <button
                                v-if="role.systemKey !== 'admin'"
                                class="btn btn-quiet"
                                type="button"
                                :disabled="busyId !== null"
                                :aria-label="`Kopieren: ${role.name}`"
                                @click="copy(role)"
                            >
                                {{ busyId === role.id ? 'Wird kopiert …' : 'Kopieren' }}
                            </button>
                            <button
                                v-if="role.deletable && deleteBlocker(role) === null"
                                class="btn btn-quiet"
                                type="button"
                                :disabled="busyId !== null"
                                :aria-label="`${role.name} löschen …`"
                                @click="askDelete(role)"
                            >
                                Löschen …
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <aside class="card rules" :aria-labelledby="`${id}-rules`">
            <h2 :id="`${id}-rules`">Regeln</h2>
            <ul>
                <li>Löschen geht nur bei Rollen ohne Personen und ohne offene Einladungscodes.</li>
                <li>
                    Änderungen wirken für alle Personen der Rolle beim nächsten Aufruf der App, auch
                    auf dem Handy.
                </li>
                <li>
                    Die Rolle „Admin“ hat immer alle Rechte, und jede Stadt behält mindestens einen
                    aktiven Admin.
                </li>
                <li>
                    Rollen mit „Sieht nur …“ dürfen nicht verwalten (Benutzer, Standorte und
                    Ausrüstungstypen ändern, CSV-Import).
                </li>
            </ul>
        </aside>

        <ConfirmDialog
            v-model:open="deleteOpen"
            :title="`Rolle „${deleteTarget?.name ?? ''}“ löschen?`"
            :description="`Die Rolle wird in ${tenantName} endgültig gelöscht. Niemand hat sie, und kein offener Einladungscode zeigt auf sie.`"
            confirm-label="Löschen"
            @confirm="confirmDelete"
        />

        <FormDialog
            v-model:open="importOpen"
            :unsaved="unsaved"
            title="Rolle aus anderer Stadt übernehmen"
            description="Übernommen werden Name, Kurzbeschreibung und Rechte. „Sieht nur …“ nennt Standorte der anderen Stadt und wird daher auf „alle“ gesetzt."
            submit-label="Übernehmen"
            :busy="importBusy"
            :can-submit="sourceRole !== '' && importNameProblem === null"
            :error="importError"
            :return-focus="opener"
            @submit="submitImport"
        >
            <AppSelect
                v-model="sourceTenant"
                label="Stadt"
                placeholder="Bitte wählen"
                :options="tenantOptions"
            />
            <ErrorNotice v-if="sourceError !== null" :error="sourceError" />
            <AppSelect
                v-if="sourceTenant !== '' && sourceRoles !== null"
                v-model="sourceRole"
                label="Rolle"
                placeholder="Bitte wählen"
                :options="sourceRoleOptions"
            />
            <label class="field">
                <span class="field-label">Name in {{ tenantName }} (freiwillig)</span>
                <input
                    v-model="importName"
                    class="input"
                    type="text"
                    autocomplete="off"
                    :aria-invalid="(importNameError ?? importNameProblem) !== null"
                    @input="importNameError = null"
                />
                <span class="hint">Leer: Name der Vorlage (bei Gleichstand mit „(2)“).</span>
                <p v-if="(importNameError ?? importNameProblem) !== null" class="field-error">
                    {{ importNameError ?? importNameProblem }}
                </p>
            </label>
        </FormDialog>
    </div>
</template>

<style scoped>
.roles {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.hint {
    margin: 0;
    color: var(--muted);
}

.retry {
    align-self: flex-start;
}

.toolbar-row {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 12px;
}

.table-card {
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    background: var(--surface);
    overflow: hidden;
}

.role-table {
    width: 100%;
    border-collapse: collapse;
}

.role-table th {
    padding: 12px 14px;
    color: var(--text-secondary);
    font-size: var(--font-size-sm);
    text-align: left;
    white-space: nowrap;
}

.role-table td {
    padding: 14px;
    border-top: 1px solid var(--border-subtle);
    text-align: left;
    vertical-align: top;
}

.role-name {
    font-weight: var(--font-weight-semibold);
}

.role-description {
    display: block;
    font-size: var(--font-size-sm);
}

.chip {
    display: inline-flex;
    margin-left: 6px;
    padding: 1px 8px;
    border-radius: var(--radius-pill);
    background: var(--border-subtle);
    color: var(--text-secondary);
    font-size: var(--font-size-sm);
    font-weight: var(--font-weight-semibold);
}

.col-count {
    text-align: right;
}

.role-table td.col-count {
    text-align: right;
}

.col-actions {
    white-space: nowrap;
}

.col-actions .btn {
    margin: 0 0 4px 4px;
}

.btn-quiet {
    border-color: transparent;
    background: transparent;
}

.cell-label {
    display: none;
}

.rules h2 {
    margin: 0;
    font-size: var(--font-size-lg);
}

.rules ul {
    margin: 0;
    padding-left: 20px;
}

@media (max-width: 899px) {
    .role-table thead {
        display: none;
    }

    .role-table,
    .role-table tbody,
    .role-table tr,
    .role-table td {
        display: block;
    }

    .role-table tr {
        padding: 8px 0;
        border-top: 1px solid var(--border);
    }

    .role-table tr:first-child {
        border-top: 0;
    }

    .role-table td,
    .role-table td.col-count {
        padding: 4px 16px;
        border-top: 0;
        text-align: left;
    }

    .cell-label {
        display: block;
        color: var(--text-secondary);
        font-size: var(--font-size-sm);
        font-weight: var(--font-weight-semibold);
    }

    .col-actions {
        white-space: normal;
    }

    .col-actions .btn {
        margin: 4px 8px 0 0;
    }
}
</style>
