<script setup lang="ts">
import { computed, onMounted, ref, shallowRef, useId } from 'vue';
import type { ApiError } from '../api/client';
import { fetchStock, postStockRules, type StockEntry, type StockState } from '../api/stock';
import { allows } from '../api/permissions';
import { currentPermissions } from '../auth/session';
import { adminLocationStore, deviceTypeStore } from '../data';
import {
    draftsChanged,
    draftsOf,
    ruleText,
    STOCK_MAX_COUNT,
    STOCK_MAX_RULES,
    stockInputs,
    stockProblem,
    outageText,
    stockSummary,
    type RuleDraft,
} from '../data/stock';
import { useUnsavedInput } from '../runtime/unsavedInput';
import AppSelect, { type SelectOption } from './AppSelect.vue';
import ErrorNotice from './ErrorNotice.vue';
import FormDialog from './FormDialog.vue';

/**
 * Sollbestand der Standorte (Phase 2, P2.7; Mockup „Mindestbestand“): je Standort (Ebene 1 oder Fach)
 * Regeln „mindestens N einsatzbereit, Typ A oder B“. Unterschritten = der Standort ist „außer Betrieb“
 * (berechnet, nicht gesetzt). Die Liste zeigt Soll, Ist und Fehlendes; das Fenster ersetzt alle Regeln
 * eines Standorts.
 */
const emit = defineEmits<{ loaded: [state: StockState] }>();
const canWrite = computed(() => allows(currentPermissions.value, 'locations', 'write'));

const state = shallowRef<StockState | null>(null);
const error = shallowRef<ApiError | null>(null);
const success = ref<string | null>(null);
/** Folge der neuen Regel (U4): der Standort gilt jetzt als außer Betrieb. */
const outage = ref<string | null>(null);

async function load(): Promise<void> {
    const result = await fetchStock();
    if (result.ok) {
        state.value = result.data;
        error.value = null;
        emit('loaded', result.data);
    } else {
        error.value = result.error;
    }
}

onMounted(() => {
    void load();
});

// --- Fenster ---
const id = useId();
const open = ref(false);
const locationId = ref('');
const drafts = ref<RuleDraft[]>([]);
const touched = ref(false);
const busy = ref(false);
const dialogError = ref<ApiError | null>(null);
const opener = ref<HTMLElement | null>(null);

const entries = computed(() => state.value?.entries ?? []);
const current = computed(
    (): StockEntry | null =>
        entries.value.find((entry) => String(entry.location.id) === locationId.value) ?? null,
);
const types = computed(() => (deviceTypeStore.state.types ?? []).filter((type) => type.active));

const locationOptions = computed((): SelectOption[] => {
    const options: SelectOption[] = [];
    for (const location of adminLocationStore.state.locations ?? []) {
        if (!location.active) {
            continue;
        }
        options.push({ value: String(location.id), label: location.name });
        for (const child of location.children) {
            if (child.active) {
                options.push({
                    value: String(child.id),
                    label: `${location.name} / ${child.name}`,
                    level: 2,
                });
            }
        }
    }
    return options;
});

const problem = computed(() =>
    locationId.value === '' ? 'Bitte wählen Sie den Standort.' : stockProblem(drafts.value),
);
const shownProblem = computed(() => (touched.value ? problem.value : null));
const unsaved = computed(
    () => open.value && (busy.value || touched.value || draftsChanged(current.value, drafts.value)),
);
useUnsavedInput(() => unsaved.value);

function openDialog(entry: StockEntry | null, event: Event): void {
    opener.value = event.currentTarget instanceof HTMLElement ? event.currentTarget : null;
    locationId.value = entry === null ? '' : String(entry.location.id);
    drafts.value = draftsOf(entry);
    touched.value = false;
    dialogError.value = null;
    success.value = null;
    outage.value = null;
    void deviceTypeStore.refresh('open');
    void adminLocationStore.refresh('open');
    open.value = true;
}

