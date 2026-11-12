<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import type { ApiError } from '../api/client';
import { conflictDevice, type DeviceListItem, type SwapResponse } from '../api/devices';
import { fetchStock, type StockState } from '../api/stock';
import { bookingQueue, deviceStore, locationStore, swapEquipment } from '../data';
import { ClientRefKeeper, MAX_COMMENT_LENGTH, normalizeComment } from '../data/booking';
import { actionTime, offlineBookingProblem, pendingDevice, queuedText } from '../data/bookingQueue';
import { offlineData, syncedAtText } from '../data/offline';
import {
    SWAP_OUT_STATUSES,
    parseStockBlock,
    stockBlockText,
    swapCandidates,
    type SwapOutStatus,
} from '../data/swap';
import { germanDate } from '../data/changeTime';
import { connectivity, isOffline } from '../runtime/connectivity';
import { useUnsavedInput } from '../runtime/unsavedInput';
import FormDialog from './FormDialog.vue';

/**
 * Tauschen (Phase 2, P2.8; Mockup „Handy-Tausch“): Die Ausrüstung geht heraus (Status benutzt, defekt oder in
 * Wartung; benutzt und defekt kommen in die Sammelstelle, wenn die Stadt eine hat), ein einsatzbereiter Ersatz
 * kommt an ihren bisherigen Standort. Beides bucht der Server in einer Transaktion. Ohne Verbindung (Streifen "Offline")
 * kommt der Tausch als eine Einheit in die Warteschlange (P2.9), sofern offline gebucht werden darf.
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
    /** Ohne Verbindung in die Warteschlange gelegt: Text der Buchung. */
    queued: [text: string, clientRef: string];
}>();

const query = ref('');
const replacementId = ref<number | null>(null);
/** Ohne Vorauswahl (U2): Der Zustand der Ausrüstung muss bewusst gewählt werden. */
const outStatus = ref<SwapOutStatus | null>(null);
const comment = ref('');
const busy = ref(false);
const error = ref<ApiError | null>(null);
const confirmedBelow = ref(false);
/** Grund, warum offline nicht getauscht werden kann (Kopie zu alt, nicht gespeichert). */
const offlineProblem = ref<string | null>(null);
const refs = new ClientRefKeeper();
const showOthers = ref(false);
/** Sollbestand der Stadt, erst geladen, wenn „Andere Standorte anzeigen“ gewählt wird (für die Warnung). */
const stock = ref<StockState | null>(null);
/** Klicks auf „Tauschen“ in den ersten Zehntelsekunden nach dem Öffnen zählen nicht (Doppeltipp, wie ConfirmDialog). */
const SUBMIT_GUARD_MS = 400;
const armed = ref(false);
/** Die Liste der Ausrüstung wird beim Öffnen vom Server geholt; bis dahin kein (womöglich alter) Stand. */
const listLoading = ref(false);
/** Die Auffrischung ist gescheitert oder es besteht keine Verbindung: die Vorschläge stammen aus der Kopie. */
const listStale = ref(false);
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

