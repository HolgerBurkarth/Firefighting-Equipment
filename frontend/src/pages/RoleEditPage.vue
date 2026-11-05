<script setup lang="ts">
import { computed, onMounted, ref, shallowRef, useId, watch } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import type { ApiError } from '../api/client';
import {
    fetchRoleFilterOptions,
    fetchTenantRole,
    parseRoleResponse,
    type AreaLevel,
    type FilterOptions,
    type PermissionCatalog,
    type Role,
} from '../api/roles';
import { fetchTenant, type ManagedTenant } from '../api/tenants';
import ConfirmDialog from '../components/ConfirmDialog.vue';
import ErrorNotice from '../components/ErrorNotice.vue';
import {
    actionState,
    deleteBlocker,
    emptyRoleForm,
    filterNote,
    hasProblems,
    isFiltered,
    levelOptions,
    peopleText,
    roleForm,
    roleFormProblems,
    rolePreview,
    roleRequest,
    sameRoleForm,
    type FilterMode,
    type RoleForm,
} from '../data/roles';
import {
    createRole,
    permissionCatalog,
    removeRole,
    saveRole,
    setRoleNotice,
    takeRoleNotice,
    tenantList,
} from '../data/service';
import { useUnsavedInput } from '../runtime/unsavedInput';

/**
 * „Rolle bearbeiten“ des Super-Admins (Plan Phase 1, Schritt 1.5; Mockup „Rolle-bearbeiten“):
 * `staedte/<id>/rollen/<rolle>` bzw. `staedte/<id>/rollen/neu`. Name und Kurzbeschreibung, „1 ·
 * Ansichten und Bereiche“ (Knöpfe je Stufe), „2 · Aktionen“ (grau ohne den nötigen Bereich), „4 ·
 * Sieht nur …“ (Standorte und Ausrüstungstypen; „3 · Einschränkungen“ erst ab Phase 2), rechts „So
 * sieht es die Rolle“. F3: Mit „Sieht nur …“ sind Verwaltungsrechte grau. Die Rolle Admin nur zum
 * Ansehen. Nach dem Speichern bzw. Löschen zurück zu den Rollen der Stadt.
 */
const id = useId();
const route = useRoute();
const router = useRouter();
const tenantId = computed(() => Number(route.params['id']));
const roleId = computed(() => (route.name === 'role-new' ? null : Number(route.params['role'])));

const catalog = shallowRef<PermissionCatalog | null>(null);
const options = shallowRef<FilterOptions | null>(null);
const role = shallowRef<Role | null>(null);
const loadedTenant = shallowRef<ManagedTenant | null>(null);
const tenant = computed(
    () =>
        loadedTenant.value ??
        tenantList.state.items?.find((entry) => entry.id === tenantId.value) ??
        null,
);
const loadError = shallowRef<ApiError | null>(null);
const notFound = ref(false);

const form = ref<RoleForm>(emptyRoleForm());
const initial = shallowRef<RoleForm>(emptyRoleForm());
const nameTouched = ref(false);
const triedSave = ref(false);
const saving = ref(false);
const saveError = shallowRef<ApiError | null>(null);
const nameError = ref<string | null>(null);
const conflictNotice = ref<string | null>(null);
const notice = ref<string | null>(takeRoleNotice());
const deleteOpen = ref(false);
const deleteError = shallowRef<ApiError | null>(null);

const readOnly = computed(() => role.value !== null && !role.value.editable);
const isNew = computed(() => roleId.value === null);
const ready = computed(() => catalog.value !== null && (isNew.value || role.value !== null));

const unsaved = computed(
    () => ready.value && !readOnly.value && !sameRoleForm(form.value, initial.value),
);
useUnsavedInput(() => unsaved.value);

const problems = computed(() =>
    catalog.value === null ? null : roleFormProblems(form.value, catalog.value),
);
const shownNameError = computed(
    () =>
        nameError.value ??
        (nameTouched.value || triedSave.value ? (problems.value?.name ?? null) : null),
);
const preview = computed(() =>
    catalog.value === null ? null : rolePreview(catalog.value, form.value, options.value),
);
const blocker = computed(() => (role.value === null ? null : deleteBlocker(role.value)));
const title = computed(() =>
    isNew.value ? 'Neue Rolle' : readOnly.value ? 'Rolle ansehen' : 'Rolle bearbeiten',
);

