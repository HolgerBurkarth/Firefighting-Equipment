<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, shallowRef, watch } from 'vue';
import type { ApiError } from '../api/client';
import type { InspectionOverview } from '../api/inspections';
import BookingNoticeMessage from '../components/BookingNoticeMessage.vue';
import DeviceCard from '../components/DeviceCard.vue';
import ErrorNotice from '../components/ErrorNotice.vue';
import { deviceStore, fetchServerInspections } from '../data';
import { takeBookingNotice } from '../data/booking';
import { localDateKey } from '../data/changeTime';
import { inspectionOverview, inspectionText } from '../data/inspections';
import { useNow } from '../runtime/now';

const list = computed(() => deviceStore.state);
const now = useNow();
/** Ergebnis der eben gespeicherten Buchung (Entscheidung 22), bis die Seite verlassen wird. */
const bookingNotice = takeBookingNotice();

// Modus `server` (zu viele Geräte für die lokale Liste): Abschnitte vom Server.
const serverOverview = shallowRef<InspectionOverview | null>(null);
const serverError = shallowRef<ApiError | null>(null);
let serverSequence = 0;

async function loadServer(): Promise<void> {
    const sequence = ++serverSequence;
    const result = await fetchServerInspections();
    if (sequence !== serverSequence) {
        return;
    }
    serverOverview.value = result.ok ? result.data : serverOverview.value;
    serverError.value = result.ok ? null : result.error;
}

// Jede neu geladene Liste (Öffnen, Zurückwechseln, eigene Buchung) fragt im Modus `server` neu.
watch(list, (state) => {
    if (state.mode === 'server' && !state.loading) {
        void loadServer();
    }
});

/** Lokal aus der Geräteliste (heute in der Ortszeit), sonst vom Server. */
const overview = computed<InspectionOverview | null>(() => {
    if (list.value.mode === 'local') {
        return inspectionOverview(list.value.devices, localDateKey(now.value));
    }
    return list.value.mode === 'server' ? serverOverview.value : null;
});
const error = computed(() => (list.value.mode === 'server' ? serverError.value : list.value.error));

onMounted(() => {
    if (list.value.mode === 'server') {
        void loadServer();
    }
    void deviceStore.refresh('open');
});

onBeforeUnmount(() => {
    serverSequence += 1;
});

function retry(): void {
    if (list.value.mode === 'server') {
        void loadServer();
    }
    void deviceStore.refresh('changed');
}

function countText(total: number): string {
    return total === 1 ? '1 Gerät' : `${String(total)} Geräte`;
}
</script>

<!-- Prüffristen am Handy: überfällig und in 30 Tagen fällig; Antippen öffnet das Umbuchen (Entscheidung 25). -->
<template>
    <h1 class="page-heading">Prüffristen</h1>

    <BookingNoticeMessage v-if="bookingNotice !== null" :notice="bookingNotice" />

    <template v-if="overview === null">
        <p v-if="error === null" class="hint" role="status">Prüffristen werden geladen …</p>
        <ErrorNotice v-else :error="error">
            <button class="btn" type="button" :disabled="list.loading" @click="retry">
                Erneut versuchen
            </button>
        </ErrorNotice>
    </template>

    <template v-else>
        <p v-if="error !== null" class="hint" role="status" data-testid="refresh-error">
            Die Liste konnte nicht aufgefrischt werden, sie zeigt den letzten Stand.
            <small>({{ error.code }})</small>
        </p>

        <section class="inspection-section" aria-labelledby="overdue-heading">
            <h2 id="overdue-heading" class="section-heading">
                Überfällig
                <span class="section-count" data-testid="overdue-count">{{
                    countText(overview.overdue.total)
                }}</span>
            </h2>
            <p v-if="overview.overdue.total === 0" class="hint" data-testid="overdue-empty">
                Keine Prüfung überfällig.
            </p>
            <ul v-else class="device-list" data-testid="overdue-list">
                <li v-for="entry in overview.overdue.entries" :key="entry.device.id">
                    <DeviceCard :device="entry.device">
                        <span class="inspection inspection-overdue" data-testid="inspection-due">
                            {{ inspectionText(entry, true) }}
                        </span>
                    </DeviceCard>
                </li>
            </ul>
            <p
                v-if="overview.overdue.total > overview.overdue.entries.length"
                class="hint"
                data-testid="overdue-more"
            >
                Angezeigt werden die ersten {{ overview.overdue.entries.length }}.
            </p>
        </section>

        <section class="inspection-section" aria-labelledby="due-soon-heading">
            <h2 id="due-soon-heading" class="section-heading">
                In den nächsten {{ overview.days }} Tagen fällig
                <span class="section-count" data-testid="due-soon-count">{{
                    countText(overview.dueSoon.total)
                }}</span>
            </h2>
            <p v-if="overview.dueSoon.total === 0" class="hint" data-testid="due-soon-empty">
                In den nächsten {{ overview.days }} Tagen ist keine Prüfung fällig.
            </p>
            <ul v-else class="device-list" data-testid="due-soon-list">
                <li v-for="entry in overview.dueSoon.entries" :key="entry.device.id">
                    <DeviceCard :device="entry.device">
                        <span class="inspection inspection-soon" data-testid="inspection-due">
                            {{ inspectionText(entry, false) }}
                        </span>
                    </DeviceCard>
                </li>
            </ul>
            <p
                v-if="overview.dueSoon.total > overview.dueSoon.entries.length"
                class="hint"
                data-testid="due-soon-more"
            >
                Angezeigt werden die ersten {{ overview.dueSoon.entries.length }}.
            </p>
        </section>
    </template>
</template>

<style scoped>
.page-heading {
    font-size: var(--font-size-xl);
    font-weight: var(--font-weight-bold);
}

.inspection-section {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.section-heading {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 4px 10px;
    font-size: var(--font-size-lg);
    font-weight: var(--font-weight-bold);
}

.section-count {
    font-size: var(--font-size-sm);
    font-weight: var(--font-weight-normal);
    color: var(--muted);
}

.device-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin: 0;
    padding: 0;
    list-style: none;
}

/* Wie im Mockup „AdminHandy“: überfällig rot und fett, bald fällig gelb (Statusfarben). */
.inspection {
    font-size: var(--font-size-xs);
}

.inspection-overdue {
    color: var(--status-defective-fg);
    font-weight: var(--font-weight-bold);
}

.inspection-soon {
    color: var(--status-maintenance-fg);
    font-weight: var(--font-weight-semibold);
}

.hint {
    margin: 0;
    color: var(--muted);
}
</style>