/** Solange eine Verbindung besteht, immer der aktuelle Stand vom Server (nie ein alter aus der Offline-Kopie). */
async function reloadList(): Promise<void> {
    if (isOffline.value) {
        listStale.value = true;
        return;
    }
    listLoading.value = true;
    await Promise.all([deviceStore.refresh('changed'), locationStore.refresh('changed')]);
    if (!open.value) {
        return;
    }
    listLoading.value = false;
    listStale.value = deviceStore.state.error !== null || isOffline.value;
}

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
        outStatus.value = null;
        comment.value = '';
        error.value = null;
        offlineProblem.value = null;
        confirmedBelow.value = false;
        stock.value = null;
        listStale.value = false;
        void reloadList();
    } else {
        listLoading.value = false;
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
const canSubmit = computed(
    () =>
        replacement.value !== null && outStatus.value !== null && armed.value && !listLoading.value,
);
const title = computed(() =>
    replacement.value === null
        ? `${props.device.identifier} ersetzen durch …`
        : `${props.device.identifier} ersetzen durch ${replacement.value.identifier}`,
);
/** Was noch fehlt, solange „Tauschen“ gesperrt ist (U2: sonst ist der gesperrte Knopf unerklärt). */
const missingText = computed(() => {
    if (outStatus.value === null && replacement.value === null) {
        return `Bitte wählen Sie den Zustand von ${props.device.identifier} und einen Ersatz.`;
    }
    if (outStatus.value === null) {
        return `Bitte wählen Sie den Zustand von ${props.device.identifier}.`;
    }
    return replacement.value === null ? 'Bitte wählen Sie einen Ersatz.' : null;
});
const summaryText = computed(() => {
    const chosen = replacement.value;
    const status = SWAP_OUT_STATUSES.find((entry) => entry.value === outStatus.value);
    return chosen === null || status === undefined
        ? null
        : `${props.device.identifier}: ${status.label} · ${chosen.identifier} kommt an den Standort.`;
});
const staleText = computed(() =>
    offlineData.syncedAt === null
        ? 'Keine Verbindung – die Vorschläge stammen aus der gespeicherten Kopie.'
        : `Keine Verbindung – die Vorschläge stammen aus dem Stand vom ${syncedAtText(offlineData.syncedAt)}.`,
);

const unsaved = computed(
    () => open.value && (replacementId.value !== null || comment.value.trim() !== ''),
);
useUnsavedInput(() => unsaved.value);

/** Zweite Zeile einer Ersatz-Zeile: Standort und Wartungsdatum als Unterscheidung (U2). */
function detailText(entry: DeviceListItem): string {
    return entry.nextInspectionDue === null
        ? entry.location.path
        : `${entry.location.path} · Wartung ${germanDate(entry.nextInspectionDue)}`;
}

/** Der Server lehnt den Ersatz ab, weil der Sollbestand trotzdem unterschritten bliebe. */
const stockBlock = computed(() => parseStockBlock(error.value?.details ?? null));

async function submit(): Promise<void> {
    const chosen = replacement.value;
    const status = outStatus.value;
    if (busy.value || chosen === null || status === null || !armed.value) {
        return;
    }
    const text = normalizeComment(comment.value);
    offlineProblem.value = null;
    const queueProblem = offlineBookingProblem(
        offlineData.syncedAt,
        bookingQueue.currentLimits,
        new Date(),
    );
    if (isOffline.value && (queueProblem === null || connectivity.browserOffline)) {
        await queueSwap(chosen, text, queueProblem, status);
        return;
    }
    busy.value = true;
    error.value = null;
    const result = await swapEquipment({
        out: { id: props.device.id, version: props.device.version },
        in: { id: chosen.id, version: chosen.version },
        outStatus: status,
        ...(text !== '' && { comment: text }),
        ...(confirmedBelow.value && { confirmedBelowMinimum: true }),
        // Gleicher Inhalt = gleiche Kennung: Ein erneutes Senden wirkt nicht doppelt (wie beim Umbuchen).
        clientRef: refs.refFor(swapContent(chosen, text, status)),
    });
    busy.value = false;
    // Scheitert es an der Verbindung, kommt der Tausch (wenn offline gebucht werden darf) wie beim Umbuchen
    // in die Warteschlange: gleiche Kennung, Aktionszeit des ersten Versuchs.
    if (!result.ok && result.error.code === 'network' && queueProblem === null) {
        await queueSwap(chosen, text, null, status);
        return;
    }
    if (!result.ok && result.error.code === 'network' && offlineData.syncedAt !== null) {
        offlineProblem.value = queueProblem;
    }
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

/** Inhalt des Tauschs für die Kennung (`ClientRefKeeper`): derselbe Inhalt behält Kennung und Zeit. */
function swapContent(chosen: DeviceListItem, text: string, status: SwapOutStatus): object {
    return {
        out: props.device.id,
        outVersion: props.device.version,
        in: chosen.id,
        inVersion: chosen.version,
        outStatus: status,
        comment: text,
    };
}

/** Ohne Verbindung: der Tausch als eine Einheit in die Warteschlange (der Server bucht ihn, wie er ist). */
async function queueSwap(
    chosen: DeviceListItem,
    text: string,
    queueProblem: string | null,
    status: SwapOutStatus,
): Promise<void> {
    if (queueProblem !== null) {
        offlineProblem.value = queueProblem;
        return;
    }
    const attempt = refs.attemptFor(swapContent(chosen, text, status));
    const booking = {
        clientRef: attempt.ref,
        deviceId: props.device.id,
        identifier: props.device.identifier,
        deviceTypeName: props.device.deviceType.name,
        version: props.device.version,
        occurredAt: actionTime(attempt.at),
        swap: {
            inId: chosen.id,
            inVersion: chosen.version,
            inIdentifier: chosen.identifier,
            outStatus: status,
        },
        ...(text !== '' && { comment: text }),
    };
    busy.value = true;
    const added = await bookingQueue.add(booking);
    busy.value = false;
    if (!added) {
        offlineProblem.value = 'Der Tausch ließ sich auf diesem Gerät nicht speichern.';
        return;
    }
    const queued = { ...booking, state: 'waiting' as const };
    deviceStore.upsert(pendingDevice(props.device, queued));
    open.value = false;
    emit('queued', queuedText(queued), queued.clientRef);
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
        :title="title"
        description="Der Ersatz kommt an den bisherigen Standort, beides wird zusammen gebucht."
        submit-label="Tauschen"
        :busy="busy"
        :can-submit="canSubmit"
        :error="stockBlock === null ? error : null"
        :return-focus="returnFocus"
        @submit="submit"
    >
        <div class="field" role="group" :aria-label="`Zustand von ${device.identifier}`">
            <span class="field-label">Zustand von {{ device.identifier }}?</span>
            <div class="swap-states">
                <button
                    v-for="entry in SWAP_OUT_STATUSES"
                    :key="entry.value"
                    class="btn swap-state"
                    :class="{ 'is-chosen': outStatus === entry.value }"
                    type="button"
                    :aria-pressed="outStatus === entry.value"
                    :data-testid="`swap-state-${entry.value}`"
                    @click="outStatus = entry.value"
                >
                    {{ entry.label }}
                </button>
            </div>
        </div>

        <fieldset class="field">
            <legend class="field-label">Ersatz wählen</legend>
            <input
                v-model="query"
                class="input"
                type="search"
                autocomplete="off"
                placeholder="Nummer oder Typ suchen"
                aria-label="Ersatz suchen"
                data-testid="swap-search"
            />
            <p v-if="listLoading" class="hint" role="status" data-testid="swap-loading">
                Wird geladen …
            </p>
            <template v-else>
                <p v-if="listStale" class="hint" data-testid="swap-stale">{{ staleText }}</p>
                <p v-if="candidates.suggested.length === 0" class="hint" data-testid="swap-none">
                    Keine passende einsatzbereite Ausrüstung im Lager gefunden.
                </p>
                <ul class="swap-list" data-testid="swap-candidates">
                    <li v-for="candidate in candidates.suggested" :key="candidate.device.id">
                        <label class="check swap-option">
                            <input
                                v-model="replacementId"
                                type="radio"
                                name="swap-replacement"
                                :value="candidate.device.id"
                            />
                            <span class="swap-text">
                                <span class="identifier"
                                    >{{ candidate.device.identifier }}
                                    <span class="type">{{
                                        candidate.device.deviceType.name
                                    }}</span></span
                                >
                                <span class="where">{{ detailText(candidate.device) }}</span>
                            </span>
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
                            <label class="check swap-option">
                                <input
                                    v-model="replacementId"
                                    type="radio"
                                    name="swap-replacement"
                                    :value="candidate.device.id"
                                />
                                <span class="swap-text">
                                    <span class="identifier"
                                        >{{ candidate.device.identifier }}
                                        <span class="type">{{
                                            candidate.device.deviceType.name
                                        }}</span></span
                                    >
                                    <span class="where">{{ detailText(candidate.device) }}</span>
                                    <span v-if="candidate.belowMinimum" class="warn">
                                        Sollbestand dort unterschritten
                                    </span>
                                </span>
                            </label>
                        </li>
                    </ul>
                </template>
                <p v-if="replacementWarning" class="notice notice-error" data-testid="swap-warning">
                    Achtung: Am Standort dieser Ausrüstung würde der Sollbestand unterschritten.
                </p>
            </template>
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

        <p
            v-if="offlineProblem !== null"
            class="notice notice-error"
            role="alert"
            data-testid="swap-offline-problem"
        >
            {{ offlineProblem }}
        </p>

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
        <template #foot>
            <p v-if="summaryText !== null" class="hint" data-testid="swap-summary">
                {{ summaryText }}
            </p>
            <p v-else-if="missingText !== null" class="hint" data-testid="swap-missing">
                {{ missingText }}
            </p>
        </template>
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

/* Ganze Zeile antippbar (U2/U3), die gewählte hinterlegt; Text bricht um, kein Überstand bei 125 %. */
.swap-option {
    display: flex;
    width: 100%;
    box-sizing: border-box;
    min-height: 56px;
    padding: 6px 10px;
    border: 1px solid transparent;
}

.swap-option:has(input:checked) {
    border-color: var(--accent);
}

.swap-text {
    display: flex;
    flex: 1 1 0;
    flex-direction: column;
    min-width: 0;
    overflow-wrap: anywhere;
}

.identifier {
    font-weight: var(--font-weight-semibold);
}

.type,
.where {
    color: var(--ink-muted);
    font-weight: normal;
}

.warn {
    color: var(--status-defective-fg);
}

.swap-states {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 8px;
}

.swap-state {
    min-height: 56px;
    padding-inline: 6px;
    overflow-wrap: anywhere;
}

.swap-state.is-chosen {
    border-color: var(--accent);
    background: var(--selected-bg);
    font-weight: var(--font-weight-bold);
    box-shadow: inset 0 0 0 2px var(--accent);
}
</style>