async function load(): Promise<void> {
    loadError.value = null;
    notFound.value = false;
    const [catalogResult, optionsResult, roleResult, tenantResult] = await Promise.all([
        permissionCatalog(),
        fetchRoleFilterOptions(tenantId.value),
        roleId.value === null
            ? Promise.resolve(null)
            : fetchTenantRole(tenantId.value, roleId.value),
        fetchTenant(tenantId.value),
    ]);
    if (tenantResult.ok) {
        loadedTenant.value = tenantResult.data;
    }
    if (optionsResult.ok) {
        options.value = optionsResult.data;
    }
    if (roleResult !== null && !roleResult.ok) {
        if (roleResult.error.code === 'not_found') {
            notFound.value = true;
            return;
        }
        loadError.value = roleResult.error;
    } else if (roleResult !== null) {
        setRole(roleResult.data);
    } else {
        form.value = emptyRoleForm();
        initial.value = emptyRoleForm();
    }
    if (!catalogResult.ok) {
        loadError.value = catalogResult.error;
        return;
    }
    if (!optionsResult.ok) {
        loadError.value = optionsResult.error;
        return;
    }
    if (!tenantResult.ok && tenantResult.error.code === 'not_found') {
        notFound.value = true;
        return;
    }
    catalog.value = catalogResult.data;
}

function setRole(next: Role): void {
    role.value = next;
    form.value = roleForm(next);
    initial.value = roleForm(next);
}

function update(change: Partial<RoleForm>): void {
    form.value = { ...form.value, ...change };
    saveError.value = null;
    conflictNotice.value = null;
}

function setLevel(area: string, level: AreaLevel | null): void {
    const areas = Object.fromEntries(
        Object.entries(form.value.areas).filter(([key]) => key !== area),
    );
    if (level !== null) {
        areas[area] = level;
    }
    update({ areas });
}

function toggleAction(key: string): void {
    const actions = form.value.actions.includes(key)
        ? form.value.actions.filter((action) => action !== key)
        : [...form.value.actions, key];
    update({ actions });
}

function toggleId(list: readonly number[], value: number): number[] {
    return list.includes(value) ? list.filter((entry) => entry !== value) : [...list, value];
}

function setLocationsMode(mode: FilterMode): void {
    update({ locationsMode: mode });
}

function setTypesMode(mode: FilterMode): void {
    update({ typesMode: mode });
}

function toRoles(): Promise<void> {
    const back: unknown = router.options.history.state['back'];
    if (typeof back === 'string' && router.resolve(back).name === 'tenant') {
        router.back();
        return Promise.resolve();
    }
    return router
        .replace({ name: 'tenant', params: { id: tenantId.value }, query: { ansicht: 'rollen' } })
        .then(() => undefined);
}

async function save(): Promise<void> {
    const current = catalog.value;
    triedSave.value = true;
    saveError.value = null;
    nameError.value = null;
    conflictNotice.value = null;
    if (
        current === null ||
        problems.value === null ||
        hasProblems(problems.value) ||
        saving.value
    ) {
        return;
    }
    if (!isNew.value && !unsaved.value) {
        await toRoles();
        return;
    }
    const request = roleRequest(form.value, current);
    saving.value = true;
    const result =
        role.value === null
            ? await createRole(tenantId.value, request)
            : await saveRole(tenantId.value, role.value.id, role.value.version, request);
    saving.value = false;
    if (result.ok) {
        initial.value = form.value;
        setRoleNotice(
            role.value === null
                ? `Rolle „${result.data.name}“ angelegt.`
                : `Rolle „${result.data.name}“ gespeichert.`,
        );
        await toRoles();
        return;
    }
    const error = result.error;
    if (
        error.code === 'name_taken' ||
        (error.code === 'invalid_request' && (error.message ?? '').includes('Name der Rolle'))
    ) {
        nameError.value = error.message ?? 'Bitte einen anderen Namen wählen.';
        return;
    }
    if (error.code === 'version_conflict') {
        const latest = parseRoleResponse(error.details);
        if (latest !== null) {
            setRole(latest);
            conflictNotice.value =
                'Die Rolle wurde inzwischen geändert. Angezeigt wird jetzt der neue Stand; Ihre Änderungen sind nicht gespeichert.';
            return;
        }
    }
    saveError.value = error;
}

