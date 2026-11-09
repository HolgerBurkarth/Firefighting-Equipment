<script setup lang="ts">
import { computed, onMounted, ref, shallowRef } from 'vue';
import type { ApiError } from '../api/client';
import type { DeviceListItem } from '../api/devices';
import { fetchMaintenance, type MaintenanceStage, type MaintenanceState } from '../api/maintenance';
import { allows } from '../api/permissions';
import { currentPermissions } from '../auth/session';
import ConfirmDialog from '../components/ConfirmDialog.vue';
import ErrorNotice from '../components/ErrorNotice.vue';
import StatusBadge from '../components/StatusBadge.vue';
import { maintenanceBack, maintenanceToWorkshop } from '../data';
import {
    bulkCounts,
    bulkErrorText,
    bulkResultText,
    type BulkAction,
    type BulkRun,
} from '../data/deviceBulk';
import { sinceText, transportText } from '../data/maintenance';
import { useNow } from '../runtime/now';
import { copyText } from '../runtime/share';
import { useUnsavedInput } from '../runtime/unsavedInput';

/**
 * Seite „Wartung“ (Phase 2, P2.4; Mockup „Wartung“): die Stufen Sammelstelle und Werkstatt mit der
 * Ausrüstung dort, am längsten wartende zuerst („seit“ aus der letzten Umbuchung bzw. Statusänderung),
 * Sammelaktionen „Zur Werkstatt“ und „Zurück ins Lager“ (der Server gibt das Ziel vor) und eine
 * Transportliste zum Kopieren. Einmalgeräte werden nicht gewartet und lassen sich nicht wählen.
 */
const canChange = computed(() => allows(currentPermissions.value, 'maintenance', 'write'));
const now = useNow();

const state = shallowRef<MaintenanceState | null>(null);
const error = shallowRef<ApiError | null>(null);
const loading = ref(false);

type StageKey = 'collection' | 'workshop';
const picked = ref<Record<StageKey, number[]>>({ collection: [], workshop: [] });

const busy = ref(false);
const progress = ref('');
const resultText = ref<string | null>(null);
const resultOk = ref(true);
const failures = ref<{ id: number; identifier: string; text: string }[]>([]);
const copied = ref<string | null>(null);

const confirmOpen = ref(false);
const pending = ref<StageKey>('collection');

// Während ein Lauf sendet, lädt die App nie neu.
useUnsavedInput(() => busy.value);

function stage(key: StageKey): MaintenanceStage | null {
    return state.value?.[key] ?? null;
}

function selectable(device: DeviceListItem, key: StageKey): boolean {
    return canChange.value && !(key === 'collection' && device.deviceType.singleUse === true);
}

function pickedDevices(key: StageKey): DeviceListItem[] {
    const ids = picked.value[key];
    return (stage(key)?.devices ?? []).filter((device) => ids.includes(device.id));
}

function selectableDevices(key: StageKey): DeviceListItem[] {
    return (stage(key)?.devices ?? []).filter((device) => selectable(device, key));
}

function allPicked(key: StageKey): boolean {
    const available = selectableDevices(key);
    return (
        available.length > 0 && available.every((device) => picked.value[key].includes(device.id))
    );
}

function togglePicked(key: StageKey, id: number): void {
    const list = picked.value[key];
    picked.value = {
        ...picked.value,
        [key]: list.includes(id) ? list.filter((entry) => entry !== id) : [...list, id],
    };
}

function toggleAll(key: StageKey): void {
    picked.value = {
        ...picked.value,
        [key]: allPicked(key) ? [] : selectableDevices(key).map((device) => device.id),
    };
}

async function load(): Promise<void> {
    loading.value = true;
    const result = await fetchMaintenance();
    loading.value = false;
    if (result.ok) {
        state.value = result.data;
        error.value = null;
        // Auswahl nur, was noch in der Stufe steht.
        picked.value = {
            collection: picked.value.collection.filter((id) =>
                result.data.collection.devices.some((device) => device.id === id),
            ),
            workshop: picked.value.workshop.filter((id) =>
                result.data.workshop.devices.some((device) => device.id === id),
            ),
        };
    } else {
        error.value = result.error;
    }
}

onMounted(() => {
    void load();
});

/** Kann die Sammelaktion der Stufe laufen (Ziel vorhanden)? */
const toWorkshopPossible = computed(() => (stage('workshop')?.locations.length ?? 0) > 0);
const backPossible = computed(() => state.value?.defaultStore != null);

