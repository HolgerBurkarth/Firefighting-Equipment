<script setup lang="ts">
import { computed, onMounted, ref, shallowRef } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import type { ApiError } from '../api/client';
import type { DeviceDetail } from '../api/devices';
import BookingNoticeMessage from '../components/BookingNoticeMessage.vue';
import DeviceCodeDialog from '../components/DeviceCodeDialog.vue';
import DeviceDueDialog from '../components/DeviceDueDialog.vue';
import DeviceEditDialog from '../components/DeviceEditDialog.vue';
import DeviceHistoryList from '../components/DeviceHistoryList.vue';
import DeviceMasterData from '../components/DeviceMasterData.vue';
import DeviceReinstateDialog from '../components/DeviceReinstateDialog.vue';
import DeviceRetireDialog from '../components/DeviceRetireDialog.vue';
import ErrorNotice from '../components/ErrorNotice.vue';
import StatusBadge from '../components/StatusBadge.vue';
import { adminLocationStore, deviceHistory, deviceStore, deviceTypeStore } from '../data';
import { takeBookingNotice } from '../data/booking';
import { germanDate, localDateKey } from '../data/changeTime';
import { dueState } from '../data/deviceList';
import { goBack } from '../router/back';
import { useNow } from '../runtime/now';

/**
 * Gerätedetail für Admins (`geraete/<id>/details`, KONZEPT.md, "Bereiche des Admins" und
 * Entscheidung 33): oben Kennung, Typ, Standort, Status und Prüfdatum, darunter Stammdaten und
 * Verlauf. Aktionen: groß „Umbuchen / Status ändern“ (die Handy-Seite `geraete/<id>`), kleiner
 * „Bearbeiten“, „Prüfdatum ändern“, „Code zuordnen“; rot und abseits „Aussondern“. Ausgesonderte
 * Geräte nimmt der Admin hier wieder auf.
 */
const route = useRoute();
const router = useRouter();
const deviceId = Number(route.params['id']);
const now = useNow();

const device = shallowRef<DeviceDetail | null>(null);
const loadError = shallowRef<ApiError | null>(null);
const loading = ref(false);
const history = deviceHistory(deviceId);
const success = ref<string | null>(null);
/** Nach „Umbuchen / Status ändern“ zurück hierher (Entscheidung 22). */
const bookingNotice = shallowRef(takeBookingNotice());

type DialogName = 'edit' | 'due' | 'code' | 'retire' | 'reinstate';
const openDialog = ref<DialogName | null>(null);
/** Knopf, der das Fenster geöffnet hat (bekommt den Fokus zurück). */
const opener = ref<HTMLElement | null>(null);

function dialogModel(name: DialogName) {
    return computed({
        get: () => openDialog.value === name,
        set: (value: boolean) => {
            openDialog.value = value ? name : openDialog.value === name ? null : openDialog.value;
        },
    });
}

const editOpen = dialogModel('edit');
const dueOpen = dialogModel('due');
const codeOpen = dialogModel('code');
const retireOpen = dialogModel('retire');
const reinstateOpen = dialogModel('reinstate');

function show(name: DialogName, event: Event): void {
    opener.value = event.currentTarget instanceof HTMLElement ? event.currentTarget : null;
    success.value = null;
    bookingNotice.value = null;
    openDialog.value = name;
}

const retired = computed(() => device.value?.status === 'retired');
const due = computed(() =>
    device.value === null
        ? null
        : dueState(device.value.nextInspectionDue, localDateKey(now.value), device.value.status),
);
const DUE_NOTES = { overdue: 'überfällig', soon: 'bald fällig' } as const;

async function load(): Promise<void> {
    loading.value = true;
    const result = await deviceStore.fetchCurrent(deviceId);
    loading.value = false;
    if (result.ok) {
        device.value = result.data;
        loadError.value = null;
    } else {
        loadError.value = result.error;
    }
}

