<script setup lang="ts">
import { computed, onMounted } from 'vue';
import type { DeviceDetail, DeviceListItem } from '../api/devices';
import { deviceHistory } from '../data';
import { germanDate, localDateKey } from '../data/changeTime';
import DeviceHistoryList from './DeviceHistoryList.vue';
import DeviceMasterData from './DeviceMasterData.vue';
import FocusHeader from './FocusHeader.vue';
import StatusBadge from './StatusBadge.vue';

const props = defineProps<{
    /** Stand wie in der Umbuchen-Ansicht. */
    device: DeviceListItem;
    /** Alle Felder (Beschaffungsdatum, Notiz); null, solange nicht geladen. */
    detail: DeviceDetail | null;
    /** Kopfzeile, z. B. „M1 · Atemschutzmaske“. */
    title: string;
}>();

const emit = defineEmits<{ close: [] }>();

const history = deviceHistory(props.device.id);

/** Heute in der Ortszeit (Vergleich mit dem Prüfdatum). */
const today = localDateKey(new Date());

const overdue = computed(
    () => props.device.nextInspectionDue !== null && props.device.nextInspectionDue < today,
);

onMounted(() => {
    window.scrollTo(0, 0);
    void history.reload();
});

function close(): Promise<void> {
    emit('close');
    return Promise.resolve();
}
</script>

<!-- „Details und Verlauf“ (KONZEPT.md, "User am Handy – Entscheidungen", 15): Stammdaten und Historie. -->
<template>
    <FocusHeader
        :title="title"
        subtitle="Details und Verlauf"
        back-label="Zurück zum Umbuchen"
        :back-action="close"
        focus-title
    />

    <div class="details-content">
        <section class="details-card" aria-label="Aktueller Stand" data-testid="details-state">
            <span class="details-location">{{ device.location.path }}</span>
            <StatusBadge :status="device.status" :label="device.statusLabel" />
            <span class="details-due" :class="{ 'details-due-overdue': overdue }">
                Nächste Prüfung:
                {{
                    device.nextInspectionDue === null
                        ? 'nicht festgelegt'
                        : germanDate(device.nextInspectionDue)
                }}<template v-if="overdue"> · überfällig</template>
            </span>
        </section>

        <section class="details-section" aria-labelledby="master-heading">
            <h2 id="master-heading" class="details-heading">Stammdaten</h2>
            <DeviceMasterData :device="device" :detail="detail" />
        </section>

        <DeviceHistoryList :history="history" />
    </div>
</template>

<style scoped>
.details-content {
    display: flex;
    flex-direction: column;
    gap: 22px;
    padding: 16px 16px 28px;
}

.details-card {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 8px;
    padding: 14px;
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    background: var(--surface);
}

.details-location {
    font-size: var(--font-size-xl);
    font-weight: var(--font-weight-bold);
    overflow-wrap: anywhere;
}

.details-due {
    font-size: var(--font-size-sm);
    color: var(--muted);
}

.details-due-overdue {
    color: var(--danger);
    font-weight: var(--font-weight-semibold);
}

.details-section {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.details-heading {
    font-size: var(--font-size-lg);
    font-weight: var(--font-weight-bold);
}
</style>
