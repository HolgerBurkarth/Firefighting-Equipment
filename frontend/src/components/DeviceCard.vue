<script setup lang="ts">
import { RouterLink } from 'vue-router';
import type { DeviceSummary } from '../api/devices';
import StatusBadge from './StatusBadge.vue';

/**
 * `changeTime`: letzte Umbuchung/Statusänderung als Text („vor 5 Min.“), nur unter „Zuletzt benutzt“.
 * `link` false: ohne Recht auf die Umbuchen-Ansicht nur eine Karte, kein Link (Schritt 1.7b).
 */
withDefaults(
    defineProps<{
        device: DeviceSummary;
        changeTime?: string | null;
        link?: boolean;
    }>(),
    { changeTime: null, link: true },
);
</script>

<!-- Gerät als Karte (Mockup UserStart); Antippen öffnet das Umbuchen (Entscheidung 15). -->
<template>
    <component
        :is="link ? RouterLink : 'div'"
        v-bind="link ? { to: { name: 'device', params: { id: device.id } } } : {}"
        class="device-card"
        data-testid="device-card"
    >
        <span class="device-identifier">{{ device.identifier }}</span>
        <span class="device-text">
            <span class="device-type">{{ device.deviceType.name }}</span>
            <span class="device-location">{{ device.location.path }}</span>
            <span v-if="changeTime" class="device-time" data-testid="device-change-time">
                <span class="visually-hidden">Zuletzt geändert: </span>{{ changeTime }}
            </span>
            <!-- Zusatzzeile der Seite, z. B. die Prüffrist. -->
            <slot />
        </span>
        <StatusBadge :status="device.status" :label="device.statusLabel" />
    </component>
</template>

<style scoped>
.device-card {
    display: flex;
    align-items: center;
    gap: 12px;
    min-height: 64px;
    padding: 14px;
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    background: var(--surface);
    color: var(--text);
    text-decoration: none;
}

.device-card:hover {
    background: var(--surface-hover);
    border-color: var(--line);
    color: var(--text);
}

.device-identifier {
    min-width: 52px;
    font-size: var(--font-size-xl);
    font-weight: var(--font-weight-bold);
    overflow-wrap: anywhere;
}

.device-text {
    display: flex;
    flex: 1 1 auto;
    flex-direction: column;
    min-width: 0;
}

.device-type {
    font-size: var(--font-size-base);
}

.device-location {
    font-size: var(--font-size-sm);
    color: var(--muted);
}

.device-time {
    font-size: var(--font-size-xs);
    color: var(--muted);
}
</style>