function onSaved(updated: DeviceDetail, message: string): void {
    device.value = updated;
    success.value = message;
    void history.reload();
}

/** 409 version_conflict: oben der aktuelle Stand (das Fenster bleibt offen und sagt es). */
function onConflict(updated: DeviceDetail): void {
    device.value = updated;
    void history.reload();
    // Passt die Aktion nicht mehr zum Stand (inzwischen ausgesondert bzw. wieder aufgenommen),
    // schließt das Fenster; die Seite zeigt dann die passenden Knöpfe.
    const name = openDialog.value;
    if ((name === 'reinstate') !== (updated.status === 'retired')) {
        openDialog.value = null;
    }
}

function back(): Promise<void> {
    return goBack(router, { name: 'devices' });
}

onMounted(() => {
    void deviceTypeStore.refresh('open');
    void adminLocationStore.refresh('open');
    void load();
    void history.reload();
});
</script>

<template>
    <div class="detail-nav">
        <button class="back-link" type="button" @click="back">
            <svg
                width="18"
                height="18"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                aria-hidden="true"
            >
                <path d="M15 6l-6 6 6 6" />
            </svg>
            Zurück
        </button>
    </div>

    <template v-if="device === null">
        <h1>Gerät</h1>
        <p v-if="loadError === null" class="hint" role="status">Gerät wird geladen …</p>
        <div v-else-if="loadError.status === 404" class="notice notice-info" role="alert">
            <p>Dieses Gerät gibt es nicht (mehr).</p>
        </div>
        <ErrorNotice v-else :error="loadError">
            <button class="btn retry" type="button" :disabled="loading" @click="load">
                Erneut versuchen
            </button>
        </ErrorNotice>
    </template>

    <template v-else>
        <div class="device-head">
            <h1>{{ device.identifier }}</h1>
            <span class="device-type">{{ device.deviceType.name }}</span>
        </div>

        <section
            class="card state-card"
            :class="{ 'state-retired': retired }"
            aria-label="Aktueller Stand"
            data-testid="device-state"
        >
            <span class="state-location">{{ device.location.path }}</span>
            <StatusBadge class="state-badge" :status="device.status" :label="device.statusLabel" />
            <span class="state-due" :class="due === null ? null : `due-${due}`">
                Nächste Prüfung:
                {{
                    device.nextInspectionDue === null
                        ? 'nicht festgelegt'
                        : germanDate(device.nextInspectionDue)
                }}<template v-if="due !== null"> · {{ DUE_NOTES[due] }}</template>
            </span>
        </section>

        <p
            v-if="success !== null"
            class="notice notice-success"
            role="status"
            data-testid="success-notice"
        >
            {{ success }}
        </p>
        <BookingNoticeMessage v-if="bookingNotice !== null" :notice="bookingNotice" />

        <div v-if="retired" class="notice notice-info retired-box" data-testid="device-retired">
            <p>
                Dieses Gerät ist ausgesondert: Es fehlt in Suche, Prüffristen und Gerätezahlen und
                lässt sich nicht umbuchen oder bearbeiten.
            </p>
            <button class="btn btn-accent" type="button" @click="show('reinstate', $event)">
                Wieder aufnehmen
            </button>
        </div>

        <div v-else class="actions">
            <RouterLink
                class="btn btn-accent action-main"
                :to="{ name: 'device', params: { id: device.id } }"
            >
                Umbuchen / Status ändern
            </RouterLink>
            <button class="btn" type="button" @click="show('edit', $event)">Bearbeiten</button>
            <button class="btn" type="button" @click="show('due', $event)">Prüfdatum ändern</button>
            <button class="btn" type="button" @click="show('code', $event)">Code zuordnen</button>
        </div>

        <div class="columns">
            <div class="column">
                <section class="section" aria-labelledby="master-heading">
                    <h2 id="master-heading" class="section-heading">Stammdaten</h2>
                    <DeviceMasterData :device="device" :detail="device" />
                </section>

                <section
                    v-if="!retired"
                    class="danger-zone"
                    aria-labelledby="retire-heading"
                    data-testid="danger-zone"
                >
                    <h2 id="retire-heading" class="section-heading">Aussondern</h2>
                    <p class="hint">
                        Für Geräte, die nicht mehr genutzt werden. Der Verlauf bleibt erhalten, das
                        Gerät lässt sich wieder aufnehmen.
                    </p>
                    <button class="btn btn-danger" type="button" @click="show('retire', $event)">
                        Aussondern …
                    </button>
                </section>
            </div>

            <DeviceHistoryList class="column" :history="history" />
        </div>

        <template v-if="!retired">
            <DeviceEditDialog
                v-model:open="editOpen"
                :device="device"
                :return-focus="opener"
                @saved="onSaved"
                @conflict="onConflict"
            />
            <DeviceDueDialog
                v-model:open="dueOpen"
                :device="device"
                :return-focus="opener"
                @saved="onSaved"
                @conflict="onConflict"
            />
            <DeviceCodeDialog
                v-model:open="codeOpen"
                :device="device"
                :return-focus="opener"
                @saved="onSaved"
                @conflict="onConflict"
            />
            <DeviceRetireDialog
                v-model:open="retireOpen"
                :device="device"
                :return-focus="opener"
                @saved="onSaved"
                @conflict="onConflict"
            />
        </template>
        <DeviceReinstateDialog
            v-else
            v-model:open="reinstateOpen"
            :device="device"
            :return-focus="opener"
            @saved="onSaved"
            @conflict="onConflict"
        />
    </template>