function onLocationChange(): void {
    drafts.value = draftsOf(current.value);
}

function addRule(): void {
    if (drafts.value.length < STOCK_MAX_RULES) {
        drafts.value = [...drafts.value, { count: '1', typeIds: [] }];
    }
}

function removeRule(index: number): void {
    drafts.value = drafts.value.filter((_, position) => position !== index);
}

function setCount(index: number, value: string): void {
    drafts.value = drafts.value.map((draft, position) =>
        position === index ? { ...draft, count: value } : draft,
    );
}

function toggleType(index: number, typeId: number): void {
    drafts.value = drafts.value.map((draft, position) => {
        if (position !== index) {
            return draft;
        }
        const typeIds = draft.typeIds.includes(typeId)
            ? draft.typeIds.filter((entry) => entry !== typeId)
            : [...draft.typeIds, typeId];
        return { ...draft, typeIds };
    });
}

async function submit(): Promise<void> {
    touched.value = true;
    if (problem.value !== null || busy.value) {
        return;
    }
    busy.value = true;
    dialogError.value = null;
    const target = Number(locationId.value);
    const result = await postStockRules(target, stockInputs(drafts.value));
    busy.value = false;
    if (!result.ok) {
        dialogError.value = result.error;
        return;
    }
    const name =
        locationOptions.value.find((option) => option.value === locationId.value)?.label ?? '';
    success.value =
        result.data.stock === null
            ? `Sollbestand von „${name}“ entfernt.`
            : `Sollbestand von „${name}“ gespeichert.`;
    open.value = false;
    await load();
    const saved = state.value?.entries.find((entry) => entry.location.id === target);
    outage.value = saved === undefined ? null : outageText(saved);
}
</script>

