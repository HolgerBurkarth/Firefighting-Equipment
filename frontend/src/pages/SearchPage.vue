<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, useTemplateRef, watch } from 'vue';
import { useRouter } from 'vue-router';
import type { ApiError } from '../api/client';
import type { DeviceListItem } from '../api/devices';
import DeviceCard from '../components/DeviceCard.vue';
import ErrorNotice from '../components/ErrorNotice.vue';
import { deviceStore, recentDeviceIds } from '../data';
import {
    findByCode,
    MAX_SEARCH_HITS,
    searchDevices,
    searchTokens,
    type SearchResult,
} from '../data/deviceSearch';

/** Pause nach dem letzten Tastendruck, bevor im Modus `server` gesucht wird. */
const SERVER_SEARCH_DELAY_MS = 300;

const router = useRouter();
const input = useTemplateRef<HTMLInputElement>('input');

const query = ref('');
/** Nach Enter ohne eindeutigen Treffer (wie ein Scan ohne Treffer). */
const notFound = ref(false);

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
watch(
    () => list.value.mode,
    async (mode) => {
        if (mode === 'server') {
            serverRecent.value = await deviceStore.findMany(recentIds.value);
        }
    },
);

onMounted(() => {
    recentIds.value = recentDeviceIds();
    void deviceStore.refresh('open');
});

onBeforeUnmount(() => {
    clearTimeout(serverTimer);
    serverSequence += 1;
});

function onInput(): void {
    notFound.value = false;
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
    const match = findByCode(candidates, text) ?? (found?.total === 1 ? found.hits[0] : null);
    if (match !== null && match !== undefined) {
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

    <p v-if="notFound" class="notice notice-info" role="status" data-testid="search-not-found">
        Kein Gerät mit diesem Code gefunden.
    </p>

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
                    <DeviceCard :device="device" />
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
