<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import type { ApiError } from '../api/client';
import { conflictDevice, type DeviceListItem, type SwapResponse } from '../api/devices';
import { fetchStock, type StockState } from '../api/stock';
import { deviceStore, locationStore, swapEquipment } from '../data';
import { MAX_COMMENT_LENGTH, normalizeComment } from '../data/booking';
import {
    SWAP_OUT_STATUSES,
    parseStockBlock,
    stockBlockText,
    swapCandidates,
    type SwapOutStatus,
} from '../data/swap';
import { useUnsavedInput } from '../runtime/unsavedInput';
import FormDialog from './FormDialog.vue';

/**
 * Tauschen (Phase 2, P2.8; Mockup „Handy-Tausch“): Die Ausrüstung geht heraus (Status benutzt, defekt oder in
 * Wartung; benutzt und defekt kommen in die Sammelstelle, wenn die Stadt eine hat), ein einsatzbereiter Ersatz
 * kommt an ihren bisherigen Standort. Beides bucht der Server in einer Transaktion. Nur mit Verbindung.
 * Vorschläge für den Ersatz: einsatzbereite Ausrüstung, zuerst die Typen der verletzten Regel bzw. der gleiche Typ.
 */
const props = defineProps<{
    device: DeviceListItem;
    /** Typen der Regel des Sollbestands, die eine Buchung verletzt hat (leer = der Typ der Ausrüstung). */
    typeIds?: readonly number[];
    returnFocus: HTMLElement | null;
}>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{
    swapped: [response: SwapResponse];
    conflict: [device: DeviceListItem];
}>();

const query = ref('');
const replacementId = ref<number | null>(null);
const outStatus = ref<SwapOutStatus>('used');
const comment = ref('');
const busy = ref(false);
const error = ref<ApiError | null>(null);
const confirmedBelow = ref(false);
const showOthers = ref(false);
/** Sollbestand der Stadt, erst geladen, wenn „Andere Standorte anzeigen“ gewählt wird (für die Warnung). */
const stock = ref<StockState | null>(null);
/** Klicks auf „Tauschen“ in den ersten Zehntelsekunden nach dem Öffnen zählen nicht (Doppeltipp, wie ConfirmDialog). */
const SUBMIT_GUARD_MS = 400;
const armed = ref(false);
let armTimer: ReturnType<typeof setTimeout> | undefined;

async function revealOthers(): Promise<void> {
    showOthers.value = true;
    if (stock.value === null) {
        const result = await fetchStock();
        if (result.ok) {
            stock.value = result.data;
        }
    }
}

onBeforeUnmount(() => {
    clearTimeout(armTimer);
});

watch(open, (value) => {
    clearTimeout(armTimer);
    armed.value = false;
    if (value) {
        armTimer = setTimeout(() => {
            armed.value = true;
        }, SUBMIT_GUARD_MS);
        showOthers.value = false;
        query.value = '';
        replacementId.value = null;
        outStatus.value = 'used';
        comment.value = '';
        error.value = null;
        confirmedBelow.value = false;
    }
});

const candidates = computed(() =>
    swapCandidates(
        deviceStore.state.devices,
        props.device,
        query.value,
        props.typeIds ?? [],
        locationStore.state.locations,
        stock.value,
    ),
);
const replacementWarning = computed(
    () =>
        showOthers.value &&
        candidates.value.others.some(
            (entry) => entry.device.id === replacementId.value && entry.belowMinimum,
        ),
);
const replacement = computed(
    () => deviceStore.state.devices.find((entry) => entry.id === replacementId.value) ?? null,
);
const canSubmit = computed(() => replacement.value !== null && armed.value);

const unsaved = computed(
    () => open.value && (replacementId.value !== null || comment.value.trim() !== ''),
);
useUnsavedInput(() => unsaved.value);

/** Der Server lehnt den Ersatz ab, weil der Sollbestand trotzdem unterschritten bliebe. */
const stockBlock = computed(() => parseStockBlock(error.value?.details ?? null));

async function submit(): Promise<void> {
    const chosen = replacement.value;
    if (busy.value || chosen === null || !armed.value) {
        return;
    }
    busy.value = true;
    error.value = null;
    const text = normalizeComment(comment.value);
    const result = await swapEquipment({
        out: { id: props.device.id, version: props.device.version },
        in: { id: chosen.id, version: chosen.version },
        outStatus: outStatus.value,
        ...(text !== '' && { comment: text }),
        ...(confirmedBelow.value && { confirmedBelowMinimum: true }),
    });
    busy.value = false;
    if (result.ok) {
        open.value = false;
        emit('swapped', result.data);
        return;
    }
    const latest =
        result.error.code === 'version_conflict' ? conflictDevice(result.error.details) : null;
    if (latest !== null) {
        emit('conflict', latest);
    }
    error.value = result.error;
}

