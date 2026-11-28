<script setup lang="ts">
import type { DeviceListItem } from '../api/devices';
import StatusBadge from './StatusBadge.vue';

/** Ausrüstung eines Standorts, eingerückt unter seiner Zeile („Ausrüstung anzeigen“). */
defineProps<{
    devices: readonly DeviceListItem[];
    /** Ziel je Ausrüstung: Detailseite (Recht `devices`) bzw. Umbuchen. */
    target: 'device-details' | 'device';
    /** Bezeichnung für Bildschirmleser, z. B. „Ausrüstung in HLF 20 / G1“. */
    label: string;
}>();
</script>

<template>
    <ul class="location-devices" :aria-label="label" data-testid="location-devices">
        <li v-for="device in devices" :key="device.id">
            <RouterLink
                class="location-device"
                :to="{ name: target, params: { id: String(device.id) } }"
                :data-testid="`location-device-${String(device.id)}`"
            >
                <span class="device-number">{{ device.identifier }}</span>
                <span class="device-type">{{ device.deviceType.name }}</span>
                <StatusBadge :status="device.status" :label="device.statusLabel" />
            </RouterLink>
        </li>
    </ul>
</template>

<style scoped>
.location-devices {
    margin: 0 0 4px 52px;
    padding: 0;
    list-style: none;
}

.location-device {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 4px 10px;
    min-height: var(--touch-min);
    padding: 4px 8px;
    border-radius: var(--radius-sm);
    color: var(--text);
    text-decoration: none;
}

.location-device:hover {
    background: var(--selected-bg);
}

.location-devices li + li .location-device {
    border-top: 1px solid var(--border-subtle);
}

.device-number {
    min-width: 4em;
    font-weight: var(--font-weight-semibold);
    overflow-wrap: anywhere;
}

.device-type {
    flex: 1 1 8em;
    min-width: 0;
    color: var(--muted);
    font-size: var(--font-size-sm);
    overflow-wrap: break-word;
}
</style>
