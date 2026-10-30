<script setup lang="ts">
import { computed } from 'vue';
import type { DeviceDetail, DeviceListItem } from '../api/devices';
import { germanDate } from '../data/changeTime';
import { scanCodeDisplay } from '../data/eventDisplay';

const props = defineProps<{
    device: DeviceListItem;
    /** Alle Felder (Beschaffungsdatum, Notiz); null, solange nicht geladen. */
    detail: DeviceDetail | null;
}>();

/** Stammdaten; leere Felder als „–“, Trennzeichen eines Scan-Codes sichtbar ([GS]). */
const entries = computed<{ label: string; value: string | null; multiline?: boolean }[]>(() => [
    { label: 'Kennung', value: props.device.identifier },
    {
        label: 'Scan-Code',
        value: props.device.scanCode === null ? null : scanCodeDisplay(props.device.scanCode),
    },
    { label: 'Gerätetyp', value: props.device.deviceType.name },
    { label: 'Seriennummer', value: props.device.serialNumber },
    { label: 'Hersteller', value: props.device.manufacturer },
    { label: 'Modell', value: props.device.model },
    {
        label: 'Beschaffungsdatum',
        value: props.detail?.procuredOn == null ? null : germanDate(props.detail.procuredOn),
    },
    { label: 'Notiz', value: props.detail?.note ?? null, multiline: true },
]);
</script>

<!-- Stammdaten eines Geräts („Details und Verlauf“ und Gerätedetail des Admins). -->
<template>
    <dl class="master-data" data-testid="master-data">
        <div v-for="entry in entries" :key="entry.label" class="master-row">
            <dt>{{ entry.label }}</dt>
            <dd
                :class="{
                    'master-empty': entry.value === null,
                    'master-multiline': entry.multiline,
                }"
            >
                {{ entry.value ?? '–' }}
            </dd>
        </div>
    </dl>
</template>

<style scoped>
.master-data {
    margin: 0;
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    background: var(--surface);
}

.master-row {
    display: flex;
    flex-wrap: wrap;
    gap: 2px 12px;
    padding: 10px 14px;
}

.master-row + .master-row {
    border-top: 1px solid var(--border-subtle);
}

.master-row dt {
    flex: 0 0 9.5em;
    color: var(--muted);
    font-size: var(--font-size-sm);
}

.master-row dd {
    flex: 1 1 12em;
    min-width: 0;
    margin: 0;
    overflow-wrap: anywhere;
}

.master-empty {
    color: var(--muted);
}

.master-multiline {
    white-space: pre-line;
}
</style>