const confirmCount = computed(() =>
    pending.value === 'collection' ? picked.value.collection.length : picked.value.workshop.length,
);
const confirmTitle = computed(() => {
    const count = confirmCount.value;
    const what = count === 1 ? '1 Ausrüstung' : `${String(count)} Ausrüstungen`;
    return pending.value === 'collection'
        ? `${what} zur Werkstatt bringen?`
        : `${what} zurück ins Lager bringen?`;
});
const confirmDescription = computed(() => {
    const they = confirmCount.value === 1 ? 'Sie steht' : 'Sie stehen';
    return pending.value === 'collection'
        ? `${they} dann in „${stage('workshop')?.locations[0]?.name ?? 'der Werkstatt'}“ mit dem Status „in Wartung“.`
        : `${they} dann in „${state.value?.defaultStore?.name ?? 'dem Lager'}“ mit dem Status „einsatzbereit“.`;
});

function ask(key: StageKey): void {
    pending.value = key;
    confirmOpen.value = true;
}

async function confirm(): Promise<void> {
    const key = pending.value;
    const devices = pickedDevices(key);
    if (devices.length === 0 || busy.value) {
        return;
    }
    busy.value = true;
    resultText.value = null;
    failures.value = [];
    const run: BulkRun = await (key === 'collection' ? maintenanceToWorkshop : maintenanceBack)(
        devices,
        (done, total) => {
            progress.value = `${String(done)} von ${String(total)} gesendet …`;
        },
    );
    busy.value = false;
    progress.value = '';
    const action: BulkAction = key === 'collection' ? 'to-workshop' : 'back';
    const counts = bulkCounts(run);
    resultText.value = bulkResultText(action, run);
    resultOk.value = counts.errors === 0 && run.error === null && run.unsent.length === 0;
    failures.value = run.results
        .filter((result) => result.outcome === 'error')
        .map((result) => ({
            id: result.id,
            identifier:
                devices.find((device) => device.id === result.id)?.identifier ??
                `#${String(result.id)}`,
            text: bulkErrorText(result),
        }));
    // Übernommene und unveränderte verlassen die Auswahl, Fehler bleiben ausgewählt.
    const done = new Set(
        run.results.filter((result) => result.outcome !== 'error').map((result) => result.id),
    );
    picked.value = { ...picked.value, [key]: picked.value[key].filter((id) => !done.has(id)) };
    await load();
}

async function copyList(key: StageKey): Promise<void> {
    const current = stage(key);
    if (current === null) {
        return;
    }
    const list = picked.value[key].length > 0 ? pickedDevices(key) : [...current.devices];
    const ok = await copyText(transportText(current.label, list));
    copied.value = ok ? key : null;
}
</script>

