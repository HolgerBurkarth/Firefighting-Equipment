<script setup lang="ts">
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    shallowRef,
    useTemplateRef,
    watch,
} from 'vue';
import { useRoute, useRouter } from 'vue-router';
import type { ApiError } from '../api/client';
import type { DeviceListItem } from '../api/devices';
import BookingNoticeMessage from '../components/BookingNoticeMessage.vue';
import CodeScanner from '../components/CodeScanner.vue';
import DeviceCard from '../components/DeviceCard.vue';
import ErrorNotice from '../components/ErrorNotice.vue';
import { deviceStore, recentDeviceIds } from '../data';
import { takeBookingNotice } from '../data/booking';
import { changeTimeText } from '../data/changeTime';
import { useNow } from '../runtime/now';
import { closeQueryView } from '../router/back';
import {
    findByCode,
    MAX_SEARCH_HITS,
    searchDevices,
    searchTokens,
    trimCode,
    type SearchResult,
} from '../data/deviceSearch';

/** Pause nach dem letzten Tastendruck, bevor im Modus `server` gesucht wird. */
const SERVER_SEARCH_DELAY_MS = 300;

const router = useRouter();
const route = useRoute();
const input = useTemplateRef<HTMLInputElement>('input');

/**
 * Der Kamera-Scanner ist eine Ansicht dieser Seite (`?scannen=1`, wie die Standortwahl beim
 * Umbuchen): Der Zurück-Knopf des Handys schließt nur ihn, ein Update lädt dabei nicht neu.
 */
const SCANNER_QUERY = 'scannen';
const scanButton = useTemplateRef<HTMLButtonElement>('scanButton');
const scannerOpen = computed(() => route.query[SCANNER_QUERY] === '1');

const query = ref('');
/** Nach Enter oder einem Scan ohne eindeutigen Treffer. */
const notFound = ref(false);
/** Fehler beim Nachschlagen eines gescannten Codes (z. B. keine Verbindung). */
const scanError = shallowRef<ApiError | null>(null);
/** Ergebnis der eben gespeicherten Buchung (Entscheidung 22), bis zur nächsten Eingabe. */
const bookingNotice = shallowRef(takeBookingNotice());

const list = computed(() => deviceStore.state);
const hasQuery = computed(() => searchTokens(query.value).length > 0);

// Suche auf dem Server (mehr als MAX_LOCAL_DEVICES Geräte): verzögert, ältere Antworten verworfen.
const serverResult = shallowRef<SearchResult | null>(null);
const serverError = shallowRef<ApiError | null>(null);
const serverBusy = ref(false);
let serverTimer: ReturnType<typeof setTimeout> | undefined;
let serverSequence = 0;

async function runServerSearch(text: string): Promise<SearchResult | null> {
    const sequence = ++serverSequence;
    serverBusy.value = true;
    const result = await deviceStore.searchServer(text, MAX_SEARCH_HITS);
    if (sequence !== serverSequence) {
        return null;
    }
    serverBusy.value = false;
    serverResult.value = result.ok ? result.data : null;
    serverError.value = result.ok ? null : result.error;
    return serverResult.value;
}

watch([query, () => list.value.mode], ([text, mode]) => {
    clearTimeout(serverTimer);
    if (mode !== 'server' || searchTokens(text).length === 0) {
        serverSequence += 1;
        serverBusy.value = false;
        serverResult.value = null;
        serverError.value = null;
        return;
    }
    serverTimer = setTimeout(() => void runServerSearch(text), SERVER_SEARCH_DELAY_MS);
});

const result = computed<SearchResult | null>(() => {
    if (!hasQuery.value) {
        return null;
    }
    if (list.value.mode === 'local') {
        return searchDevices(list.value.devices, query.value);
    }
    return list.value.mode === 'server' ? serverResult.value : null;
});

// „Zuletzt benutzt“: gespeicherte IDs, Angaben immer aus der aktuellen Geräteliste.
const recentIds = ref<number[]>([]);
const serverRecent = shallowRef<readonly DeviceListItem[]>([]);
const recent = computed<readonly DeviceListItem[]>(() => {
    if (list.value.mode === 'server') {
        return serverRecent.value;
    }
    return recentIds.value
        .map((id) => list.value.devices.find((device) => device.id === id))
        .filter((device): device is DeviceListItem => device !== undefined);
});
async function loadServerRecent(): Promise<void> {
    serverRecent.value = await deviceStore.findMany(recentIds.value);
}
watch(
    () => list.value.mode,
    (mode) => {
        if (mode === 'server') {
            void loadServerRecent();
        }
    },
);