<template>
    <section class="stock" aria-labelledby="stock-title" data-testid="stock-panel">
        <div class="stock-head">
            <h2 id="stock-title" tabindex="-1">Sollbestand</h2>
            <button
                v-if="canWrite"
                class="btn"
                type="button"
                data-testid="stock-open"
                @click="openDialog(null, $event)"
            >
                Sollbestand festlegen …
            </button>
        </div>
        <ErrorNotice v-if="error !== null" :error="error" />
        <p
            v-if="success !== null"
            class="notice notice-success"
            role="status"
            data-testid="stock-success"
        >
            {{ success }}
        </p>
        <p
            v-if="success !== null && outage !== null"
            class="notice notice-error"
            role="status"
            data-testid="stock-outage"
        >
            {{ outage }}
        </p>
        <template v-if="state !== null">
            <p class="summary" data-testid="stock-summary">{{ stockSummary(entries) }}</p>
            <ul v-if="entries.length > 0" class="entries">
                <li
                    v-for="entry in entries"
                    :key="entry.location.id"
                    class="entry"
                    data-testid="stock-entry"
                >
                    <div class="entry-head">
                        <strong>{{ entry.location.path }}</strong>
                        <span
                            class="badge"
                            :class="entry.outOfService ? 'badge-defective' : 'badge-ready'"
                            data-testid="stock-chip"
                            >{{ entry.outOfService ? 'außer Betrieb' : 'vollständig' }}</span
                        >
                        <button
                            v-if="canWrite"
                            class="btn btn-small"
                            type="button"
                            :aria-label="`Sollbestand von ${entry.location.path} bearbeiten`"
                            @click="openDialog(entry, $event)"
                        >
                            Bearbeiten
                        </button>
                    </div>
                    <ul class="rules">
                        <li
                            v-for="rule in entry.rules"
                            :key="rule.id"
                            :class="{ missing: rule.missing > 0 }"
                        >
                            {{ ruleText(rule) }}
                        </li>
                    </ul>
                </li>
            </ul>
        </template>
    </section>

    <FormDialog
        v-model:open="open"
        :unsaved="unsaved"
        title="Sollbestand festlegen"
        description="Mindestens so viele einsatzbereite Ausrüstungen müssen am Standort stehen; bei einem Fahrzeug zählen die Fächer mit. Ein Standort mit zu wenig ist außer Betrieb."
        submit-label="Speichern"
        :busy="busy"
        :error="dialogError"
        :can-submit="!touched || problem === null"
        :return-focus="opener"
        @submit="submit"
    >
        <div class="field">
            <AppSelect
                v-model="locationId"
                label="Standort"
                :options="locationOptions"
                placeholder="Bitte wählen"
                @update:model-value="onLocationChange"
            />
        </div>
        <ul class="draft-list" data-testid="stock-drafts">
            <li
                v-for="(draft, index) in drafts"
                :key="index"
                class="draft"
                data-testid="stock-draft"
            >
                <label class="field draft-count">
                    <span class="field-label">Mindestens</span>
                    <input
                        :value="draft.count"
                        class="input"
                        type="number"
                        inputmode="numeric"
                        min="1"
                        :max="STOCK_MAX_COUNT"
                        :disabled="busy"
                        :aria-label="`Regel ${String(index + 1)}: Soll-Anzahl`"
                        @input="setCount(index, ($event.target as HTMLInputElement).value)"
                    />
                </label>
                <fieldset class="draft-types">
                    <legend class="field-label">Ausrüstungstyp</legend>
                    <label v-for="type in types" :key="type.id" class="check">
                        <input
                            type="checkbox"
                            :checked="draft.typeIds.includes(type.id)"
                            :disabled="busy"
                            @change="toggleType(index, type.id)"
                        />
                        {{ type.name }}
                    </label>
                    <p class="field-hint">
                        Mehrere Typen werden zusammen gezählt. Für getrennte Zahlen legen Sie eine
                        zweite Regel an.
                    </p>
                </fieldset>
                <button
                    class="btn btn-small"
                    type="button"
                    :disabled="busy"
                    :aria-label="`Regel ${String(index + 1)} entfernen`"
                    @click="removeRule(index)"
                >
                    Entfernen
                </button>
            </li>
        </ul>
        <button
            class="btn"
            type="button"
            :disabled="busy || drafts.length >= STOCK_MAX_RULES"
            data-testid="stock-add"
            @click="addRule"
        >
            + Regel hinzufügen
        </button>
        <p v-if="drafts.length === 0 && locationId !== ''" :id="`${id}-hint`" class="field-hint">
            Ohne Regeln hat der Standort keinen Sollbestand.
        </p>
        <p v-if="shownProblem !== null" class="field-error" role="alert">{{ shownProblem }}</p>
    </FormDialog>
</template>

<style scoped>
.stock {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-top: 28px;
    padding: 16px;
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    background: var(--surface);
}

.stock-head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}

.stock-head h2 {
    margin: 0;
}

.summary {
    margin: 0;
    font-weight: var(--font-weight-semibold);
}

.entries,
.rules,
.draft-list {
    display: flex;
    flex-direction: column;
    margin: 0;
    padding: 0;
    list-style: none;
}

.entry {
    padding: 8px 0;
    border-top: 1px solid var(--border-subtle);
}

.entry-head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px 12px;
}

.rules {
    padding-left: 4px;
    color: var(--text-secondary);
}

.rules .missing {
    color: var(--status-defective-fg);
    font-weight: var(--font-weight-semibold);
}

.draft {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    gap: 8px 14px;
    padding: 8px 0;
    border-top: 1px solid var(--border-subtle);
}

.draft-count {
    width: 8em;
}

.draft-types {
    display: flex;
    flex: 1 1 14em;
    flex-direction: column;
    gap: 4px;
    min-width: 0;
    border: 0;
    padding: 0;
}

.field-hint {
    margin: 0;
    font-size: var(--font-size-sm);
    color: var(--muted);
}
</style>
