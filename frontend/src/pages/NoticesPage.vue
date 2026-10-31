<script setup lang="ts">
import { TabsContent, TabsList, TabsRoot, TabsTrigger } from 'reka-ui';
import { computed, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import type { NoticeFilter } from '../api/notices';
import ErrorNotice from '../components/ErrorNotice.vue';
import HistoryExportPanel from '../components/HistoryExportPanel.vue';
import { noticeList } from '../data';
import { eventTimeText } from '../data/changeTime';
import {
    emptyNoticeText,
    NOTICE_FILTERS,
    noticeCountText,
    noticeFilterFromQuery,
    noticeFilterQuery,
    otherStateHeading,
    stateText,
} from '../data/notices';
import { useNow } from '../runtime/now';

/**
 * Protokoll des Admins (`protokoll`, KONZEPT.md, "Hinweise für Admins"): Hinweise zu
 * Offline-Buchungen (überschrieben, nur Historie, nicht übernommen, nach Rückfrage gesendet),
 * neueste zuerst, Filter Offen/Erledigt/Alle (`?status=erledigt|alle`); ein Hinweis öffnet
 * `protokoll/<id>`. Zweiter Reiter „Verlauf exportieren“ (`?ansicht=export`, HistoryExportPanel).
 */
const route = useRoute();
const router = useRouter();
const now = useNow();

type Tab = 'notices' | 'export';
const EXPORT_VIEW = 'export';

const filter = computed<NoticeFilter>(() => noticeFilterFromQuery(route.query['status']));

/** Reiter und Filter in der Adresse (Neuladen und Zurück behalten sie; gleicher Pfad). */
function replaceQuery(tab: Tab, next: NoticeFilter): void {
    void router.replace({
        query: { ...(tab === 'export' && { ansicht: EXPORT_VIEW }), ...noticeFilterQuery(next) },
    });
}

const tab = computed<Tab>({
    get: () => (route.query['ansicht'] === EXPORT_VIEW ? 'export' : 'notices'),
    set: (value) => {
        replaceQuery(value, filter.value);
    },
});

function selectTab(value: string | number): void {
    tab.value = value === 'export' ? 'export' : 'notices';
}

function setFilter(next: NoticeFilter): void {
    replaceQuery('notices', next);
}

const state = computed(() => noticeList.state);
const shownFilter = computed(() => state.value.filter ?? filter.value);

watch(
    filter,
    (value) => {
        void noticeList.show(value);
    },
    { immediate: true },
);

const remaining = computed(() =>
    state.value.total === null ? 0 : state.value.total - state.value.notices.length,
);

function retry(): void {
    void noticeList.refresh('changed');
}
</script>

<template>
    <div class="page-head">
        <h1>Protokoll</h1>
    </div>

    <TabsRoot :model-value="tab" class="tabs-root" @update:model-value="selectTab">
        <TabsList class="tabs" aria-label="Protokoll">
            <TabsTrigger class="tab" value="notices">
                Hinweise
                <span
                    v-if="(state.open ?? 0) > 0"
                    class="badge badge-maintenance"
                    data-testid="open-count"
                    >{{ state.open }}</span
                >
            </TabsTrigger>
            <TabsTrigger class="tab" value="export">Verlauf exportieren</TabsTrigger>
        </TabsList>

        <TabsContent class="tab-panel" value="notices">
            <p class="hint">
                Hinweise entstehen bei Buchungen, die offline erfasst wurden und erst später
                ankamen: wenn sie einen anderen Stand überschrieben haben, nicht übernommen wurden
                oder älter als erlaubt waren. Prüfen Sie den Stand des Geräts und erledigen Sie den
                Hinweis.
            </p>

            <div class="toolbar">
                <div class="filters" role="group" aria-label="Hinweise anzeigen">
                    <button
                        v-for="option in NOTICE_FILTERS"
                        :key="option.filter"
                        class="btn filter-button"
                        type="button"
                        :aria-pressed="filter === option.filter"
                        @click="setFilter(option.filter)"
                    >
                        {{ option.label }}
                        <template v-if="option.filter === 'open' && state.open !== null">
                            ({{ state.open }})
                        </template>
                    </button>
                </div>
                <span
                    v-if="state.total !== null && state.total > 0"
                    class="count"
                    role="status"
                    data-testid="notice-count"
                    >{{ noticeCountText(state.total, shownFilter) }}</span
                >
            </div>

            <ErrorNotice v-if="state.error !== null" :error="state.error">
                <button class="btn retry" type="button" :disabled="state.loading" @click="retry">
                    Erneut versuchen
                </button>
            </ErrorNotice>

            <p v-if="state.total === null && state.error === null" class="hint" role="status">
                Wird geladen …
            </p>
            <p v-else-if="state.total === 0" class="hint" data-testid="no-notices">
                {{ emptyNoticeText(shownFilter) }}
            </p>

            <ul v-if="state.notices.length > 0" class="notice-list" data-testid="notice-list">
                <li
                    v-for="notice in state.notices"
                    :key="notice.id"
                    :data-testid="`notice-${String(notice.id)}`"
                >
                    <RouterLink
                        class="notice-card"
                        :class="{ 'notice-resolved': notice.status === 'resolved' }"
                        :to="{ name: 'notice', params: { id: notice.id } }"
                    >
                        <span class="notice-top">
                            <strong class="notice-kind">{{ notice.kindLabel }}</strong>
                            <span
                                class="badge"
                                :class="
                                    notice.status === 'open' ? 'badge-maintenance' : 'badge-ready'
                                "
                                >{{ notice.statusLabel }}</span
                            >
                        </span>
                        <span class="notice-device">
                            {{ notice.device.identifier }} · {{ notice.device.deviceType }}
                        </span>
                        <span class="notice-line">
                            Buchung:
                            {{ stateText(notice.booking.location, notice.booking.status) }} · von
                            {{ notice.booking.user.name }} ·
                            {{ eventTimeText(notice.booking.occurredAt, now) }}
                        </span>
                        <span class="notice-line notice-other">
                            {{ otherStateHeading(notice) }}:
                            {{ stateText(notice.other.location, notice.other.status) }}
                        </span>
                    </RouterLink>
                </li>
            </ul>

            <div v-if="remaining > 0" class="more">
                <span class="hint">
                    {{ state.notices.length }} von {{ state.total }} angezeigt
                </span>
                <button
                    class="btn"
                    type="button"
                    :disabled="state.loadingMore"
                    @click="noticeList.more()"
                >
                    {{ state.loadingMore ? 'Wird geladen …' : 'Weitere laden' }}
                </button>
            </div>
        </TabsContent>

        <TabsContent class="tab-panel" value="export" :unmount-on-hide="false">
            <HistoryExportPanel :active="tab === 'export'" />
        </TabsContent>
    </TabsRoot>
</template>

<style scoped>
.hint {
    margin: 0;
    color: var(--muted);
}

.retry {
    align-self: flex-start;
}

.filters {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.filter-button[aria-pressed='true'] {
    border-color: var(--line);
    background: var(--selected-bg);
}

.count {
    color: var(--text-secondary);
    font-weight: var(--font-weight-semibold);
}

.notice-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin: 0;
    padding: 0;
    list-style: none;
}

.notice-card {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 14px 16px;
    border: 1px solid var(--border);
    border-left: 4px solid var(--status-maintenance-fg);
    border-radius: var(--radius-lg);
    background: var(--surface);
    color: var(--text);
    text-decoration: none;
}

/* Hover am PC deutlich (KONZEPT.md, "Oberfläche: Grundsätze"). */
.notice-card:hover {
    background: var(--surface-hover);
    color: var(--text);
    text-decoration: none;
}

.notice-resolved {
    border-left-color: var(--border);
}

.notice-top {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px 10px;
}

.notice-kind {
    font-size: var(--font-size-lg);
}

.notice-device {
    font-weight: var(--font-weight-semibold);
    overflow-wrap: anywhere;
}

.notice-line {
    font-size: var(--font-size-md);
    overflow-wrap: anywhere;
}

.notice-other {
    color: var(--muted);
}

.more {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px;
}
</style>
