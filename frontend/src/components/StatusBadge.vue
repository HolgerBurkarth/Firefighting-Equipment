<script setup lang="ts">
import type { DeviceStatus } from '../api/devices';

defineProps<{ status: DeviceStatus; label: string }>();

/** Statusfarben fest und immer mit Symbol und Wort (KONZEPT.md, "Oberfläche: Grundsätze"). */
const SYMBOLS: Readonly<Record<DeviceStatus, string>> = {
    ready: '✓',
    maintenance: '◐',
    defective: '✕',
    retired: '–',
};
</script>

<template>
    <span class="badge" :class="`badge-${status}`">
        <span aria-hidden="true">{{ SYMBOLS[status] }}</span>
        {{ label }}
    </span>
</template>
