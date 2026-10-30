<script setup lang="ts">
import { computed, useId } from 'vue';
import { eventTimeText } from '../data/changeTime';
import type { DeviceHistory } from '../data/deviceHistory';
import { eventLines } from '../data/eventDisplay';
import ErrorNotice from './ErrorNotice.vue';

const props = defineProps<{
    /** Verlauf des Geräts; laden (`reload`) muss die Seite. */
    history: DeviceHistory;
}>();

const headingId = useId();
const state = computed(() => props.history.state);
/** Zeitpunkte relativ zu „heute“ beim Öffnen. */
const now = new Date();

const countText = computed(() => {
    const total = state.value.total;
    return total === null ? '' : total === 1 ? '1 Eintrag' : `${String(total)} Einträge`;
});

function retry(): void {
    void (state.value.events.length === 0 ? props.history.reload() : props.history.loadMore());
}
</script>

<!-- Verlauf eines Geräts, neueste zuerst, seitenweise („Details und Verlauf“, Gerätedetail). -->
<template>
    <section class="history-section" :aria-labelledby="headingId">
        <h2 :id="headingId" class="history-heading">
            Verlauf
            <span v-if="countText !== ''" class="history-count">{{ countText }}</span>
        </h2>
        <ol class="history" data-testid="history">
            <li
                v-for="event in state.events"
                :key="event.id"
                class="history-entry"
                :class="{ 'history-not-applied': !event.applied }"
                data-testid="history-entry"
            >
                <div class="history-head">
                    <span class="history-type">{{ event.typeLabel }}</span>
                    <time class="history-time" :datetime="event.occurredAt">
                        {{ eventTimeText(event.occurredAt, now) }}
                    </time>
                </div>
                <span class="history-user">von {{ event.userName }}</span>
                <dl v-if="eventLines(event, now).length > 0" class="history-lines">
                    <div
                        v-for="line in eventLines(event, now)"
                        :key="line.label"
                        class="history-line"
                    >
                        <dt>{{ line.label }}:</dt>
                        <dd>{{ line.value }}</dd>
                    </div>
                </dl>
                <p v-if="event.comment !== null" class="history-comment">„{{ event.comment }}“</p>
            </li>
        </ol>
        <p v-if="state.loading" class="hint" role="status" data-testid="history-loading">
            Verlauf wird geladen …
        </p>
        <ErrorNotice v-else-if="state.error !== null" :error="state.error">
            <button class="btn" type="button" @click="retry">Erneut versuchen</button>
        </ErrorNotice>
        <button
            v-else-if="history.hasMore"
            class="btn more"
            type="button"
            data-testid="history-more"
            @click="history.loadMore()"
        >
            Weitere Einträge laden
        </button>
    </section>
</template>

<style scoped>
.history-section {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.history-heading {
    display: flex;
    align-items: baseline;
    gap: 8px;
    font-size: var(--font-size-lg);
    font-weight: var(--font-weight-bold);
}

.history-count {
    font-size: var(--font-size-sm);
    font-weight: var(--font-weight-normal);
    color: var(--muted);
}

.history {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin: 0;
    padding: 0;
    list-style: none;
}

.history-entry {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 12px 14px;
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    background: var(--surface);
}

/* Nicht übernommen: nur Historie, deutlich abgesetzt. */
.history-not-applied {
    border-style: dashed;
    box-shadow: inset 4px 0 0 var(--muted);
}

.history-head {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    justify-content: space-between;
    gap: 2px 12px;
}

.history-type {
    font-weight: var(--font-weight-bold);
}

.history-time,
.history-user {
    font-size: var(--font-size-sm);
    color: var(--muted);
}

.history-lines {
    display: flex;
    flex-direction: column;
    gap: 2px;
    margin: 4px 0 0;
}

.history-line {
    display: flex;
    flex-wrap: wrap;
    gap: 0 6px;
}

.history-line dt {
    color: var(--muted);
}

.history-line dd {
    min-width: 0;
    margin: 0;
    overflow-wrap: anywhere;
    white-space: pre-line;
}

.history-comment {
    margin: 4px 0 0;
    font-style: italic;
    overflow-wrap: anywhere;
}

.more {
    align-self: stretch;
    min-height: var(--touch-min);
}

.hint {
    margin: 0;
    color: var(--muted);
}
</style>