// Zeitpunkt der letzten Änderung (Umbuchung/Status) in der Ortszeit des Handys.
const now = useNow();

function changeTime(device: DeviceListItem): string | null {
    return device.stateChangedAt === null ? null : changeTimeText(device.stateChangedAt, now.value);
}

onMounted(() => {
    recentIds.value = recentDeviceIds();
    // Zurück auf „Suchen“ (z. B. nach einer Buchung): im Modus `server` frisch vom Server.
    if (list.value.mode === 'server') {
        void loadServerRecent();
    }
    void deviceStore.refresh('open');
});

onBeforeUnmount(() => {
    clearTimeout(serverTimer);
    serverSequence += 1;
});

function onInput(): void {
    notFound.value = false;
    scanError.value = null;
    bookingNotice.value = null;
}

async function openScanner(): Promise<void> {
    await router.push({ query: { ...route.query, [SCANNER_QUERY]: '1' } });
}

function closeScanner(): Promise<void> {
    return closeQueryView(router, SCANNER_QUERY);
}

// Nach dem Schließen zurück zum Knopf (Tastatur, Bildschirmleser).
watch(scannerOpen, async (open) => {
    if (!open) {
        await nextTick();
        scanButton.value?.focus({ preventScroll: true });
    }
});

/**
 * Gescannter Code (KONZEPT.md, Entscheidung 17): Scan-Code genau, dann Kennung → Gerät öffnen (an
 * Stelle des Scanners, Zurück führt also zu „Suchen“). Sonst Scanner zu, der Text steht im
 * Suchfeld, darüber „Kein Gerät mit diesem Code gefunden.“ bzw. der Fehler.
 */
async function onDetected(code: string): Promise<void> {
    const result = await deviceStore.findByCode(code);
    if (result.ok && result.data !== null) {
        await router.replace({ name: 'device', params: { id: result.data.id } });
        return;
    }
    await closeScanner();
    bookingNotice.value = null;
    query.value = trimCode(code);
    notFound.value = result.ok;
    scanError.value = result.ok ? null : result.error;
}

function openDevice(device: DeviceListItem): void {
    void router.push({ name: 'device', params: { id: device.id } });
}

/**
 * Enter (auch ein USB-Barcode-Scanner am PC) wirkt wie ein Scan (Entscheidung 24): Scan-Code bzw.
 * Kennung genau → Gerät öffnen; sonst genau ein Treffer der Suche → öffnen; sonst Hinweis.
 */
async function onSubmit(): Promise<void> {
    const text = query.value;
    if (!hasQuery.value) {
        return;
    }
    let candidates: readonly DeviceListItem[] = [];
    let found: SearchResult | null = null;
    if (list.value.mode === 'local') {
        candidates = list.value.devices;
        found = searchDevices(candidates, text);
    } else if (list.value.mode === 'server') {
        clearTimeout(serverTimer);
        found = await runServerSearch(text);
        candidates = found?.hits ?? [];
    }
    let match = findByCode(candidates, text);
    if (match === null && list.value.mode === 'server' && found !== null) {
        // Scan-Code bzw. Kennung genau, auch wenn sie nicht unter den ersten Treffern von `q` ist.
        const exact = await deviceStore.findByCode(text);
        match = exact.ok ? exact.data : null;
    }
    match ??= found?.total === 1 ? (found.hits[0] ?? null) : null;
    if (match !== null) {
        openDevice(match);
        return;
    }
    if (found !== null && found.total > 1) {
        // Mehrere Treffer: Tastatur schließen, damit die Liste sichtbar ist.
        input.value?.blur();
        return;
    }
    notFound.value = found !== null;
}

function retry(): void {
    void deviceStore.refresh('changed');
}
</script>

