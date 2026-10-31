<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import type { ApiError } from '../api/client';
import type { Location, LocationKind, SubLocation } from '../api/locations';
import ConfirmDialog from '../components/ConfirmDialog.vue';
import ErrorNotice from '../components/ErrorNotice.vue';
import FormDialog from '../components/FormDialog.vue';
import { KIND_ICONS, SUB_ICON } from '../components/locationIcons';
import { addLocation, adminLocationStore, renameLocation, setLocationActive } from '../data';
import { deviceCountText } from '../data/locationSearch';
import {
    filterLocationTree,
    LOCATION_NAME_MAX_LENGTH,
    lockBlocker,
    lockedCount,
    nameProblem,
    sameName,
} from '../data/masterData';
import { useUnsavedInput } from '../runtime/unsavedInput';

/**
 * Standorte der Stadt als Baum (KONZEPT.md, "Bereiche des Admins"): Fahrzeuge, Räume und Externe
 * mit ihren Fächern, Symbol je Art und Zahl der Geräte. Anlegen, Fach hinzufügen, Umbenennen,
 * Sperren (nur leer) und Entsperren in kleinen Fenstern; gesperrte nur auf Wunsch. Der Name führt
 * zur Geräteliste mit diesem Standort (samt Fächern).
 */
const state = computed(() => adminLocationStore.state);
const tree = computed(() => state.value.locations ?? []);
const query = ref('');
const showLocked = ref(false);
const groups = computed(() => filterLocationTree(tree.value, query.value, showLocked.value));
const locked = computed(() => lockedCount(tree.value));

const KIND_OPTIONS: readonly { value: LocationKind; label: string }[] = [
    { value: 'vehicle', label: 'Fahrzeug' },
    { value: 'room', label: 'Raum/Lager' },
    { value: 'external', label: 'Extern' },
];

/** Inhalt des Fensters: neuer Standort, neues Fach in `parent` oder Bearbeiten von `target`. */
type Editing =
    | { readonly mode: 'new' }
    | { readonly mode: 'child'; readonly parent: Location }
    | {
          readonly mode: 'edit';
          readonly target: Location | SubLocation;
          readonly parent: Location | null;
      };

const editing = ref<Editing | null>(null);
const dialogOpen = ref(false);
const name = ref('');
const kind = ref<LocationKind>('vehicle');
const touched = ref(false);
const busy = ref(false);
const nameError = ref<string | null>(null);
const dialogError = ref<ApiError | null>(null);
const success = ref<string | null>(null);
const pageError = ref<ApiError | null>(null);

/** Rückfrage vor dem Sperren (nach dem Schließen des Fensters). */
const lockTarget = ref<Location | SubLocation | null>(null);
const confirmOpen = ref(false);

const initialName = computed(() =>
    editing.value?.mode === 'edit' ? editing.value.target.name : '',
);

/** Ungespeicherte Eingaben im Fenster (auch für FormDialog: Klick daneben, Escape). */
const unsaved = computed(() => dialogOpen.value && name.value !== initialName.value);
useUnsavedInput(() => unsaved.value);

/** Namen, mit denen der neue nicht übereinstimmen darf (auch gesperrte; ohne den eigenen). */
const siblingNames = computed((): readonly string[] => {
    const current = editing.value;
    if (current === null) {
        return [];
    }
    if (current.mode === 'new') {
        return tree.value.map((location) => location.name);
    }
    if (current.mode === 'child') {
        return current.parent.children.map((child) => child.name);
    }
    const siblings = current.parent === null ? tree.value : current.parent.children;
    return siblings
        .filter((sibling) => sibling.id !== current.target.id)
        .map((sibling) => sibling.name);
});

const liveProblem = computed(() => {
    const problem = nameProblem(name.value, LOCATION_NAME_MAX_LENGTH);
    if (problem !== null) {
        return problem;
    }
    return siblingNames.value.some((other) => sameName(other, name.value))
        ? 'Diesen Namen gibt es hier schon.'
        : null;
});

const shownNameError = computed(
    () => nameError.value ?? (touched.value ? liveProblem.value : null),
);

