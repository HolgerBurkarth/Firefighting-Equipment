<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue';
import type { ApiError } from '../api/client';
import { downloadHistoryExport } from '../api/deviceEvents';
import { adminLocationStore, deviceTypeStore } from '../data';
import { localDateKey } from '../data/changeTime';
import { compareNames } from '../data/masterData';
import {
    defaultHistoryExportForm,
    HISTORY_EXPORT_MAX_DAYS,
    HISTORY_EXPORT_PRESETS,
    historyExportParams,
    historyExportProblems,
    presetRange,
    type HistoryExportForm,
} from '../data/notices';
import { saveDownload } from '../runtime/download';
import AppSelect, { type SelectOption } from './AppSelect.vue';
import ErrorNotice from './ErrorNotice.vue';

/**
 * Export des Verlaufs (Protokoll, Reiter „Verlauf exportieren“; GET device-events/export): alle
 * Einträge der Stadt in einem Zeitraum (höchstens 366 Tage, Standard die letzten 90), wahlweise nur
 * eines Gerätetyps bzw. eines Standorts samt Fächern, als CSV für Excel. Gerätetypen und Standorte
 * erst laden, wenn der Reiter sichtbar ist.
 */
const props = defineProps<{ active: boolean }>();

const id = useId();
const ALL = 'all';
const form = ref<HistoryExportForm>(defaultHistoryExportForm(localDateKey(new Date())));
const busy = ref(false);
const error = ref<ApiError | null>(null);
const saved = ref<string | null>(null);

watch(
    () => props.active,
    (active) => {
        if (active) {
            void deviceTypeStore.refresh('open');
            void adminLocationStore.refresh('open');
        }
    },
    { immediate: true },
);

const problems = computed(() => historyExportProblems(form.value));
const params = computed(() => historyExportParams(form.value));

function update(change: Partial<HistoryExportForm>): void {
    form.value = { ...form.value, ...change };
    error.value = null;
    saved.value = null;
}

function choosePreset(days: number): void {
    update(presetRange(localDateKey(new Date()), days));
}

const presetActive = (days: number): boolean => {
    const range = presetRange(localDateKey(new Date()), days);
    return form.value.from === range.from && form.value.to === range.to;
};

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
    get: () => (form.value.deviceTypeId === null ? ALL : String(form.value.deviceTypeId)),
    set: (value: string) => {
        update({ deviceTypeId: value === ALL ? null : Number(value) });
    },
});

const locationValue = computed({
    get: () => (form.value.locationId === null ? ALL : String(form.value.locationId)),
    set: (value: string) => {
        update({ locationId: value === ALL ? null : Number(value) });
    },
});

async function download(): Promise<void> {
    const request = params.value;
    if (request === null || busy.value) {
        return;
    }
    busy.value = true;
    error.value = null;
    saved.value = null;
    const result = await downloadHistoryExport(request);
    busy.value = false;
    if (!result.ok) {
        error.value = result.error;
        return;
    }
    saveDownload(result.data);
    saved.value = `Die Datei „${result.data.fileName}“ wird heruntergeladen.`;
}
</script>

<template>
    <section class="card export" aria-labelledby="export-heading">
        <h2 id="export-heading">Verlauf exportieren</h2>
        <p class="hint">
            Alle Einträge im Verlauf der Geräte (auch ausgesonderter) als CSV-Datei für Excel:
            Umbuchungen, Statusänderungen, Prüfdaten und Stammdaten mit Zeit, Person und Kommentar.
            Höchstens {{ HISTORY_EXPORT_MAX_DAYS }} Tage auf einmal.
        </p>

        <div class="presets" role="group" aria-label="Zeitraum">
            <button
                v-for="preset in HISTORY_EXPORT_PRESETS"
                :key="preset.days"
                class="btn preset"
                type="button"
                :aria-pressed="presetActive(preset.days)"
                @click="choosePreset(preset.days)"
            >
                {{ preset.label }}
            </button>
        </div>

        <div class="range">
            <label class="field">
                <span class="field-label">Von</span>
                <input
                    class="input"
                    type="date"
                    :value="form.from"
                    :aria-invalid="problems.from !== undefined"
                    :aria-describedby="problems.from !== undefined ? `${id}-from` : undefined"
                    @input="update({ from: ($event.target as HTMLInputElement).value })"
                />
                <p v-if="problems.from !== undefined" :id="`${id}-from`" class="field-error">
                    {{ problems.from }}
                </p>
            </label>
            <label class="field">
                <span class="field-label">Bis (einschließlich)</span>
                <input
                    class="input"
                    type="date"
                    :value="form.to"
                    :aria-invalid="problems.to !== undefined || problems.range !== undefined"
                    :aria-describedby="
                        problems.to !== undefined
                            ? `${id}-to`
                            : problems.range !== undefined
                              ? `${id}-range`
                              : undefined
                    "
                    @input="update({ to: ($event.target as HTMLInputElement).value })"
                />
                <p v-if="problems.to !== undefined" :id="`${id}-to`" class="field-error">
                    {{ problems.to }}
                </p>
            </label>
        </div>
        <p
            v-if="problems.range !== undefined"
            :id="`${id}-range`"
            class="field-error"
            data-testid="range-problem"
        >
            {{ problems.range }}
        </p>

        <div class="filters">
            <AppSelect v-model="typeValue" label="Gerätetyp" :options="typeOptions" />
            <AppSelect
                v-model="locationValue"
                label="Standort (mit Fächern)"
                :options="locationOptions"
            />
        </div>

        <fieldset class="order">
            <legend class="field-label">Reihenfolge</legend>
            <label class="check">
                <input
                    type="radio"
                    :name="`${id}-order`"
                    value="newest"
                    :checked="form.order === 'newest'"
                    @change="update({ order: 'newest' })"
                />
                Neueste zuerst
            </label>
            <label class="check">
                <input
                    type="radio"
                    :name="`${id}-order`"
                    value="oldest"
                    :checked="form.order === 'oldest'"
                    @change="update({ order: 'oldest' })"
                />
                Älteste zuerst
            </label>
        </fieldset>

        <ErrorNotice v-if="error !== null" :error="error" />
        <p
            v-if="saved !== null"
            class="notice notice-success"
            role="status"
            data-testid="export-saved"
        >
            {{ saved }}
        </p>

        <div class="export-actions">
            <button
                class="btn btn-accent"
                type="button"
                :disabled="busy || params === null"
                @click="download"
            >
                {{ busy ? 'Export läuft …' : 'Verlauf herunterladen (CSV)' }}
            </button>
        </div>
    </section>
</template>

<style scoped>
.export {
    gap: 16px;
}

.hint {
    margin: 0;
    color: var(--muted);
}

.presets,
.export-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.preset[aria-pressed='true'] {
    border-color: var(--line);
    background: var(--selected-bg);
}

.range,
.filters {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
}

.order {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 4px 20px;
    margin: 0;
    padding: 0;
    border: 0;
}

.order legend {
    width: 100%;
    margin-bottom: 4px;
    padding: 0;
}

@media (max-width: 767.98px) {
    .range,
    .filters {
        grid-template-columns: minmax(0, 1fr);
    }

    .export-actions .btn {
        flex: 1 1 100%;
    }
}
</style>