<template>
    <h1 class="visually-hidden">Suchen</h1>

    <form class="search-form" role="search" @submit.prevent="onSubmit">
        <label class="search-field">
            <svg
                width="22"
                height="22"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                aria-hidden="true"
            >
                <circle cx="11" cy="11" r="7" />
                <path d="M20 20l-4-4" />
            </svg>
            <span class="visually-hidden">Gerät suchen</span>
            <input
                ref="input"
                v-model="query"
                class="search-input"
                type="search"
                placeholder="Gerät suchen, z. B. M1"
                autocomplete="off"
                autocapitalize="off"
                spellcheck="false"
                enterkeyhint="search"
                @input="onInput"
            />
        </label>
    </form>

    <button
        ref="scanButton"
        class="btn btn-primary scan-button"
        type="button"
        data-testid="scan-button"
        @click="openScanner"
    >
        <svg
            width="22"
            height="22"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            aria-hidden="true"
        >
            <path d="M4 8V5a1 1 0 0 1 1-1h3" />
            <path d="M16 4h3a1 1 0 0 1 1 1v3" />
            <path d="M20 16v3a1 1 0 0 1-1 1h-3" />
            <path d="M8 20H5a1 1 0 0 1-1-1v-3" />
            <path d="M7 12h10" />
        </svg>
        Code scannen
    </button>

    <CodeScanner v-if="scannerOpen" @detected="onDetected" @close="closeScanner" />

    <BookingNoticeMessage v-if="bookingNotice !== null" :notice="bookingNotice" />

    <p v-if="notFound" class="notice notice-info" role="status" data-testid="search-not-found">
        Kein Gerät mit diesem Code gefunden.
    </p>
    <ErrorNotice v-if="scanError !== null" :error="scanError" />

    <!-- Noch nie geladen: Laden bzw. Fehler mit erneutem Versuch. -->
    <template v-if="list.mode === null">
        <p v-if="list.error === null" class="hint" role="status">Geräte werden geladen …</p>
        <ErrorNotice v-else :error="list.error">
            <button class="btn" type="button" :disabled="list.loading" @click="retry">
                Erneut versuchen
            </button>
        </ErrorNotice>
    </template>

    <template v-else>
        <p v-if="list.error !== null" class="hint" role="status" data-testid="refresh-error">
            Die Liste konnte nicht aufgefrischt werden, sie zeigt den letzten Stand.
            <small>({{ list.error.code }})</small>
        </p>

        <section v-if="hasQuery" class="results" aria-label="Treffer">
            <ErrorNotice v-if="serverError !== null" :error="serverError" />
            <p v-else-if="result === null || serverBusy" class="hint" role="status">
                Suche läuft …
            </p>
            <template v-else>
                <p class="hint" role="status" data-testid="search-count">
                    <template v-if="result.total === 0"
                        >Kein Gerät passt zu „{{ query.trim() }}“.</template
                    >
                    <template v-else-if="result.total === 1">1 Treffer</template>
                    <template v-else>{{ result.total }} Treffer</template>
                </p>
                <p
                    v-if="result.total > result.hits.length"
                    class="notice notice-info"
                    data-testid="search-narrow"
                >
                    Mehr als {{ result.hits.length }} Treffer – bitte weiter eingrenzen.
                </p>
                <ul class="device-list">
                    <li v-for="device in result.hits" :key="device.id">
                        <DeviceCard :device="device" />
                    </li>
                </ul>
            </template>
        </section>

        <section v-else class="results" aria-labelledby="recent-heading">
            <h2 id="recent-heading" class="section-heading">Zuletzt benutzt</h2>
            <p v-if="recent.length === 0" class="hint">
                Hier erscheinen die Geräte, die Sie zuletzt geöffnet haben.
            </p>
            <ul v-else class="device-list" data-testid="recent-list">
                <li v-for="device in recent" :key="device.id">
                    <DeviceCard :device="device" :change-time="changeTime(device)" />
                </li>
            </ul>
        </section>
    </template>
</template>

<style scoped>
.search-form {
    display: flex;
    flex-direction: column;
}

.scan-button {
    width: 100%;
}

.search-field {
    display: flex;
    align-items: center;
    gap: 10px;
    min-height: var(--touch-large);
    padding: 0 14px;
    border: 2px solid var(--input-border);
    border-radius: var(--radius-lg);
    background: var(--surface);
    color: var(--muted);
}

.search-field:focus-within {
    border-color: var(--line);
}

.search-input {
    flex: 1 1 auto;
    min-width: 0;
    min-height: 52px;
    border: 0;
    outline: none;
    background: transparent;
    color: var(--text);
    font: inherit;
    font-size: 18px;
}

.results {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.section-heading {
    margin-top: 8px;
    font-size: var(--font-size-lg);
    color: var(--text-secondary);
}

.device-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin: 0;
    padding: 0;
    list-style: none;
}

.hint {
    margin: 0;
    color: var(--muted);
}
</style>