const dialogTitle = computed(() => {
    const current = editing.value;
    if (current === null || current.mode === 'new') {
        return 'Neuer Standort';
    }
    if (current.mode === 'child') {
        return `Neues Fach in ${current.parent.name}`;
    }
    return `${current.target.name} bearbeiten`;
});

const dialogDescription = computed(() =>
    editing.value?.mode === 'child'
        ? `Fächer gehören zu „${editing.value.parent.name}“ und haben dieselbe Art.`
        : null,
);

/** Knopf, der das Fenster geöffnet hat (bekommt den Fokus zurück). */
const opener = ref<HTMLElement | null>(null);

function openDialog(next: Editing, event: Event): void {
    opener.value = event.currentTarget instanceof HTMLElement ? event.currentTarget : null;
    editing.value = next;
    name.value = next.mode === 'edit' ? next.target.name : '';
    kind.value = 'vehicle';
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

function editTarget(event: Event, location: Location, child?: SubLocation): void {
    openDialog(
        child === undefined
            ? { mode: 'edit', target: location, parent: null }
            : { mode: 'edit', target: child, parent: location },
        event,
    );
}

function showResult(error: ApiError): void {
    if (error.code === 'name_taken' || error.code === 'invalid_request') {
        nameError.value = error.message;
    } else {
        dialogError.value = error;
    }
}

async function submit(): Promise<void> {
    const current = editing.value;
    touched.value = true;
    nameError.value = null;
    dialogError.value = null;
    if (current === null || liveProblem.value !== null || busy.value) {
        return;
    }
    if (current.mode === 'edit' && name.value === current.target.name) {
        dialogOpen.value = false;
        return;
    }
    busy.value = true;
    const result =
        current.mode === 'new'
            ? await addLocation({ name: name.value, kind: kind.value })
            : current.mode === 'child'
              ? await addLocation({ name: name.value, parentId: current.parent.id })
              : await renameLocation(current.target.id, name.value);
    busy.value = false;
    if (!result.ok) {
        showResult(result.error);
        return;
    }
    success.value =
        current.mode === 'edit'
            ? `„${current.target.name}“ heißt jetzt „${result.data.name}“.`
            : `„${result.data.name}“ wurde angelegt.`;
    dialogOpen.value = false;
}

/** Warum der bearbeitete Standort nicht entsperrt werden kann (übergeordneter gesperrt). */
const unlockBlocker = computed(() => {
    const current = editing.value;
    if (current?.mode !== 'edit' || current.parent === null || current.parent.active) {
        return null;
    }
    return `Zuerst „${current.parent.name}“ entsperren.`;
});

const editedLockBlocker = computed(() =>
    editing.value?.mode === 'edit' ? lockBlocker(editing.value.target) : null,
);

function askLock(): void {
    if (editing.value?.mode !== 'edit') {
        return;
    }
    lockTarget.value = editing.value.target;
    dialogOpen.value = false;
    confirmOpen.value = true;
}

async function changeActive(target: Location | SubLocation, active: boolean): Promise<void> {
    busy.value = true;
    success.value = null;
    pageError.value = null;
    const result = await setLocationActive(target.id, active);
    busy.value = false;
    if (!result.ok) {
        if (active) {
            dialogError.value = result.error;
        } else {
            pageError.value = result.error;
        }
        return;
    }
    success.value = active ? `„${target.name}“ ist wieder frei.` : `„${target.name}“ ist gesperrt.`;
    dialogOpen.value = false;
}

const lockDescription = computed(() =>
    lockTarget.value === null
        ? ''
        : `„${lockTarget.value.name}“ ist danach kein Ziel mehr für Umbuchungen. Der Verlauf bleibt erhalten; Sie können den Standort jederzeit wieder entsperren.`,
);

function confirmLock(): void {
    if (lockTarget.value !== null) {
        void changeActive(lockTarget.value, false);
    }
}

function unlockEdited(): void {
    if (editing.value?.mode === 'edit') {
        void changeActive(editing.value.target, true);
    }
}

function retry(): void {
    void adminLocationStore.refresh('changed');
}

onMounted(() => {
    void adminLocationStore.refresh('open');
});
</script>

<template>
    <div class="page-head">
        <h1>Standorte</h1>
        <button class="btn btn-accent" type="button" @click="openDialog({ mode: 'new' }, $event)">
            Neuer Standort
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
            <span class="visually-hidden">Standorte filtern</span>
            <input v-model="query" type="search" placeholder="Filtern, z. B. HLF oder G1" />
        </label>
        <label v-if="locked > 0" class="check">
            <input v-model="showLocked" type="checkbox" />
            Gesperrte anzeigen ({{ locked }})
        </label>
    </div>

    <ErrorNotice v-if="state.error !== null" :error="state.error">
        <button class="btn retry" type="button" :disabled="state.loading" @click="retry">
            Erneut versuchen
        </button>
    </ErrorNotice>

    <p v-if="state.locations === null && state.error === null" class="hint" role="status">
        Wird geladen …
    </p>
    <p v-else-if="state.locations !== null && tree.length === 0" class="hint">
        Noch keine Standorte. Legen Sie mit „Neuer Standort“ den ersten an, z. B. ein Fahrzeug.
    </p>
    <p v-else-if="state.locations !== null && groups.length === 0" class="hint">
        Kein Standort passt zu „{{ query.trim() }}“.
    </p>

    <ul v-if="groups.length > 0" class="tree" data-testid="location-tree">
        <li
            v-for="group in groups"
            :key="group.location.id"
            class="card group"
            :data-testid="`location-${String(group.location.id)}`"
        >
            <div class="node" :class="{ 'node-locked': !group.location.active }">
                <svg
                    class="node-icon"
                    width="24"
                    height="24"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    aria-hidden="true"
                >
                    <path v-for="d in KIND_ICONS[group.location.kind]" :key="d" :d="d" />
                </svg>
                <span class="node-text">
                    <RouterLink
                        class="node-name"
                        :to="{ name: 'devices', query: { standort: String(group.location.id) } }"
                        :title="`Geräte in ${group.location.name}`"
                        >{{ group.location.name }}</RouterLink
                    >
                    <span class="node-meta">
                        {{ group.location.kindLabel }} ·
                        {{ deviceCountText(group.location.deviceCountTotal) }}
                    </span>
                </span>
                <span v-if="!group.location.active" class="badge badge-retired">gesperrt</span>
                <button
                    v-if="group.location.active"
                    class="icon-btn"
                    type="button"
                    :aria-label="`Fach in ${group.location.name} anlegen`"
                    :title="`Fach in ${group.location.name} anlegen`"
                    @click="openDialog({ mode: 'child', parent: group.location }, $event)"
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
                        <path d="M12 5v14" />
                        <path d="M5 12h14" />
                    </svg>
                </button>
                <button
                    class="icon-btn"
                    type="button"
                    :aria-label="`${group.location.name} bearbeiten`"
                    :title="`${group.location.name} bearbeiten`"
                    @click="editTarget($event, group.location)"
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
            </div>
            <ul v-if="group.children.length > 0" class="children">
                <li
                    v-for="child in group.children"
                    :key="child.id"
                    class="node node-child"
                    :class="{ 'node-locked': !child.active }"
                    :data-testid="`location-${String(child.id)}`"
                >
                    <svg
                        class="node-icon"
                        width="20"
                        height="20"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        aria-hidden="true"
                    >
                        <path v-for="d in SUB_ICON" :key="d" :d="d" />
                    </svg>
                    <span class="node-text">
                        <RouterLink
                            class="node-name"
                            :to="{ name: 'devices', query: { standort: String(child.id) } }"
                            :title="`Geräte in ${group.location.name} / ${child.name}`"
                            >{{ child.name }}</RouterLink
                        >
                        <span class="node-meta">{{ deviceCountText(child.deviceCount) }}</span>
                    </span>
                    <span v-if="!child.active" class="badge badge-retired">gesperrt</span>
                    <button
                        class="icon-btn"
                        type="button"
                        :aria-label="`${group.location.name} / ${child.name} bearbeiten`"
                        :title="`${child.name} bearbeiten`"
                        @click="editTarget($event, group.location, child)"
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
                </li>
            </ul>
        </li>
    </ul>

    <FormDialog
        v-model:open="dialogOpen"
        :unsaved="unsaved"
        :title="dialogTitle"
        :description="dialogDescription"
        :submit-label="editing?.mode === 'edit' ? 'Speichern' : 'Anlegen'"
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
                :aria-describedby="shownNameError !== null ? 'location-name-error' : undefined"
                @input="onNameInput"
            />
            <p v-if="shownNameError !== null" id="location-name-error" class="field-error">
                {{ shownNameError }}
            </p>
        </label>
        <fieldset v-if="editing?.mode === 'new'" class="kinds">
            <legend class="field-label">Art</legend>
            <label v-for="option in KIND_OPTIONS" :key="option.value" class="kind-option">
                <input v-model="kind" type="radio" name="location-kind" :value="option.value" />
                <svg
                    width="20"
                    height="20"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    aria-hidden="true"
                >
                    <path v-for="d in KIND_ICONS[option.value]" :key="d" :d="d" />
                </svg>
                {{ option.label }}
            </label>
        </fieldset>
        <template v-if="editing?.mode === 'edit'" #extra>
            <template v-if="editing.target.active">
                <p class="extra-text">
                    Gesperrte Standorte sind kein Ziel mehr für Umbuchungen; der Verlauf bleibt.
                </p>
                <p v-if="editedLockBlocker !== null" class="extra-text" data-testid="lock-blocker">
                    {{ editedLockBlocker }}
                </p>
                <button
                    class="btn btn-danger"
                    type="button"
                    :disabled="busy || editedLockBlocker !== null"
                    @click="askLock"
                >
                    {{ editing.target.name }} sperren
                </button>
            </template>
            <template v-else>
                <p v-if="unlockBlocker !== null" class="extra-text">{{ unlockBlocker }}</p>
                <button
                    class="btn"
                    type="button"
                    :disabled="busy || unlockBlocker !== null"
                    @click="unlockEdited"
                >
                    {{ editing.target.name }} entsperren
                </button>
            </template>
        </template>
    </FormDialog>

    <ConfirmDialog
        v-model:open="confirmOpen"
        :title="`${lockTarget?.name ?? ''} sperren?`"
        :description="lockDescription"
        confirm-label="Sperren"
        @confirm="confirmLock"
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

.tree,
.children {
    margin: 0;
    padding: 0;
    list-style: none;
}

.tree {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.group {
    gap: 0;
    padding: 8px 12px;
}

.node {
    display: flex;
    align-items: center;
    gap: 12px;
    min-height: var(--touch-large);
    padding: 6px 0;
}

.children {
    margin-left: 18px;
    padding-left: 16px;
    border-left: 2px solid var(--border);
}

.node-child + .node-child {
    border-top: 1px solid var(--border-subtle);
}

.node-icon {
    flex: none;
    color: var(--line);
}

.node-text {
    display: flex;
    flex-grow: 1;
    flex-direction: column;
    min-width: 0;
}

.node-name {
    align-self: flex-start;
    color: var(--text);
    font-weight: var(--font-weight-bold);
    text-decoration: none;
    overflow-wrap: anywhere;
}

/* Klick auf den Namen zeigt die Geräte dort (Geräteliste mit Filter, KONZEPT.md). */
.node-name:hover {
    color: var(--line);
    text-decoration: underline;
}

.node-child .node-name {
    font-weight: var(--font-weight-semibold);
}

.node-meta {
    font-size: var(--font-size-sm);
    color: var(--muted);
}

.node-locked .node-name,
.node-locked .node-icon {
    color: var(--muted);
}

.kinds {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin: 0;
    padding: 0;
    border: 0;
}

.kinds legend {
    margin-bottom: 6px;
    padding: 0;
}

.kind-option {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    min-height: var(--touch-min);
    padding: 0 14px 0 10px;
    border: 1px solid var(--input-border);
    border-radius: var(--radius-sm);
    cursor: pointer;
}

.kind-option:has(input:checked) {
    border-color: var(--line);
    background: var(--selected-bg);
    font-weight: var(--font-weight-semibold);
}

.extra-text {
    margin: 0;
    font-size: var(--font-size-sm);
    color: var(--muted);
}
</style>