<template>
    <div class="page-head">
        <h1>Wartung</h1>
    </div>

    <ErrorNotice v-if="error !== null" :error="error">
        <button class="btn" type="button" :disabled="loading" @click="load">
            Erneut versuchen
        </button>
    </ErrorNotice>
    <p v-else-if="state === null" class="hint" role="status">Wird geladen …</p>

    <p
        v-if="resultText !== null"
        class="notice"
        :class="resultOk ? 'notice-success' : 'notice-error'"
        role="status"
        data-testid="maintenance-result"
    >
        {{ resultText }}
    </p>
    <ul v-if="failures.length > 0" class="failures" data-testid="maintenance-failures">
        <li v-for="failure in failures" :key="failure.id">
            <strong>{{ failure.identifier }}</strong
            >: {{ failure.text }}
        </li>
    </ul>
    <p v-if="progress !== ''" class="hint" role="status">{{ progress }}</p>

    <template v-if="state !== null">
        <section
            v-for="key in ['collection', 'workshop'] as const"
            :key="key"
            class="stage"
            :data-testid="`stage-${key}`"
            :aria-labelledby="`stage-${key}-title`"
        >
            <header class="stage-head">
                <h2 :id="`stage-${key}-title`">
                    {{ state[key].label }}
                    <span class="count">{{ state[key].total }}</span>
                </h2>
                <p v-if="state[key].locations.length > 0" class="places">
                    {{ state[key].locations.map((place) => place.name).join(', ') }}
                </p>
            </header>

            <p v-if="state[key].locations.length === 0" class="hint" data-testid="stage-empty">
                Hier ist noch kein Standort eingerichtet. Der Admin vergibt die Aufgabe „{{
                    state[key].label
                }}“ bei den Standorten.
            </p>
            <p v-else-if="state[key].devices.length === 0" class="hint">Nichts in dieser Stufe.</p>
            <template v-else>
                <div class="actions">
                    <button
                        v-if="canChange"
                        class="btn btn-primary"
                        type="button"
                        :data-testid="key === 'collection' ? 'to-workshop' : 'back'"
                        :disabled="
                            busy ||
                            picked[key].length === 0 ||
                            (key === 'collection' ? !toWorkshopPossible : !backPossible)
                        "
                        @click="ask(key)"
                    >
                        {{ key === 'collection' ? 'Zur Werkstatt …' : 'Zurück ins Lager …' }}
                        <template v-if="picked[key].length > 0"
                            >({{ picked[key].length }})</template
                        >
                    </button>
                    <button
                        class="btn"
                        type="button"
                        :data-testid="`copy-${key}`"
                        @click="copyList(key)"
                    >
                        Transportliste kopieren
                    </button>
                    <span v-if="copied === key" class="hint" role="status">Kopiert.</span>
                </div>
                <p v-if="canChange && key === 'collection' && !toWorkshopPossible" class="hint">
                    Es gibt noch keine Werkstatt. Der Admin vergibt die Aufgabe „{{
                        state.workshop.label
                    }}“ bei den Standorten.
                </p>
                <p v-if="canChange && key === 'workshop' && !backPossible" class="hint">
                    Es gibt noch kein Standard-Lager. Der Admin wählt es bei den Standorten.
                </p>

                <ul class="devices">
                    <li v-if="canChange" class="all">
                        <label class="check">
                            <input
                                type="checkbox"
                                :checked="allPicked(key)"
                                :disabled="busy || selectableDevices(key).length === 0"
                                :aria-label="`Alle angezeigten Ausrüstungen in „${state[key].label}“ auswählen`"
                                @change="toggleAll(key)"
                            />
                            Alle auswählen
                        </label>
                    </li>
                    <li
                        v-for="device in state[key].devices"
                        :key="device.id"
                        class="device"
                        data-testid="maintenance-device"
                    >
                        <input
                            v-if="canChange"
                            type="checkbox"
                            :checked="picked[key].includes(device.id)"
                            :disabled="busy || !selectable(device, key)"
                            :aria-label="`${device.identifier} auswählen`"
                            @change="togglePicked(key, device.id)"
                        />
                        <RouterLink
                            class="identifier"
                            :to="{ name: 'device-details', params: { id: device.id } }"
                            >{{ device.identifier }}</RouterLink
                        >
                        <span class="type">
                            {{ device.deviceType.name }}
                            <span
                                v-if="device.deviceType.singleUse === true"
                                class="badge badge-used"
                                >Einmalgerät</span
                            >
                        </span>
                        <StatusBadge :status="device.status" :label="device.statusLabel" />
                        <span class="place">{{ device.location.path }}</span>
                        <span class="since">{{ sinceText(device.stateChangedAt, now) }}</span>
                    </li>
                </ul>
                <p v-if="state[key].total > state[key].devices.length" class="hint">
                    Angezeigt werden die ersten {{ state[key].devices.length }} von
                    {{ state[key].total }}.
                </p>
            </template>
        </section>
    </template>

    <ConfirmDialog
        v-model:open="confirmOpen"
        :title="confirmTitle"
        :description="confirmDescription"
        confirm-label="Bringen"
        @confirm="confirm"
    />
</template>

<style scoped>
.hint {
    margin: 0;
    color: var(--muted);
}

.stage {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-bottom: 24px;
    padding: 16px;
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    background: var(--surface);
}

.stage-head {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 6px 16px;
}

.stage-head h2 {
    margin: 0;
}

.count {
    margin-left: 6px;
    color: var(--muted);
    font-weight: var(--font-weight-regular);
}

.places {
    margin: 0;
    color: var(--muted);
}

.actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
}

.devices {
    display: flex;
    flex-direction: column;
    margin: 0;
    padding: 0;
    list-style: none;
}

.device,
.all {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px 14px;
    min-height: var(--touch-min);
    padding: 6px 4px;
    border-top: 1px solid var(--border-subtle);
}

.device input[type='checkbox'] {
    width: 22px;
    height: 22px;
}

.identifier {
    min-width: 5em;
    font-weight: var(--font-weight-bold);
}

.type {
    min-width: 10em;
}

.place {
    color: var(--muted);
}

.since {
    margin-left: auto;
    color: var(--text-secondary);
}

.failures {
    margin: 0 0 16px;
    padding-left: 20px;
}
</style>
