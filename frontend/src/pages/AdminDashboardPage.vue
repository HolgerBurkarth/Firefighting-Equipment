<script setup lang="ts">
import { computed, onMounted } from 'vue';
import ErrorNotice from '../components/ErrorNotice.vue';
import StatusBadge from '../components/StatusBadge.vue';
import { adminOverview } from '../data';
import { eventTimeText } from '../data/changeTime';
import {
    activityDetail,
    inspectionTile,
    notReadyTile,
    openItemsTile,
    readinessTile,
} from '../data/dashboard';
import { useNow } from '../runtime/now';

/**
 * Startseite des Admins (Mockup „Main“): Kacheln Einsatzbereitschaft, nicht einsatzbereit,
 * Prüffristen und offene Hinweise, darunter „Nicht einsatzbereit“ und „Letzte Aktivitäten“
 * (GET device-events). Die Kacheln führen zur gefilterten Geräteliste bzw. zu den Prüffristen.
 */
const now = useNow();
const state = computed(() => adminOverview.state);
const overview = computed(() => state.value.overview);

const tiles = computed(() => {
    const data = overview.value;
    if (data === null) {
        return null;
    }
    return {
        readiness: readinessTile(data.summary),
        notReady: notReadyTile(data.summary),
        inspection: inspectionTile(data.summary),
        open: openItemsTile(data.summary.openNotices ?? 0, data.pendingUsers),
    };
});

const loadedAt = computed(() => {
    const time = state.value.loadedAt;
    if (time === null) {
        return null;
    }
    const date = new Date(time);
    return `${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`;
});

const moreNotReady = computed(() => {
    const list = overview.value?.notReady;
    return list === undefined ? 0 : list.total - list.devices.length;
});

function retry(): void {
    void adminOverview.refresh('changed');
}

onMounted(() => {
    void adminOverview.refresh('open');
});
</script>

<template>
    <div class="dashboard-head">
        <h1>Dashboard</h1>
        <span v-if="loadedAt !== null" class="dashboard-time" data-testid="dashboard-time">
            Stand {{ loadedAt }}
        </span>
    </div>

    <ErrorNotice v-if="state.error !== null" :error="state.error">
        <button class="btn retry" type="button" :disabled="state.loading" @click="retry">
            Erneut versuchen
        </button>
    </ErrorNotice>

    <p v-if="tiles === null && state.error === null" class="hint" role="status">Wird geladen …</p>

    <template v-if="tiles !== null && overview !== null">
        <div class="tiles">
            <RouterLink
                class="tile tile-link tile-ready"
                :to="{ name: 'devices' }"
                data-testid="tile-readiness"
            >
                <span class="tile-label">Einsatzbereitschaft</span>
                <span class="tile-value">{{ tiles.readiness.value }}</span>
                <span class="tile-detail">{{ tiles.readiness.detail }}</span>
                <progress
                    class="tile-bar"
                    max="100"
                    :value="tiles.readiness.percent"
                    aria-label="Anteil einsatzbereit"
                />
            </RouterLink>
            <RouterLink
                class="tile tile-link tile-defective"
                :to="{ name: 'devices', query: { status: 'maintenance,defective' } }"
                data-testid="tile-not-ready"
            >
                <span class="tile-label">Nicht einsatzbereit</span>
                <span class="tile-value tile-value-defective">{{ tiles.notReady.value }}</span>
                <span class="tile-detail">{{ tiles.notReady.detail }}</span>
            </RouterLink>
            <RouterLink
                class="tile tile-link tile-maintenance"
                :to="{ name: 'inspections' }"
                data-testid="tile-inspection"
            >
                <span class="tile-label">Prüffristen</span>
                <span class="tile-value tile-value-maintenance">{{ tiles.inspection.value }}</span>
                <span class="tile-detail">{{ tiles.inspection.detail }}</span>
            </RouterLink>
            <div class="tile tile-accent" data-testid="tile-open">
                <span class="tile-label">Offene Hinweise</span>
                <span class="tile-value">{{ tiles.open.value }}</span>
                <span class="tile-detail">{{ tiles.open.detail }}</span>
            </div>
        </div>

        <div class="lists">
            <section class="card list" aria-labelledby="not-ready-title">
                <h2 id="not-ready-title">Nicht einsatzbereit</h2>
                <p v-if="overview.notReady.devices.length === 0" class="hint">
                    Alle Geräte sind einsatzbereit.
                </p>
                <ul v-else class="rows" data-testid="not-ready-list">
                    <li v-for="device in overview.notReady.devices" :key="device.id">
                        <RouterLink
                            class="row row-link"
                            :to="{ name: 'device-details', params: { id: device.id } }"
                        >
                            <span class="row-identifier">{{ device.identifier }}</span>
                            <span class="row-text">
                                {{ device.deviceType.name }} · {{ device.location.path }}
                            </span>
                            <StatusBadge :status="device.status" :label="device.statusLabel" />
                        </RouterLink>
                    </li>
                </ul>
                <RouterLink
                    v-if="moreNotReady > 0"
                    class="more-link"
                    :to="{ name: 'devices', query: { status: 'maintenance,defective' } }"
                    data-testid="not-ready-more"
                >
                    … und {{ moreNotReady }} weitere
                </RouterLink>
            </section>

            <section class="card list" aria-labelledby="activity-title">
                <h2 id="activity-title">Letzte Aktivitäten</h2>
                <p v-if="overview.activity.length === 0" class="hint">Noch keine Einträge.</p>
                <ul v-else class="rows" data-testid="activity-list">
                    <li
                        v-for="event in overview.activity"
                        :key="event.id"
                        class="row activity"
                        :class="{ 'activity-not-applied': !event.applied }"
                    >
                        <span class="activity-time">{{
                            eventTimeText(event.occurredAt, now)
                        }}</span>
                        <span class="activity-text">
                            <span>
                                <RouterLink
                                    class="activity-device"
                                    :to="{
                                        name: 'device-details',
                                        params: { id: event.device.id },
                                    }"
                                >
                                    {{ event.device.identifier }}</RouterLink
                                >
                                {{ event.typeLabel }} · {{ event.userName }}
                            </span>
                            <span v-if="activityDetail(event, now) !== ''" class="activity-detail">
                                {{ activityDetail(event, now) }}
                            </span>
                            <span v-if="event.comment !== null" class="activity-detail">
                                „{{ event.comment }}“
                            </span>
                        </span>
                    </li>
                </ul>
            </section>
        </div>
    </template>
