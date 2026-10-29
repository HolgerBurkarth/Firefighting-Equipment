<script setup lang="ts">
import { computed, onMounted } from 'vue';
import type { DeviceDetail, DeviceListItem } from '../api/devices';
import { deviceHistory } from '../data';
import { eventTimeText, germanDate, localDateKey } from '../data/changeTime';
import { eventLines, scanCodeDisplay } from '../data/eventDisplay';
import ErrorNotice from './ErrorNotice.vue';
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
const state = computed(() => history.state);
/** Zeitpunkte relativ zu „heute“ beim Öffnen. */
const now = new Date();

/** Heute in der Ortszeit (Vergleich mit dem Prüfdatum). */
const today = localDateKey(now);

const overdue = computed(
    () => props.device.nextInspectionDue !== null && props.device.nextInspectionDue < today,
);

/** Stammdaten; leere Felder als „–“. */
const masterData = computed<{ label: string; value: string | null; multiline?: boolean }[]>(() => [
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

const countText = computed(() => {
    const total = state.value.total;
    return total === null ? '' : total === 1 ? '1 Eintrag' : `${String(total)} Einträge`;
});

onMounted(() => {
    window.scrollTo(0, 0);
    void history.reload();
});

function retry(): void {
    void (state.value.events.length === 0 ? history.reload() : history.loadMore());
}

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
            <dl class="master-data" data-testid="master-data">
                <div v-for="entry in masterData" :key="entry.label" class="master-row">
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
        </section>

        <section class="details-section" aria-labelledby="history-heading">
            <h2 id="history-heading" class="details-heading">
                Verlauf
                <span v-if="countText !== ''" class="details-count">{{ countText }}</span>
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
                    <p v-if="event.comment !== null" class="history-comment">
                        „{{ event.comment }}“
                    </p>
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
    display: flex;
    align-items: baseline;
    gap: 8px;
    font-size: var(--font-size-lg);
    font-weight: var(--font-weight-bold);
}

.details-count {
    font-size: var(--font-size-sm);
    font-weight: var(--font-weight-normal);
    color: var(--muted);
}

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
