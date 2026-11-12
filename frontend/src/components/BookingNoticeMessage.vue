<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { bookingQueue } from '../data';
import type { BookingNotice } from '../data/booking';

const props = defineProps<{ notice: BookingNotice }>();

/**
 * Ergebnis der eben gespeicherten Buchung auf „Suchen“ bzw. „Prüffristen“ (Entscheidung 22). Eine Buchung, die
 * nur in der Warteschlange liegt, zeigt „Gespeichert – wird gesendet …“; sobald sie angekommen ist, steht
 * stattdessen „Angekommen um 14:05“ mit dem Ergebnis (U9). Die Uhrzeit kennt die Anzeige nur, wenn sie das
 * Ankommen miterlebt; sonst steht „Angekommen“ ohne Zeit.
 */
const booking = computed(() =>
    props.notice.clientRef === undefined
        ? undefined
        : bookingQueue.state.bookings.find((entry) => entry.clientRef === props.notice.clientRef),
);
const arrived = ref<Date | 'unknown' | null>(null);
let first = true;
watch(
    () => booking.value?.state,
    (state) => {
        if (state === 'done' && arrived.value === null) {
            arrived.value = first ? 'unknown' : new Date();
        }
        first = false;
    },
    { immediate: true },
);

const shown = computed((): BookingNotice => {
    const entry = booking.value;
    if (arrived.value === null || entry?.state !== 'done') {
        return props.notice;
    }
    const when =
        arrived.value === 'unknown'
            ? ''
            : ` um ${arrived.value.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })}`;
    const label = props.notice.queuedLabel ?? '';
    return entry.result === undefined || entry.result.outcome === 'applied'
        ? { kind: 'success', text: `Angekommen${when}: ${label}` }
        : { kind: 'info', text: `Angekommen${when}: ${entry.result.message}` };
});
</script>

<template>
    <p
        class="notice"
        :class="shown.kind === 'success' ? 'notice-success' : 'notice-info'"
        role="status"
        data-testid="booking-notice"
    >
        <!-- Eine Zeile: .notice stapelt seine Kinder. -->
        <span
            ><span v-if="shown.kind === 'success'" aria-hidden="true">✓ </span
            >{{ shown.text }}</span
        >
    </p>
</template>