</template>

<style scoped>
.dashboard-head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.dashboard-time {
    font-size: var(--font-size-md);
    color: var(--muted);
}

.hint {
    margin: 0;
    color: var(--muted);
}

.retry {
    align-self: flex-start;
}

.more-link {
    align-self: flex-start;
    padding: 10px 0;
}

.tiles {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
    gap: 16px;
}

.tile {
    display: flex;
    flex-direction: column;
    gap: 6px;
    padding: 20px;
    border: 1px solid var(--border);
    border-top-width: 6px;
    border-radius: var(--radius-lg);
    background: var(--surface);
    color: var(--text);
    text-decoration: none;
}

.tile-link {
    transition:
        box-shadow 0.12s,
        transform 0.12s;
}

/* Kacheln heben sich am PC an (KONZEPT.md, "Oberfläche: Grundsätze"). */
.tile-link:hover {
    transform: translateY(-2px);
    border-color: var(--border-strong);
    box-shadow: var(--shadow-raised);
    color: var(--text);
}

.tile-ready {
    border-top-color: var(--status-ready-fg);
}

.tile-defective {
    border-top-color: var(--status-defective-fg);
}

.tile-maintenance {
    border-top-color: var(--status-maintenance-fg);
}

.tile-accent {
    border-top-color: var(--line);
}

.tile-label {
    font-size: var(--font-size-md);
    font-weight: var(--font-weight-semibold);
    color: var(--muted);
}

.tile-value {
    font-size: var(--font-size-kpi);
    font-weight: var(--font-weight-bold);
    line-height: 1.1;
}

.tile-value-defective {
    color: var(--status-defective-fg);
}

.tile-value-maintenance {
    color: var(--status-maintenance-fg);
}

.tile-detail {
    font-size: var(--font-size-md);
}

/* Balken ohne style="" (CSP): ein <progress> mit eigenen Farben. */
.tile-bar {
    display: block;
    width: 100%;
    height: 8px;
    margin-top: 6px;
    border: 0;
    border-radius: 4px;
    overflow: hidden;
    appearance: none;
    background: var(--border-subtle);
    color: var(--status-ready-fg);
}

.tile-bar::-webkit-progress-bar {
    background: var(--border-subtle);
}

.tile-bar::-webkit-progress-value {
    background: var(--status-ready-fg);
}

.tile-bar::-moz-progress-bar {
    background: var(--status-ready-fg);
}

.lists {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(340px, 100%), 1fr));
    gap: 16px;
}

.list {
    gap: 4px;
}

.list h2 {
    margin-bottom: 8px;
}

.rows {
    margin: 0;
    padding: 0;
    list-style: none;
}

.rows > li + li {
    border-top: 1px solid var(--border-subtle);
}

.row {
    display: flex;
    align-items: center;
    gap: 12px;
    min-height: var(--touch-min);
    padding: 10px 0;
    color: var(--text);
    text-decoration: none;
}

.row-link:hover {
    background: var(--nav-hover);
    box-shadow: inset 4px 0 0 var(--line);
    color: var(--text);
}

.row-link {
    padding-right: 6px;
    padding-left: 6px;
    border-radius: var(--radius-sm);
}

.row-identifier {
    min-width: 64px;
    font-weight: var(--font-weight-bold);
    overflow-wrap: anywhere;
}

.row-text {
    flex-grow: 1;
    min-width: 0;
    color: var(--text-secondary);
}

.activity {
    align-items: flex-start;
}

.activity-time {
    flex: none;
    min-width: 96px;
    color: var(--muted);
}

.activity-text {
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.activity-device {
    font-weight: var(--font-weight-bold);
}

.activity-detail {
    font-size: var(--font-size-sm);
    color: var(--muted);
    overflow-wrap: anywhere;
}

/* Nicht übernommene Offline-Buchung: abgesetzt wie im Verlauf (Hinweis im Protokoll). */
.activity-not-applied .activity-text {
    color: var(--status-maintenance-fg);
}
</style>