async function confirmDelete(): Promise<void> {
    const current = role.value;
    if (current === null) {
        return;
    }
    deleteError.value = null;
    saving.value = true;
    const result = await removeRole(tenantId.value, current.id);
    saving.value = false;
    if (result.ok || result.error.code === 'not_found') {
        initial.value = form.value;
        setRoleNotice(`Rolle „${current.name}“ gelöscht.`);
        await toRoles();
        return;
    }
    deleteError.value = result.error;
}

function locationLabel(name: string, active: boolean): string {
    return active ? name : `${name} (gesperrt)`;
}

watch([tenantId, roleId], () => {
    catalog.value = null;
    role.value = null;
    nameTouched.value = false;
    triedSave.value = false;
    void load();
});

onMounted(() => {
    void load();
});
</script>

<template>
    <nav class="breadcrumb" aria-label="Pfad">
        <RouterLink :to="{ name: 'tenants' }">Städte</RouterLink>
        <span aria-hidden="true">›</span>
        <RouterLink :to="{ name: 'tenant', params: { id: tenantId } }">{{
            tenant?.name ?? 'Stadt'
        }}</RouterLink>
        <span aria-hidden="true">›</span>
        <RouterLink :to="{ name: 'tenant', params: { id: tenantId }, query: { ansicht: 'rollen' } }"
            >Rollen</RouterLink
        >
        <template v-if="role !== null">
            <span aria-hidden="true">›</span>
            <span>{{ role.name }}</span>
        </template>
    </nav>

    <h1>{{ title }}</h1>

    <p v-if="notFound" class="hint">Diese Rolle gibt es nicht (mehr).</p>
    <ErrorNotice v-else-if="loadError !== null" :error="loadError">
        <button class="btn retry" type="button" @click="load">Erneut versuchen</button>
    </ErrorNotice>
    <p v-else-if="!ready || catalog === null || preview === null" class="hint" role="status">
        Rolle wird geladen …
    </p>

    <template v-else>
        <p
            v-if="notice !== null"
            class="notice notice-success"
            role="status"
            data-testid="success-notice"
        >
            {{ notice }}
        </p>
        <p v-if="readOnly" class="notice notice-info" data-testid="read-only">
            Die Rolle „Admin“ hat immer alle Rechte, auch künftige, und lässt sich nicht ändern.
            Jede Stadt behält mindestens einen aktiven Admin.
        </p>
        <p v-if="conflictNotice !== null" class="notice notice-error" role="alert">
            {{ conflictNotice }}
        </p>

        <div class="layout">
            <form class="editor" novalidate @submit.prevent="save">
                <fieldset class="plain" :disabled="readOnly">
                    <section class="card" :aria-labelledby="`${id}-name`">
                        <h2 :id="`${id}-name`">Name</h2>
                        <label class="field">
                            <span class="field-label">Name der Rolle</span>
                            <input
                                class="input"
                                type="text"
                                autocomplete="off"
                                :value="form.name"
                                :aria-invalid="shownNameError !== null"
                                :aria-describedby="
                                    shownNameError !== null ? `${id}-name-error` : undefined
                                "
                                @input="
                                    update({ name: ($event.target as HTMLInputElement).value });
                                    nameTouched = true;
                                    nameError = null;
                                "
                            />
                            <p
                                v-if="shownNameError !== null"
                                :id="`${id}-name-error`"
                                class="field-error"
                            >
                                {{ shownNameError }}
                            </p>
                        </label>
                        <label class="field">
                            <span class="field-label"
                                >Kurzbeschreibung (für den Admin der Stadt)</span
                            >
                            <input
                                class="input"
                                type="text"
                                autocomplete="off"
                                :value="form.description"
                                :aria-invalid="problems?.description != null"
                                @input="
                                    update({
                                        description: ($event.target as HTMLInputElement).value,
                                    })
                                "
                            />
                            <p v-if="problems?.description != null" class="field-error">
                                {{ problems.description }}
                            </p>
                        </label>
                    </section>

                    <section class="card" :aria-labelledby="`${id}-areas`">
                        <h2 :id="`${id}-areas`">1 · Ansichten und Bereiche</h2>
                        <p class="hint">
                            Ohne Zugriff fehlt die Seite im Menü, und der Server lehnt jeden Aufruf
                            ab. „Ändern“ schließt „Ansehen“ ein.
                        </p>
                        <div
                            v-for="area in catalog.areas"
                            :key="area.key"
                            class="area-row"
                            :data-testid="`area-${area.key}`"
                        >
                            <div class="area-text">
                                <span :id="`${id}-area-${area.key}`" class="area-label">{{
                                    area.label
                                }}</span>
                                <span class="hint">{{ area.hint }}</span>
                                <span
                                    v-if="isFiltered(form) && filterNote(area, catalog) !== null"
                                    class="note"
                                >
                                    {{ filterNote(area, catalog) }}
                                </span>
                            </div>
                            <div
                                class="levels"
                                role="group"
                                :aria-labelledby="`${id}-area-${area.key}`"
                            >
                                <button
                                    v-for="option in levelOptions(catalog, area, form)"
                                    :key="option.label"
                                    class="level"
                                    type="button"
                                    :aria-pressed="option.pressed"
                                    :disabled="option.disabled"
                                    @click="setLevel(area.key, option.level)"
                                >
                                    {{ option.label }}
                                </button>
                            </div>
                        </div>
                    </section>

                    <section class="card" :aria-labelledby="`${id}-actions`">
                        <h2 :id="`${id}-actions`">2 · Aktionen</h2>
                        <p class="hint">
                            Grau heißt: Der Bereich darüber reicht nicht. Die Aktion bleibt angehakt
                            und wirkt, sobald der Bereich freigegeben ist.
                        </p>
                        <fieldset
                            v-for="group in catalog.actionGroups"
                            :key="group.label"
                            class="group"
                        >
                            <legend class="field-label">{{ group.label }}</legend>
                            <label
                                v-for="action in group.actions"
                                :key="action.key"
                                class="check action"
                                :class="{ grey: actionState(catalog, form, action).note !== null }"
                            >
                                <input
                                    type="checkbox"
                                    :checked="actionState(catalog, form, action).checked"
                                    :disabled="actionState(catalog, form, action).disabled"
                                    @change="toggleAction(action.key)"
                                />
                                <span>
                                    {{ action.label }}
                                    <span
                                        v-if="actionState(catalog, form, action).note !== null"
                                        class="note"
                                    >
                                        {{ actionState(catalog, form, action).note }}
                                    </span>
                                </span>
                            </label>
                        </fieldset>
                    </section>

                    <section class="card" :aria-labelledby="`${id}-filter`">
                        <h2 :id="`${id}-filter`">4 · Sieht nur …</h2>
                        <p class="hint">
                            Die Rolle sieht nur Ausrüstung, deren Typ und Standort hier erlaubt sind
                            – überall: Listen, Suche, Zähler, Termine, CSV, Mails und Offline-Kopie.
                            Mit „Sieht nur …“ darf sie nicht verwalten (Benutzer, Standorte und
                            Ausrüstungstypen ändern, CSV-Import).
                        </p>
                        <fieldset class="group" data-testid="filter-locations">
                            <legend class="field-label">Standorte</legend>
                            <label class="check">
                                <input
                                    type="radio"
                                    :name="`${id}-locations`"
                                    :checked="form.locationsMode === 'all'"
                                    @change="setLocationsMode('all')"
                                />
                                Alle Standorte
                            </label>
                            <label class="check">
                                <input
                                    type="radio"
                                    :name="`${id}-locations`"
                                    :checked="form.locationsMode === 'some'"
                                    @change="setLocationsMode('some')"
                                />
                                Nur ausgewählte
                            </label>
                            <template v-if="form.locationsMode === 'some'">
                                <p
                                    v-if="options !== null && options.locations.length === 0"
                                    class="hint"
                                >
                                    Die Stadt hat noch keine Standorte.
                                </p>
                                <ul class="choices">
                                    <li
                                        v-for="location in options?.locations ?? []"
                                        :key="location.id"
                                    >
                                        <label class="check">
                                            <input
                                                type="checkbox"
                                                :checked="form.locations.includes(location.id)"
                                                @change="
                                                    update({
                                                        locations: toggleId(
                                                            form.locations,
                                                            location.id,
                                                        ),
                                                    })
                                                "
                                            />
                                            {{ locationLabel(location.name, location.active) }}
                                            <span class="hint">{{ location.kindLabel }}</span>
                                        </label>
                                        <ul
                                            v-if="location.children.length > 0"
                                            class="choices nested"
                                        >
                                            <li v-for="child in location.children" :key="child.id">
                                                <label class="check">
                                                    <input
                                                        type="checkbox"
                                                        :checked="form.locations.includes(child.id)"
                                                        @change="
                                                            update({
                                                                locations: toggleId(
                                                                    form.locations,
                                                                    child.id,
                                                                ),
                                                            })
                                                        "
                                                    />
                                                    {{ locationLabel(child.name, child.active) }}
                                                </label>
                                            </li>
                                        </ul>
                                    </li>
                                </ul>
                                <p class="hint">
                                    Ein Standort schließt seine Fächer ein, auch später angelegte.
                                </p>
                                <p
                                    v-if="problems?.locations != null"
                                    class="field-error"
                                    data-testid="locations-problem"
                                >
                                    {{ problems.locations }}
                                </p>
                            </template>
                        </fieldset>
                        <fieldset class="group" data-testid="filter-types">
                            <legend class="field-label">Ausrüstungstypen</legend>
                            <label class="check">
                                <input
                                    type="radio"
                                    :name="`${id}-types`"
                                    :checked="form.typesMode === 'all'"
                                    @change="setTypesMode('all')"
                                />
                                Alle Ausrüstungstypen
                            </label>
                            <label class="check">
                                <input
                                    type="radio"
                                    :name="`${id}-types`"
                                    :checked="form.typesMode === 'some'"
                                    @change="setTypesMode('some')"
                                />
                                Nur ausgewählte
                            </label>
                            <template v-if="form.typesMode === 'some'">
                                <ul class="choices">
                                    <li v-for="type in options?.deviceTypes ?? []" :key="type.id">
                                        <label class="check">
                                            <input
                                                type="checkbox"
                                                :checked="form.deviceTypes.includes(type.id)"
                                                @change="
                                                    update({
                                                        deviceTypes: toggleId(
                                                            form.deviceTypes,
                                                            type.id,
                                                        ),
                                                    })
                                                "
                                            />
                                            {{
                                                type.active
                                                    ? type.name
                                                    : `${type.name} (deaktiviert)`
                                            }}
                                        </label>
                                    </li>
                                </ul>
                                <p
                                    v-if="problems?.deviceTypes != null"
                                    class="field-error"
                                    data-testid="types-problem"
                                >
                                    {{ problems.deviceTypes }}
                                </p>
                            </template>
                        </fieldset>
                        <p
                            v-if="problems?.conflicts != null"
                            class="field-error"
                            data-testid="filter-conflicts"
                        >
                            {{ problems.conflicts }}
                        </p>
                    </section>
                </fieldset>

                <ErrorNotice v-if="saveError !== null" :error="saveError" />
                <ErrorNotice v-if="deleteError !== null" :error="deleteError" />
                <p
                    v-if="triedSave && problems !== null && hasProblems(problems)"
                    class="field-error"
                    data-testid="save-problem"
                >
                    Nicht gespeichert: Bitte die markierten Angaben prüfen.
                </p>
                <div class="buttons">
                    <button
                        v-if="!readOnly"
                        class="btn btn-primary"
                        type="submit"
                        :disabled="saving"
                    >
                        {{ saving ? 'Wird gespeichert …' : 'Speichern' }}
                    </button>
                    <button class="btn" type="button" @click="toRoles">
                        {{ readOnly ? 'Zurück' : 'Abbrechen' }}
                    </button>
                    <button
                        v-if="role !== null && role.deletable"
                        class="btn btn-danger"
                        type="button"
                        :disabled="blocker !== null || saving"
                        @click="deleteOpen = true"
                    >
                        Rolle löschen …
                    </button>
                </div>
                <p v-if="role !== null && role.deletable && blocker !== null" class="hint">
                    {{ blocker }}
                </p>
            </form>

            <aside class="side" :aria-labelledby="`${id}-preview`">
                <section class="card" data-testid="role-preview">
                    <h2 :id="`${id}-preview`">So sieht es die Rolle</h2>
                    <div>
                        <h3>Menü am PC</h3>
                        <ul v-if="preview.menu.length > 0" class="preview-list">
                            <li v-for="entry in preview.menu" :key="entry">{{ entry }}</li>
                        </ul>
                        <p v-else class="hint">Keine Konsole – nur die Handy-Seiten.</p>
                    </div>
                    <div>
                        <h3>Leiste am Handy</h3>
                        <p>{{ preview.bar.join(' · ') }}</p>
                    </div>
                    <div>
                        <h3>Darf</h3>
                        <p>{{ preview.may }}</p>
                    </div>
                    <div>
                        <h3>Sieht Ausrüstung</h3>
                        <p>{{ preview.sees }}</p>
                    </div>
                </section>
                <section v-if="role !== null && !readOnly" class="card" data-testid="role-impact">
                    <p>
                        Die Änderung gilt sofort für <strong>{{ peopleText(role.accounts) }}</strong
                        >. Offene Sitzungen bekommen die neuen Rechte beim nächsten Aufruf; Handys
                        laden dabei ihre Offline-Kopie neu.
                    </p>
                </section>
            </aside>
        </div>

        <ConfirmDialog
            v-if="role !== null"
            v-model:open="deleteOpen"
            :title="`Rolle „${role.name}“ löschen?`"
            description="Die Rolle wird endgültig gelöscht. Niemand hat sie, und kein offener Einladungscode zeigt auf sie."
            confirm-label="Löschen"
            @confirm="confirmDelete"
        />
    </template>
