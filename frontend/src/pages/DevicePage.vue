<script setup lang="ts">
import { computed, nextTick, onMounted, ref, shallowRef, useTemplateRef, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import type { ApiError } from '../api/client';
import {
    BOOKABLE_STATUSES,
    conflictDevice,
    STATUS_LABELS,
    type BookableStatus,
    type DeviceDetail,
    type DeviceListItem,
} from '../api/devices';
import DeviceDetails from '../components/DeviceDetails.vue';
import ErrorNotice from '../components/ErrorNotice.vue';
import FocusHeader from '../components/FocusHeader.vue';
import LocationPicker from '../components/LocationPicker.vue';
import {
    deviceStore,
    locationStore,
    recentLocationIds,
    rememberDevice,
    rememberLocation,
} from '../data';
import {
    bookingNotice,
    ClientRefKeeper,
    commentLength,
    MAX_COMMENT_LENGTH,
    MIN_DEFECT_COMMENT_LENGTH,
    normalizeComment,
    setBookingNotice,
} from '../data/booking';
import { locationPaths, type LocationChoice } from '../data/recentLocations';
import { closeQueryView, returnTo } from '../router/back';
import { useUnsavedInput } from '../runtime/unsavedInput';

const route = useRoute();
const router = useRouter();
const deviceId = Number(route.params['id']);

/**
 * Die Standortwahl ist eine Ansicht dieser Seite, geöffnet über `?standortwahl=1`: Eingaben bleiben
 * stehen, ein Update beim Seitenwechsel lädt nicht neu (gleicher Pfad), und der Zurück-Knopf des
 * Browsers bzw. Handys schließt nur die Auswahl.
 */
const PICKER_QUERY = 'standortwahl';
const otherLocationButton = useTemplateRef<HTMLButtonElement>('otherLocation');
/** „Details und Verlauf“ ebenso als Ansicht dieser Seite (`?details=1`): Eingaben bleiben. */
const DETAILS_QUERY = 'details';
const detailsButton = useTemplateRef<HTMLButtonElement>('detailsLink');

/** Symbole wie StatusBadge (Statusfarben immer mit Symbol und Wort). */
const SYMBOLS: Readonly<Record<BookableStatus, string>> = {
    ready: '✓',
    maintenance: '◐',
    defective: '✕',
};

// Stand des Geräts: sofort aus der Liste (falls geladen), dann frisch vom Server.
const device = shallowRef<DeviceListItem | null>(deviceStore.find(deviceId));
/** Alle Felder vom Server (Beschaffungsdatum, Notiz für die Details). */
const detail = shallowRef<DeviceDetail | null>(null);
const loadError = shallowRef<ApiError | null>(null);
const loading = ref(false);

// Auswahl: null = unverändert (folgt dem aktuellen Stand, auch nach einem Konflikt).
const chosenLocation = ref<number | null>(null);
const chosenStatus = ref<BookableStatus | null>(null);
const comment = ref('');

const saving = ref(false);
const saved = ref(false);
const saveError = shallowRef<ApiError | null>(null);
/** Nach 409 version_conflict: Der Kopf zeigt den neuen Stand, die Auswahl bleibt. */
const conflict = ref(false);
const nothingChanged = ref(false);
const submitted = ref(false);

const refs = new ClientRefKeeper();
const recentIds = ref<number[]>([]);

const locationId = computed(() => chosenLocation.value ?? device.value?.location.id ?? null);
const status = computed<BookableStatus | null>(() => {
    if (chosenStatus.value !== null) {
        return chosenStatus.value;
    }
    const current = device.value?.status;
    return current === undefined || current === 'retired' ? null : current;
});
const changesLocation = computed(
    () => device.value !== null && locationId.value !== device.value.location.id,
);
const changesStatus = computed(
    () => device.value !== null && status.value !== null && status.value !== device.value.status,
);
/** Neuer Defekt: Kommentar Pflicht (Entscheidung 18); ein schon defektes Gerät umbuchen nicht. */
const reportsDefect = computed(() => changesStatus.value && status.value === 'defective');
const commentTooShort = computed(
    () => reportsDefect.value && commentLength(comment.value) < MIN_DEFECT_COMMENT_LENGTH,
);
const showCommentError = computed(() => submitted.value && commentTooShort.value);
const commentDescribedBy = computed(() => {
    const ids = [
        ...(reportsDefect.value ? ['booking-comment-hint'] : []),
        ...(showCommentError.value ? ['booking-comment-error'] : []),
    ];
    return ids.length === 0 ? undefined : ids.join(' ');
});

useUnsavedInput(
    () =>
        !saved.value &&
        (chosenLocation.value !== null ||
            chosenStatus.value !== null ||
            comment.value.trim() !== ''),
);

const paths = computed(() => locationPaths(locationStore.state.locations ?? []));

/**
 * Aktueller Standort, ein in der Standortwahl gewählter und die letzten 3 eigenen Ziele
 * (Entscheidung 21), ohne gesperrte.
 */
const locationChoices = computed<(LocationChoice & { hint: string })[]>(() => {
    const current = device.value?.location;
    if (current === undefined) {
        return [];
    }
    const choices = [{ id: current.id, path: current.path, hint: 'aktuell' }];
    const chosen =
        chosenLocation.value === null ? undefined : paths.value.get(chosenLocation.value);
    if (chosen !== undefined && chosen.id !== current.id && !recentIds.value.includes(chosen.id)) {
        choices.push({ ...chosen, hint: 'gewählt' });
    }
    for (const id of recentIds.value) {
        const known = paths.value.get(id);
        if (known !== undefined && id !== current.id) {
            choices.push({ ...known, hint: 'zuletzt' });
        }
    }
    return choices;
});

const title = computed(() =>
    device.value === null
        ? 'Gerät'
        : `${device.value.identifier} · ${device.value.deviceType.name}`,
);
const subtitle = computed(() =>
    device.value === null
        ? null
        : `Jetzt: ${device.value.location.path} · ${device.value.statusLabel}`,
);

const pickerOpen = computed(
    () =>
        route.query[PICKER_QUERY] === '1' &&
        device.value !== null &&
        device.value.status !== 'retired',
);

const detailsOpen = computed(() => route.query[DETAILS_QUERY] === '1' && device.value !== null);

async function openDetails(): Promise<void> {
    await router.push({ query: { ...route.query, [DETAILS_QUERY]: '1' } });
}

function closeDetails(): Promise<void> {
    return closeQueryView(router, DETAILS_QUERY);
}

// Nach dem Schließen der Details zurück zum Link, oben beginnen.
watch(detailsOpen, async (open) => {
    if (!open) {
        window.scrollTo(0, 0);
        await nextTick();
        detailsButton.value?.focus({ preventScroll: true });
    }
});

async function openPicker(): Promise<void> {
    void locationStore.refresh('open');
    await router.push({ query: { ...route.query, [PICKER_QUERY]: '1' } });
}

function closePicker(): Promise<void> {
    return closeQueryView(router, PICKER_QUERY);
}

async function pickFromPicker(id: number): Promise<void> {
    pickLocation(id);
    await closePicker();
}

function retryLocations(): void {
    void locationStore.refresh('changed');
}

// Ansicht gewechselt: oben beginnen; nach dem Schließen zurück zum Knopf der Standortwahl.
watch(pickerOpen, async (open) => {
    window.scrollTo(0, 0);
    if (!open) {
        await nextTick();
        otherLocationButton.value?.focus({ preventScroll: true });
    }
});

async function load(): Promise<void> {
    loading.value = true;
    const result = await deviceStore.fetchCurrent(deviceId);
    loading.value = false;
    if (result.ok) {
        device.value = result.data;
        detail.value = result.data;
        loadError.value = null;
    } else {
        loadError.value = result.error;
    }
}

onMounted(() => {
    // Geöffnet → „Zuletzt benutzt“ (Entscheidung 16).
    rememberDevice(deviceId);
    recentIds.value = recentLocationIds();
    void locationStore.refresh('open');
    void load();
});

// Neue Eingabe: alte Meldungen zum Speichern gelten nicht mehr.
watch([chosenLocation, chosenStatus], () => {
    nothingChanged.value = false;
    saveError.value = null;
});

function pickLocation(id: number): void {
    chosenLocation.value = id === device.value?.location.id ? null : id;
}

function pickStatus(value: BookableStatus): void {
    chosenStatus.value = value === device.value?.status ? null : value;
}

async function save(): Promise<void> {
    const current = device.value;
    if (current === null || saving.value) {
        return;
    }
    submitted.value = true;
    conflict.value = false;
    saveError.value = null;
    if (!changesLocation.value && !changesStatus.value) {
        nothingChanged.value = true;
        return;
    }
    if (commentTooShort.value) {
        return;
    }
    const text = normalizeComment(comment.value);
    const content = {
        deviceId,
        version: current.version,
        ...(changesLocation.value && locationId.value !== null && { locationId: locationId.value }),
        ...(changesStatus.value && status.value !== null && { status: status.value }),
        ...(text !== '' && { comment: text }),
    };
    saving.value = true;
    const result = await deviceStore.book(deviceId, {
        ...content,
        clientRef: refs.refFor(content),
    });
    saving.value = false;
    if (!result.ok) {
        const latest =
            result.error.code === 'version_conflict' ? conflictDevice(result.error.details) : null;
        if (latest !== null) {
            device.value = latest;
            conflict.value = true;
            submitted.value = false;
        } else {
            saveError.value = result.error;
        }
        return;
    }
    if (content.locationId !== undefined && result.data.result.outcome === 'applied') {
        rememberLocation(content.locationId);
    }
    rememberDevice(deviceId);
    // Gerätezahlen der Standortwahl stimmen danach nicht mehr.
    void locationStore.refresh('changed');
    setBookingNotice(bookingNotice(result.data));
    saved.value = true;
    await returnTo(router, 'search');
}
</script>

<!-- Umbuchen und Status (Mockup UserUmbuchen); Admins nutzen dieselbe Ansicht (Entscheidung 15). -->
<template>
    <LocationPicker
        v-if="pickerOpen && device !== null"
        :locations="locationStore.state.locations"
        :loading="locationStore.state.loading"
        :error="locationStore.state.error"
        :current-id="device.location.id"
        :selected-id="locationId ?? device.location.id"
        :device-label="title"
        @pick="pickFromPicker"
        @close="closePicker"
        @retry="retryLocations"
    />

    <DeviceDetails
        v-else-if="detailsOpen && device !== null"
        :device="device"
        :detail="detail"
        :title="title"
        @close="closeDetails"
    />

    <template v-else>
        <FocusHeader :title="title" :subtitle="subtitle" />

        <div v-if="device === null" class="device-content">
            <p v-if="loadError === null" class="hint" role="status">Gerät wird geladen …</p>
            <div v-else-if="loadError.status === 404" class="notice notice-info" role="alert">
                <p>Dieses Gerät gibt es nicht (mehr).</p>
            </div>
            <ErrorNotice v-else :error="loadError">
                <button class="btn" type="button" :disabled="loading" @click="load">
                    Erneut versuchen
                </button>
            </ErrorNotice>
        </div>

        <div v-else-if="device.status === 'retired'" class="device-content">
            <div class="notice notice-info" role="status" data-testid="device-retired">
                <p>Dieses Gerät ist ausgesondert und kann nicht umgebucht werden.</p>
            </div>
            <button ref="detailsLink" class="details-link" type="button" @click="openDetails">
                Details und Verlauf
            </button>
        </div>

        <form v-else class="device-form" novalidate @submit.prevent="save">
            <div class="device-content">
                <div
                    v-if="conflict"
                    class="notice notice-info"
                    role="alert"
                    data-testid="booking-conflict"
                >
                    <p>
                        Das Gerät wurde inzwischen geändert. Oben steht jetzt der aktuelle Stand.
                        Bitte prüfen Sie Ihre Auswahl und speichern Sie erneut.
                    </p>
                </div>

                <section class="device-section" aria-labelledby="location-heading">
                    <h2 id="location-heading" class="device-heading">Standort</h2>
                    <button
                        v-for="choice in locationChoices"
                        :key="choice.id"
                        class="location-choice"
                        type="button"
                        :aria-pressed="choice.id === locationId"
                        data-testid="location-choice"
                        @click="pickLocation(choice.id)"
                    >
                        <svg
                            width="20"
                            height="20"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            aria-hidden="true"
                        >
                            <path d="M12 21s-7-6.5-7-12a7 7 0 0 1 14 0c0 5.5-7 12-7 12z" />
                            <circle cx="12" cy="9" r="2.5" />
                        </svg>
                        <span class="location-path">{{ choice.path }}</span>
                        <span class="location-hint">{{ choice.hint }}</span>
                    </button>
                    <button
                        ref="otherLocation"
                        class="other-location"
                        type="button"
                        data-testid="other-location"
                        @click="openPicker"
                    >
                        <svg
                            width="20"
                            height="20"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            aria-hidden="true"
                        >
                            <circle cx="11" cy="11" r="7" />
                            <path d="M20 20l-4-4" />
                        </svg>
                        <span class="other-location-text">Anderen Standort suchen …</span>
                        <svg
                            class="other-location-arrow"
                            width="20"
                            height="20"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            aria-hidden="true"
                        >
                            <path d="M9 6l6 6-6 6" />
                        </svg>
                    </button>
                </section>

                <section class="device-section" aria-labelledby="status-heading">
                    <h2 id="status-heading" class="device-heading">Status</h2>
                    <div class="status-choices">
                        <button
                            v-for="value in BOOKABLE_STATUSES"
                            :key="value"
                            class="status-choice"
                            :class="`status-choice-${value}`"
                            type="button"
                            :aria-pressed="value === status"
                            data-testid="status-choice"
                            @click="pickStatus(value)"
                        >
                            <span class="status-symbol" aria-hidden="true">{{
                                SYMBOLS[value]
                            }}</span>
                            {{ STATUS_LABELS[value] }}
                        </button>
                    </div>
                </section>

                <section class="device-section">
                    <label for="booking-comment" class="device-heading">
                        {{ reportsDefect ? 'Was ist defekt?' : 'Kommentar (optional)' }}
                    </label>
                    <span v-if="reportsDefect" id="booking-comment-hint" class="defect-hint">
                        Bitte kurz beschreiben, was nicht funktioniert. Das hilft bei der Reparatur.
                    </span>
                    <textarea
                        id="booking-comment"
                        v-model="comment"
                        class="comment"
                        :class="{ 'comment-defect': reportsDefect }"
                        rows="2"
                        :maxlength="MAX_COMMENT_LENGTH"
                        :placeholder="
                            reportsDefect
                                ? 'z. B. Ausatemventil undicht'
                                : 'z. B. nach Übung gereinigt'
                        "
                        :aria-invalid="showCommentError"
                        :aria-describedby="commentDescribedBy"
                    />
                    <p
                        v-if="showCommentError"
                        id="booking-comment-error"
                        class="field-error"
                        data-testid="comment-error"
                    >
                        Bitte beschreiben Sie den Defekt mit mindestens
                        {{ MIN_DEFECT_COMMENT_LENGTH }} Zeichen.
                    </p>
                </section>

                <button ref="detailsLink" class="details-link" type="button" @click="openDetails">
                    Details und Verlauf
                </button>
            </div>

            <div class="device-footer">
                <p v-if="nothingChanged" class="notice notice-info" role="status">
                    Bitte wählen Sie einen anderen Standort oder Status.
                </p>
                <ErrorNotice v-if="saveError !== null" :error="saveError" />
                <button class="btn btn-primary" type="submit" :disabled="saving">
                    {{ saving ? 'Wird gespeichert …' : 'Speichern' }}
                </button>
            </div>
        </form>
    </template>
</template>

<style scoped>
.device-form {
    display: flex;
    flex: 1 0 auto;
    flex-direction: column;
}

.device-content {
    display: flex;
    flex: 1 0 auto;
    flex-direction: column;
    gap: 18px;
    padding: 16px;
}

.device-section {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.device-heading {
    font-size: var(--font-size-lg);
    font-weight: var(--font-weight-bold);
}

.location-choice {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    min-height: 52px;
    padding: 0 14px;
    border: 1px solid var(--input-border);
    border-radius: var(--radius-md);
    background: var(--surface);
    color: var(--text);
    font: inherit;
    font-size: var(--font-size-lg);
    text-align: left;
    cursor: pointer;
}

.location-choice:hover {
    background: var(--surface-hover);
    border-color: var(--line);
}

.location-choice[aria-pressed='true'] {
    border: 2px solid var(--line);
    background: var(--selected-bg);
    font-weight: var(--font-weight-bold);
}

.location-path {
    flex: 1 1 auto;
    min-width: 0;
    overflow-wrap: anywhere;
}

.location-hint {
    font-size: var(--font-size-xs);
    font-weight: var(--font-weight-normal);
    color: var(--muted);
}

/* Deutlich als Schaltfläche (Live-Test): umrandet in Akzentfarbe, volle Breite, Abstand zum Status. */
.other-location {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    min-height: 52px;
    margin: 2px 0 14px;
    padding: 0 12px 0 14px;
    border: 2px solid var(--line);
    border-radius: var(--radius-md);
    background: var(--surface);
    color: var(--line);
    font: inherit;
    font-size: var(--font-size-base);
    font-weight: var(--font-weight-semibold);
    text-align: left;
    cursor: pointer;
}

.other-location-text {
    flex: 1 1 auto;
    min-width: 0;
    overflow-wrap: anywhere;
}

.other-location-arrow {
    flex: none;
}

/* Hover am PC (KONZEPT.md: Buttons färben sich): kräftig hinterlegt mit Randbalken links. */
.other-location:hover {
    background: var(--nav-active);
    box-shadow: inset 4px 0 0 var(--line);
    color: var(--text);
}

.status-choices {
    display: flex;
    gap: 8px;
}

.status-choice {
    display: flex;
    flex: 1 1 0;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 4px;
    min-width: 0;
    min-height: 84px;
    padding: 6px 4px;
    border: 1px solid var(--input-border);
    border-radius: var(--radius-lg);
    background: var(--surface);
    font: inherit;
    font-size: var(--font-size-md);
    font-weight: var(--font-weight-bold);
    text-align: center;
    hyphens: auto;
    overflow-wrap: anywhere;
    cursor: pointer;
}

.status-symbol {
    font-size: 22px;
    line-height: 1;
}

.status-choice-ready {
    color: var(--status-ready-fg);
}

.status-choice-maintenance {
    color: var(--status-maintenance-fg);
}

.status-choice-defective {
    color: var(--status-defective-fg);
}

.status-choice:hover {
    border-color: currentcolor;
}

.status-choice-ready[aria-pressed='true'] {
    border: 3px solid currentcolor;
    background: var(--status-ready-bg);
}

.status-choice-maintenance[aria-pressed='true'] {
    border: 3px solid currentcolor;
    background: var(--status-maintenance-bg);
}

.status-choice-defective[aria-pressed='true'] {
    border: 3px solid currentcolor;
    background: var(--status-defective-bg);
}

.defect-hint {
    font-size: var(--font-size-md);
    color: var(--danger);
}

.comment {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid var(--input-border);
    border-radius: var(--radius-md);
    background: var(--surface);
    color: var(--text);
    font: inherit;
    font-size: var(--font-size-base);
    resize: vertical;
}

.comment:focus-visible {
    outline: none;
    border-color: var(--line);
    box-shadow: 0 0 0 1px var(--line);
}

.comment-defect,
.comment-defect:focus-visible,
.comment[aria-invalid='true'] {
    border: 2px solid var(--danger);
    box-shadow: none;
}

/* Speichern bleibt unten sichtbar, auch bei langer Seite. */
.device-footer {
    position: sticky;
    bottom: 0;
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding: 12px 16px 22px;
    background: var(--surface);
    border-top: 1px solid var(--border);
}

.hint {
    margin: 0;
    color: var(--muted);
}

/* Link „Details und Verlauf“ (Entscheidung 15): unten, zurückhaltend, aber mindestens 44 px. */
.details-link {
    align-self: center;
    min-height: var(--touch-min);
    padding: 0 12px;
    border: 0;
    background: none;
    color: var(--line);
    font: inherit;
    font-size: var(--font-size-base);
    font-weight: var(--font-weight-semibold);
    text-decoration: underline;
    text-underline-offset: 3px;
    cursor: pointer;
}

.details-link:hover {
    color: var(--text);
}
</style>
