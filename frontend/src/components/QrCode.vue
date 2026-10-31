<script setup lang="ts">
import { computed } from 'vue';
import { QR_QUIET_ZONE, qrShape } from './qrCode';

/**
 * QR-Code als SVG (dunkel auf hell in beiden Anzeigemodi, damit jede Kamera ihn liest). Die Größe
 * bestimmt die Seite per CSS (Breite des Elements).
 */
const props = defineProps<{
    value: string;
    /** Beschreibung für Bildschirmleser. */
    label: string;
}>();

const shape = computed(() => qrShape(props.value));
const box = computed(() => shape.value.size + 2 * QR_QUIET_ZONE);
</script>

<template>
    <svg
        class="qr"
        role="img"
        :aria-label="label"
        :viewBox="`${String(-QR_QUIET_ZONE)} ${String(-QR_QUIET_ZONE)} ${String(box)} ${String(box)}`"
        shape-rendering="crispEdges"
        data-testid="qr-code"
    >
        <rect class="qr-light" :x="-QR_QUIET_ZONE" :y="-QR_QUIET_ZONE" :width="box" :height="box" />
        <path class="qr-dark" :d="shape.path" />
    </svg>
</template>

<style scoped>
.qr {
    display: block;
    width: 100%;
    height: auto;
}

.qr-light {
    fill: var(--paper);
}

.qr-dark {
    fill: var(--ink);
}
</style>