</template>

<style scoped>
.hint {
    margin: 0;
    color: var(--muted);
}

.retry {
    align-self: flex-start;
}

.breadcrumb {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    color: var(--muted);
}

.layout {
    display: grid;
    gap: 24px;
}

@media (min-width: 1100px) {
    .layout {
        grid-template-columns: minmax(0, 1fr) 320px;
        align-items: start;
    }

    .side {
        position: sticky;
        top: 16px;
    }
}

.editor,
.plain,
.side {
    display: flex;
    flex-direction: column;
    gap: 16px;
    min-width: 0;
}

.plain {
    margin: 0;
    padding: 0;
    border: 0;
}

.card h2 {
    margin: 0;
    font-size: var(--font-size-lg);
}

.card h3 {
    margin: 0 0 4px;
    font-size: var(--font-size-md);
    color: var(--text-secondary);
}

.card p {
    margin: 0;
}

.area-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 8px 16px;
    padding: 10px 0;
    border-top: 1px solid var(--border-subtle);
}

.area-text {
    display: flex;
    flex: 1 1 240px;
    flex-direction: column;
    min-width: 0;
}

.area-label {
    font-weight: var(--font-weight-semibold);
}

.note {
    color: var(--text-secondary);
    font-size: var(--font-size-sm);
}

