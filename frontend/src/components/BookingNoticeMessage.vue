<script setup lang="ts">
import type { BookingNotice } from '../data/booking';

defineProps<{ notice: BookingNotice }>();
</script>

<!-- Ergebnis der eben gespeicherten Buchung auf „Suchen“ bzw. „Prüffristen“ (Entscheidung 22). -->
<template>
    <p
        class="notice"
        :class="notice.kind === 'success' ? 'notice-success' : 'notice-info'"
        role="status"
        data-testid="booking-notice"
    >
        <!-- Eine Zeile: .notice stapelt seine Kinder. -->
        <span
            ><span v-if="notice.kind === 'success'" aria-hidden="true">✓ </span
            >{{ notice.text }}</span
        >
    </p>
</template>