function confirmBelow(): void {
    confirmedBelow.value = true;
    void submit();
}
</script>

<template>
    <FormDialog
        v-model:open="open"
        :unsaved="unsaved"
        :title="`${device.identifier} tauschen`"
        description="Die Ausrüstung geht heraus, der Ersatz kommt an ihren bisherigen Standort. Beides wird zusammen gebucht."
        submit-label="Tauschen"
        :busy="busy"
        :can-submit="canSubmit"
        :error="stockBlock === null ? error : null"
        :return-focus="returnFocus"
        @submit="submit"
    >
        <fieldset class="field">
            <legend class="field-label">Ersatz</legend>
            <input
                v-model="query"
                class="input"
                type="search"
                autocomplete="off"
                placeholder="Nummer oder Typ suchen"
                aria-label="Ersatz suchen"
                data-testid="swap-search"
            />
            <p v-if="candidates.suggested.length === 0" class="hint" data-testid="swap-none">
                Keine passende einsatzbereite Ausrüstung im Lager gefunden.
            </p>
            <ul class="swap-list" data-testid="swap-candidates">
                <li v-for="candidate in candidates.suggested" :key="candidate.device.id">
                    <label class="swap-option">
                        <input
                            v-model="replacementId"
                            type="radio"
                            name="swap-replacement"
                            :value="candidate.device.id"
                        />
                        <span class="identifier">{{ candidate.device.identifier }}</span>
                        <span class="type">{{ candidate.device.deviceType.name }}</span>
                        <span class="where">{{ candidate.device.location.path }}</span>
                    </label>
                </li>
            </ul>
            <button
                v-if="!showOthers && candidates.others.length > 0"
                class="btn"
                type="button"
                data-testid="swap-show-others"
                @click="void revealOthers()"
            >
                Andere Standorte anzeigen ({{ candidates.others.length }})
            </button>
            <template v-if="showOthers && candidates.others.length > 0">
                <p class="hint">Von anderen Fahrzeugen: Dort fehlt die Ausrüstung danach.</p>
                <ul class="swap-list" data-testid="swap-others">
                    <li v-for="candidate in candidates.others" :key="candidate.device.id">
                        <label class="swap-option">
                            <input
                                v-model="replacementId"
                                type="radio"
                                name="swap-replacement"
                                :value="candidate.device.id"
                            />
                            <span class="identifier">{{ candidate.device.identifier }}</span>
                            <span class="type">{{ candidate.device.deviceType.name }}</span>
                            <span class="where">{{ candidate.device.location.path }}</span>
                            <span v-if="candidate.belowMinimum" class="warn">
                                (Sollbestand dort unterschritten)
                            </span>
                        </label>
                    </li>
                </ul>
            </template>
            <p v-if="replacementWarning" class="notice notice-error" data-testid="swap-warning">
                Achtung: Am Standort dieser Ausrüstung würde der Sollbestand unterschritten.
            </p>
        </fieldset>

        <fieldset class="field">
            <legend class="field-label">{{ device.identifier }} ist danach</legend>
            <label v-for="entry in SWAP_OUT_STATUSES" :key="entry.value" class="swap-status">
                <input
                    v-model="outStatus"
                    type="radio"
                    name="swap-out-status"
                    :value="entry.value"
                />
                {{ entry.label }}
            </label>
        </fieldset>

        <label class="field">
            <span class="field-label">Kommentar (optional)</span>
            <input
                v-model="comment"
                class="input"
                type="text"
                autocomplete="off"
                :maxlength="MAX_COMMENT_LENGTH"
            />
        </label>

        <div
            v-if="stockBlock !== null"
            class="notice notice-error"
            role="alert"
            data-testid="swap-stock"
        >
            <p>{{ stockBlockText(stockBlock) }}</p>
            <button
                v-if="stockBlock.canOverride"
                class="btn"
                type="button"
                data-testid="swap-override"
                :disabled="busy"
                @click="confirmBelow"
            >
                Trotzdem tauschen
            </button>
        </div>
    </FormDialog>
</template>

<style scoped>
.swap-list {
    list-style: none;
    margin: var(--space-2, 8px) 0 0;
    padding: 0;
    max-height: 40vh;
    overflow-y: auto;
}

.swap-option,
.swap-status {
    display: flex;
    align-items: center;
    gap: var(--space-2, 8px);
    min-height: var(--tap-min, 44px);
}

.where {
    color: var(--ink-muted);
}

.warn {
    color: var(--status-defective-fg);
}
</style>