.levels {
    display: inline-flex;
    flex-wrap: wrap;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    overflow: hidden;
}

.level {
    min-height: var(--touch-min);
    padding: 0 14px;
    border: 0;
    border-left: 1px solid var(--border);
    background: var(--surface);
    color: var(--text-secondary);
    font: inherit;
    font-weight: var(--font-weight-semibold);
    cursor: pointer;
}

.level:first-child {
    border-left: 0;
}

.level[aria-pressed='true'] {
    background: var(--line);
    color: var(--surface);
}

.level:disabled {
    color: var(--muted);
    cursor: not-allowed;
}

.level[aria-pressed='true']:disabled {
    background: var(--border);
    color: var(--text);
}

.group {
    display: flex;
    flex-direction: column;
    gap: 2px;
    margin: 0;
    padding: 0;
    border: 0;
}

.action.grey {
    color: var(--text-secondary);
}

.action .note {
    display: block;
}

.choices {
    display: flex;
    flex-direction: column;
    margin: 0;
    padding: 0 0 0 28px;
    list-style: none;
}

.choices.nested {
    padding-left: 28px;
}

.choices .hint {
    font-size: var(--font-size-sm);
}

.preview-list {
    margin: 0;
    padding-left: 20px;
}

.buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
}

@media (max-width: 767px) {
    .buttons .btn {
        flex: 1 1 100%;
    }
}
</style>
