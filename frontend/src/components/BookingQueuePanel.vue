<script setup lang="ts">
import { computed } from 'vue';
import { bookingQueue } from '../data';
import { queuedText, type QueuedBooking } from '../data/bookingQueue';
import { eventTimeText } from '../data/changeTime';
import { useNow } from '../runtime/now';

/**
 * Warteschlange der Offline-Buchungen auf „Suchen“ (KONZEPT.md, "Offline-Modus – Entscheidungen",
 * 52–54): je Buchung Uhr (wartet) bzw. Haken (angekommen), Aktionszeit, danach das Ergebnis des
 * Servers; alte Buchungen mit „Trotzdem senden“ und „Verwerfen“, abgelehnte mit „Verwerfen“.
 */
const state = computed(() => bookingQueue.state);
const bookings = computed(() => state.value.bookings);
const waiting = computed(
    () => bookings.value.filter((b) => b.state === 'waiting' || b.state === 'sending').length,
);
const done = computed(() => bookings.value.filter((b) => b.state === 'done').length);
const now = useNow();

function symbol(booking: QueuedBooking): string {
    switch (booking.state) {
        case 'done':
            return '✓';
        case 'failed':
            return '✕';
        case 'stale':
            return '!';
        default:
            return '🕓';
    }
}

function stateLabel(booking: QueuedBooking): string {
    switch (booking.state) {
        case 'done':
            return 'beim Server angekommen';
        case 'sending':
            return 'wird gesendet';
        case 'failed':
            return 'nicht gesendet';
        case 'stale':
            return 'wartet auf Ihre Entscheidung';
        default:
            return 'wartet auf Verbindung';
    }
}

/** Farbe des Ergebnisses: übernommen grün, sonst gelb (die Admins erhalten einen Hinweis). */
function resultClass(booking: QueuedBooking): string {
    const outcome = booking.result?.outcome;
    return outcome === 'applied' || outcome === 'unchanged' ? 'result-ok' : 'result-warn';
}

const heading = computed(() => {
    if (waiting.value === 0) {
        return 'Offline erfasste Buchungen';
    }
    return waiting.value === 1
        ? '1 Buchung wartet auf Verbindung'
        : `${String(waiting.value)} Buchungen warten auf Verbindung`;
});
</script>

<template>
    <section
        v-if="bookings.length > 0"
        class="queue card"
        aria-labelledby="queue-heading"
        data-testid="booking-queue"
    >
        <h2 id="queue-heading" class="queue-heading">{{ heading }}</h2>
        <ul class="queue-list">
            <li
                v-for="booking in bookings"
                :key="booking.clientRef"
                class="queued"
                :class="`queued-${booking.state}`"
                :data-testid="`queued-${booking.state}`"
            >
                <span class="queued-symbol" aria-hidden="true">{{ symbol(booking) }}</span>
                <div class="queued-text">
                    <span class="queued-title">{{ queuedText(booking) }}</span>
                    <span class="hint">
                        {{ stateLabel(booking) }} · erfasst
                        {{ eventTimeText(booking.occurredAt, now) }}
                    </span>
                    <span
                        v-if="booking.result !== undefined"
                        class="queued-result"
                        :class="resultClass(booking)"
                    >
                        {{ booking.result.message }}
                    </span>
                    <span v-if="booking.problem !== undefined" class="queued-problem">
                        {{ booking.problem }}
                    </span>
                    <div
                        v-if="booking.state === 'stale' || booking.state === 'failed'"
                        class="queued-actions"
                    >
                        <button
                            v-if="booking.state === 'stale'"
                            class="btn"
                            type="button"
                            @click="bookingQueue.confirmStale(booking.clientRef)"
                        >
                            Trotzdem senden
                        </button>
                        <button
                            class="btn"
                            type="button"
                            @click="bookingQueue.discard(booking.clientRef)"
                        >
                            Verwerfen
                        </button>
                    </div>
                </div>
            </li>
        </ul>
        <button
            v-if="done > 0"
            class="link-button dismiss"
            type="button"
            @click="bookingQueue.dismissDone()"
        >
            Angekommene ausblenden
        </button>
    </section>
</template>

<style scoped>
.queue {
    gap: 8px;
}

.queue-heading {
    margin: 0;
    font-size: var(--font-size-md);
}

.queue-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin: 0;
    padding: 0;
    list-style: none;
}

.queued {
    display: flex;
    gap: 10px;
    align-items: flex-start;
}

.queued-symbol {
    flex: none;
    width: 24px;
    font-weight: var(--font-weight-bold);
    text-align: center;
}

.queued-done .queued-symbol {
    color: var(--status-ready-fg);
}

.queued-failed .queued-symbol,
.queued-stale .queued-symbol {
    color: var(--status-defective-fg);
}

.queued-text {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}

.queued-title {
    font-weight: var(--font-weight-semibold);
    overflow-wrap: anywhere;
}

.hint {
    color: var(--muted);
    font-size: var(--font-size-sm);
}

.queued-result {
    font-size: var(--font-size-sm);
}

.result-ok {
    color: var(--status-ready-fg);
}

.result-warn {
    color: var(--status-maintenance-fg);
}

.queued-problem {
    color: var(--status-defective-fg);
    font-size: var(--font-size-sm);
    font-weight: var(--font-weight-semibold);
}

.queued-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 4px;
}

.dismiss {
    align-self: flex-start;
    font-size: var(--font-size-sm);
}
</style>
