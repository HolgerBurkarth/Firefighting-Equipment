<script setup lang="ts">
import { computed, ref, shallowRef, watch } from 'vue';
import type { ApiError } from '../api/client';
import {
    fetchTenantLocationTasks,
    saveTenantLocationTasks,
    type LocationTaskId,
    type TaskSetting,
} from '../api/locations';
import { phpTrim } from '../data/masterData';
import { useUnsavedInput } from '../runtime/unsavedInput';
import ErrorNotice from './ErrorNotice.vue';

/**
 * Reiter „Abläufe“ im Stadtdetail des Super-Admins (Phase 2, P2.2; Mockup `Standorte-Aufgaben`):
 * schaltet je Aufgabe für Standorte (Lager, Sammelstelle, Werkstatt, Rückgabe, Ausgeliehen an) frei
 * und ändert den Anzeigenamen. Ohne freigeschaltete Aufgaben bleibt alles wie bisher. Abschalten
 * geht nur, solange kein Standort die Aufgabe trägt (der Server lehnt sonst mit 409 ab). Geladen,
 * sobald der Reiter sichtbar ist.
 */
const props = defineProps<{ tenantId: number; active: boolean }>();

/** Höchstlänge wie LocationTaskRepository::LABEL_MAX_LENGTH. */
const LABEL_MAX_LENGTH = 50;

interface Row {
    readonly task: LocationTaskId;
    readonly defaultLabel: string;
    readonly locations: number;
    enabled: boolean;
    label: string;
}

const saved = shallowRef<readonly TaskSetting[] | null>(null);
const rows = ref<Row[]>([]);
const loadError = shallowRef<ApiError | null>(null);
const saveError = shallowRef<ApiError | null>(null);
const success = ref<string | null>(null);
const busy = ref(false);

function toRows(settings: readonly TaskSetting[]): Row[] {
    return settings.map((setting) => ({
        task: setting.task,
        defaultLabel: setting.defaultLabel,
        locations: setting.locations,
        enabled: setting.enabled,
        label: setting.label === setting.defaultLabel ? '' : setting.label,
    }));
}

async function load(): Promise<void> {
    loadError.value = null;
    const result = await fetchTenantLocationTasks(props.tenantId);
    if (!result.ok) {
        loadError.value = result.error;
        return;
    }
    saved.value = result.data;
    rows.value = toRows(result.data);
}

watch(
    () => props.active,
    (active) => {
        if (active && saved.value === null) {
            void load();
        }
    },
    { immediate: true },
);

function labelProblem(row: Row): string | null {
    const label = phpTrim(row.label);
    return Array.from(label).length > LABEL_MAX_LENGTH
        ? `Höchstens ${String(LABEL_MAX_LENGTH)} Zeichen.`
        : null;
}

const hasProblem = computed(() => rows.value.some((row) => labelProblem(row) !== null));

const unsaved = computed(() => {
    const current = saved.value;
    if (current === null) {
        return false;
    }
    const before = toRows(current);
    return rows.value.some((row, index) => {
        const old = before.at(index);
        return old?.enabled !== row.enabled || old.label !== phpTrim(row.label);
    });
});
useUnsavedInput(() => unsaved.value);

async function save(): Promise<void> {
    if (busy.value || hasProblem.value || !unsaved.value) {
        return;
    }
    busy.value = true;
    saveError.value = null;
    success.value = null;
    const result = await saveTenantLocationTasks(
        props.tenantId,
        rows.value.map((row) => {
            const label = phpTrim(row.label);
            return { task: row.task, enabled: row.enabled, label: label === '' ? null : label };
        }),
    );
    busy.value = false;
    if (!result.ok) {
        saveError.value = result.error;
        return;
    }
    saved.value = result.data;
    rows.value = toRows(result.data);
    success.value = 'Die Abläufe sind gespeichert.';
}

function discard(): void {
    if (saved.value !== null) {
        rows.value = toRows(saved.value);
    }
    saveError.value = null;
}
</script>

<template>
    <section class="tasks" aria-labelledby="tasks-title">
        <h2 id="tasks-title" class="tasks-title">Aufgaben für Standorte</h2>
        <p class="tasks-intro">
            Hier schalten Sie frei, welche Aufgaben die Admins dieser Stadt an Standorte vergeben
            können. Ohne freigeschaltete Aufgabe bleibt alles wie bisher. Der Name wird in der
            Oberfläche der Stadt angezeigt.
        </p>
        <ErrorNotice v-if="loadError !== null" :error="loadError" />
        <p v-else-if="saved === null" class="tasks-loading">Wird geladen …</p>
        <form v-else class="tasks-form" @submit.prevent="save">
            <p v-if="success !== null" class="success-notice" role="status">{{ success }}</p>
            <ErrorNotice v-if="saveError !== null" :error="saveError" />
            <ul class="task-list">
                <li v-for="row in rows" :key="row.task" class="task-row" data-testid="task-row">
                    <label class="task-enable">
                        <input v-model="row.enabled" type="checkbox" />
                        <span class="task-name">{{ row.defaultLabel }}</span>
                    </label>
                    <label class="task-label">
                        <span class="field-label">Name in der Oberfläche</span>
                        <input
                            v-model="row.label"
                            class="input"
                            type="text"
                            autocomplete="off"
                            :placeholder="row.defaultLabel"
                            :aria-label="`Name für ${row.defaultLabel}`"
                            :aria-invalid="labelProblem(row) !== null"
                        />
                        <span v-if="labelProblem(row) !== null" class="field-error">
                            {{ labelProblem(row) }}
                        </span>
                    </label>
                    <span class="task-usage">
                        {{
                            row.locations === 0
                                ? 'Von keinem Standort verwendet'
                                : `Von ${String(row.locations)} Standort${row.locations === 1 ? '' : 'en'} verwendet`
                        }}
                    </span>
                </li>
            </ul>
            <div class="task-actions">
                <button
                    class="btn btn-primary"
                    type="submit"
                    :disabled="busy || hasProblem || !unsaved"
                >
                    {{ busy ? 'Wird gespeichert …' : 'Speichern' }}
                </button>
                <button class="btn" type="button" :disabled="busy || !unsaved" @click="discard">
                    Verwerfen
                </button>
            </div>
        </form>
    </section>
</template>

<style scoped>
.tasks {
    display: grid;
    gap: 16px;
    max-width: 48rem;
}

.tasks-title {
    margin: 0;
    font-size: var(--font-size-lg);
}

.tasks-intro,
.tasks-loading {
    margin: 0;
    color: var(--ink-muted);
}

.tasks-form {
    display: grid;
    gap: 16px;
}

.task-list {
    display: grid;
    gap: 12px;
    margin: 0;
    padding: 0;
    list-style: none;
}

.task-row {
    display: grid;
    gap: 8px;
    padding: 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface);
}

.task-enable {
    display: flex;
    gap: 8px;
    align-items: center;
    min-height: var(--touch-min);
    font-weight: var(--font-weight-semibold);
}

.task-label {
    display: grid;
    gap: 4px;
}

.task-usage {
    color: var(--ink-muted);
    font-size: var(--font-size-sm);
}

.task-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
</style>