</template>

<style scoped>
.device-head {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 4px 14px;
}

.device-head h1 {
    overflow-wrap: anywhere;
}

.device-type {
    font-size: var(--font-size-xl);
    color: var(--text-secondary);
}

.state-card {
    flex-direction: row;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px 18px;
}

.state-location {
    font-size: 22px;
    font-weight: var(--font-weight-bold);
    overflow-wrap: anywhere;
}

/* Status groß und farbig (KONZEPT.md, "Bereiche des Admins"). */
.state-badge {
    padding: 6px 14px;
    font-size: var(--font-size-base);
}

.state-due {
    flex-basis: 100%;
    font-size: var(--font-size-md);
    color: var(--muted);
}

.due-overdue {
    color: var(--status-defective-fg);
    font-weight: var(--font-weight-bold);
}

.due-soon {
    color: var(--status-maintenance-fg);
    font-weight: var(--font-weight-semibold);
}

.state-retired .state-location {
    color: var(--muted);
}

.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.action-main {
    min-height: var(--touch-large);
    padding: 0 22px;
    font-size: var(--font-size-lg);
    font-weight: var(--font-weight-bold);
    text-decoration: none;
}

.retired-box {
    align-items: flex-start;
}

.columns {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 22px;
}

.column {
    display: flex;
    flex-direction: column;
    gap: 22px;
    min-width: 0;
}

.section {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.section-heading {
    font-size: var(--font-size-lg);
    font-weight: var(--font-weight-bold);
}

/* Aussondern rot und abseits (KONZEPT.md, "Listen"). */
.danger-zone {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 8px;
    padding: 16px;
    border: 1px solid var(--danger);
    border-radius: var(--radius-lg);
}

.hint {
    margin: 0;
    color: var(--muted);
}

.retry {
    align-self: flex-start;
}

/* Am PC Stammdaten links, Verlauf rechts. */
@media (min-width: 1200px) {
    .columns {
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
        align-items: start;
    }
}

/* Am Handy füllen die Aktionen die Breite (Daumen). */
@media (max-width: 767.98px) {
    .actions .btn {
        flex: 1 1 40%;
    }

    .action-main {
        flex-basis: 100%;
    }
}
</style>
