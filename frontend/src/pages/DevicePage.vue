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
    type SwapResponse,
} from '../api/devices';
import ConfirmDialog from '../components/ConfirmDialog.vue';
import SwapDialog from '../components/SwapDialog.vue';
import DeviceDetails from '../components/DeviceDetails.vue';
import ErrorNotice from '../components/ErrorNotice.vue';
import FocusHeader from '../components/FocusHeader.vue';
import LocationPicker from '../components/LocationPicker.vue';
import {
    adminDeviceList,
    bookingQueue,
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
import { actionTime, offlineBookingProblem, pendingDevice, queuedText } from '../data/bookingQueue';
import { offlineData } from '../data/offline';
import { fetchClientConfig } from '../api/clientConfig';
import { parseStockBlock, stockBlockText } from '../data/swap';
import { ensureUsedTarget, usedTargetName, usedTargetText } from '../data/usedTarget';
import { currentAppBase } from '../runtime/appBase';
import { connectivity, isOffline } from '../runtime/connectivity';
import {
    bookingLocationChoices,
    locationPaths,
    type BookingLocationChoice,
} from '../data/recentLocations';
import { closeQueryView, returnTo } from '../router/back';
import { useUnsavedInput } from '../runtime/unsavedInput';
import { can, restricted } from '../api/permissions';
import { canOpenRoute } from '../auth/redirect';
import { currentPermissions, session } from '../auth/session';

const route = useRoute();
const router = useRouter();
const deviceId = Number(route.params['id']);
/** Mit Recht auf das Gerätedetail der Link dorthin (Bearbeiten, Aussondern, Wieder aufnehmen). */
const showsDetailLink = computed(
    () => session.value !== null && canOpenRoute(router, 'device-details', session.value),
);
/** Umbuchen bzw. Status setzen nur mit der Aktion der Rolle (Plan Phase 1, Schritt 1.7b). */
const canMove = computed(() => can(currentPermissions.value, 'book.move'));
const canSetStatus = computed(() => can(currentPermissions.value, 'book.status'));
/** „Benutzt melden“ (Phase 2, P2.3): Status benutzt, das Ziel (Sammelstelle) gibt der Server vor. */
const canReportUsed = computed(() => can(currentPermissions.value, 'device.used'));
/** Tauschen (P2.8): Ausrüstung heraus, Ersatz hinein, in einer Buchung (nur mit Verbindung). */
const canSwap = computed(() => can(currentPermissions.value, 'device.swap'));
/**
 * Einschränkung „Bei Einmalgeräten nur benutzt melden“: Bei einem Einmalgerät gibt es dann weder
 * Standort noch Status, nur „Benutzt melden“ (der Server lehnt anderes mit 403 restricted_action ab).
 */
const usedOnly = computed(
    () =>
        restricted(currentPermissions.value, 'single_use_used_only') &&
        device.value?.deviceType.singleUse === true,
);
const showMove = computed(() => canMove.value && !usedOnly.value);
const showStatus = computed(() => canSetStatus.value && !usedOnly.value);
const alreadyUsed = computed(() => device.value?.status === 'used');

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
const submitted = ref(false);

const refs = new ClientRefKeeper();
const recentIds = ref<number[]>([]);

const locationId = computed(() => chosenLocation.value ?? device.value?.location.id ?? null);
const status = computed<BookableStatus | null>(() => {
    if (chosenStatus.value !== null) {
        return chosenStatus.value;
    }
    const current = device.value?.status;
    return current === undefined || current === 'retired' || current === 'used' ? null : current;
});
const changesLocation = computed(
    () => device.value !== null && locationId.value !== device.value.location.id,
);
const changesStatus = computed(
    () => device.value !== null && status.value !== null && status.value !== device.value.status,
);
/** „Speichern“ nur mit einer Änderung von Standort oder Status (T4). */
const hasChanges = computed(() => changesLocation.value || changesStatus.value);
/** Kommentar ohne Änderung: Hinweis, denn ein Kommentar allein wird nicht gebucht. */
const nothingChanged = computed(() => !hasChanges.value && comment.value.trim() !== '');
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
 * Aktueller Standort, ein in der Standortwahl gewählter und das letzte eigene Ziel
 * (Entscheidung 21, T2), ohne gesperrte.
 */
const locationChoices = computed<BookingLocationChoice[]>(() => {
    const current = device.value?.location;
    return current === undefined
        ? []
        : bookingLocationChoices(current, chosenLocation.value, recentIds.value, paths.value);
});

const title = computed(() =>
    device.value === null
        ? 'Ausrüstung'
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
    saveError.value = null;
    offlineProblem.value = null;
});

function pickLocation(id: number): void {
    chosenLocation.value = id === device.value?.location.id ? null : id;
}

function pickStatus(value: BookableStatus): void {
    chosenStatus.value = value === device.value?.status ? null : value;
}

/** Gründe, warum offline nicht gebucht werden kann (Daten zu alt, keine Kopie). */
const offlineProblem = ref<string | null>(null);

/**
 * Buchung in die Warteschlange; die Liste zeigt sofort den gewünschten Stand, danach zurück wie nach
 * dem Speichern online (Entscheidungen 52 und 54). `client_ref` und Aktionszeit sind die des ersten
 * Versuchs mit diesem Inhalt (`refs`): Kam ein online gesendeter Versuch trotz Netzwerkfehler an,
 * wiederholt der Server nur sein Ergebnis.
 */
async function saveOffline(
    current: DeviceListItem,
    content: {
        deviceId: number;
        version: number;
        locationId?: number;
        status?: BookableStatus;
        used?: boolean;
        comment?: string;
    },
): Promise<void> {
    offlineProblem.value = offlineBookingProblem(
        offlineData.syncedAt,
        bookingQueue.currentLimits,
        new Date(),
    );
    if (offlineProblem.value !== null) {
        return;
    }
    const attempt = refs.attemptFor(content);
    const target =
        content.locationId === undefined ? undefined : paths.value.get(content.locationId);
    const booking = {
        clientRef: attempt.ref,
        deviceId,
        identifier: current.identifier,
        deviceTypeName: current.deviceType.name,
        version: content.version,
        occurredAt: actionTime(attempt.at),
        ...(content.locationId !== undefined && {
            locationId: content.locationId,
            locationPath: target?.path ?? current.location.path,
        }),
        ...(content.status !== undefined && {
            status: content.status,
            statusLabel: STATUS_LABELS[content.status],
        }),
        ...(content.used === true && { used: true, statusLabel: 'benutzt' }),
        ...(content.comment !== undefined && { comment: content.comment }),
    };
    saving.value = true;
    const added = await bookingQueue.add(booking);
    saving.value = false;
    if (!added) {
        offlineProblem.value = 'Die Buchung ließ sich auf diesem Gerät nicht speichern.';
        return;
    }
    deviceStore.upsert(pendingDevice(current, { ...booking, state: 'waiting' }));
    if (content.locationId !== undefined) {
        rememberLocation(content.locationId);
    }
    rememberDevice(deviceId);
    setBookingNotice({
        kind: 'info',
        text: `Gespeichert – wird gesendet, sobald eine Verbindung besteht: ${queuedText({ ...booking, state: 'waiting' })}`,
    });
    saved.value = true;
    await returnTo(router, ['search', 'inspections', 'devices', 'device-details', 'notice']);
}

async function save(): Promise<void> {
    const current = device.value;
    if (current === null || saving.value) {
        return;
    }
    submitted.value = true;
    conflict.value = false;
    saveError.value = null;
    if (!hasChanges.value) {
        return;
    }
    if (commentTooShort.value) {
        return;
    }
    const text = normalizeComment(comment.value);
    await submit(current, {
        deviceId,
        version: current.version,
        ...(changesLocation.value && locationId.value !== null && { locationId: locationId.value }),
        ...(changesStatus.value && status.value !== null && { status: status.value }),
        ...(text !== '' && { comment: text }),
    });
}

/**
 * Die letzte Buchung hat den Sollbestand eines Standorts unterschritten (409, Phase 2, P2.8): Statt nur einer
 * Fehlermeldung bietet die Seite „Tauschen …“ an und, mit der Aktion stock.override, „Trotzdem buchen …“.
 */
const stockBlock = computed(() => parseStockBlock(saveError.value?.details ?? null));
const lastContent = shallowRef<Parameters<typeof submit>[1] | null>(null);
const overrideOpen = ref(false);
const swapOpen = ref(false);
const swapButton = useTemplateRef<HTMLButtonElement>('swapButton');

function openSwap(): void {
    swapOpen.value = true;
}

async function bookBelowMinimum(): Promise<void> {
    const current = device.value;
    const content = lastContent.value;
    if (current === null || content === null || saving.value) {
        return;
    }
    saveError.value = null;
    await submit(current, { ...content, confirmedBelowMinimum: true });
}

async function onSwapped(response: SwapResponse): Promise<void> {
    rememberDevice(deviceId);
    setBookingNotice({
        kind: 'success',
        text: `${response.out.identifier} ⇄ ${response.in.identifier} · getauscht`,
    });
    saved.value = true;
    await returnTo(router, ['search', 'inspections', 'devices', 'device-details', 'notice']);
}

async function onSwapQueued(text: string): Promise<void> {
    rememberDevice(deviceId);
    setBookingNotice({
        kind: 'info',
        text: `Gespeichert – wird gesendet, sobald eine Verbindung besteht: ${text}`,
    });
    saved.value = true;
    await returnTo(router, ['search', 'inspections', 'devices', 'device-details', 'notice']);
}

function onSwapConflict(latest: DeviceListItem): void {
    if (latest.id === deviceId) {
        device.value = latest;
    }
}

/** Rückfrage vor „Benutzt melden“ (am Handy tippt man leicht daneben, das Gerät kann den Filter verlassen). */
const usedConfirmOpen = ref(false);
const usedConfirmTitle = computed(
    () => `${device.value?.identifier ?? 'Diese Ausrüstung'} als benutzt melden?`,
);
const usedConfirmDescription = computed(() => usedTargetText(usedTargetName.value));

async function askReportUsed(): Promise<void> {
    if (device.value === null || saving.value || alreadyUsed.value) {
        return;
    }
    let storage: Storage | null = null;
    try {
        storage = localStorage;
    } catch {
        // Ohne Speicher gilt der Wert nur für diesen Seitenaufruf.
    }
    await ensureUsedTarget({
        area: currentAppBase().area,
        offline: isOffline.value,
        fetchConfig: () => fetchClientConfig(),
        storage,
    });
    usedConfirmOpen.value = true;
}

/** „Benutzt melden“: wie Speichern, online und offline (Warteschlange). */
async function reportUsed(): Promise<void> {
    const current = device.value;
    if (current === null || saving.value || alreadyUsed.value) {
        return;
    }
    conflict.value = false;
    saveError.value = null;
    const text = normalizeComment(comment.value);
    await submit(current, {
        deviceId,
        version: current.version,
        used: true,
        ...(text !== '' && { comment: text }),
    });
}

async function submit(
    current: DeviceListItem,
    content: {
        deviceId: number;
        version: number;
        locationId?: number;
        status?: BookableStatus;
        used?: boolean;
        comment?: string;
        confirmedBelowMinimum?: boolean;
    },
): Promise<void> {
    lastContent.value = content;
    // Offline (Streifen): gleich in die Warteschlange (KONZEPT.md, "Offline-Modus – Entscheidungen",
    // 52), sofern offline gebucht werden darf; meldet der Browser offline, sonst gleich der Grund.
    // Online und nach einer nur gescheiterten Anfrage wie bisher senden; scheitert es an der
    // Verbindung, ebenfalls in die Warteschlange (wenn erlaubt), sonst der Fehler (die Eingaben
    // bleiben, erneut senden wirkt dank client_ref nicht doppelt).
    const queueProblem = offlineBookingProblem(
        offlineData.syncedAt,
        bookingQueue.currentLimits,
        new Date(),
    );
    if (isOffline.value && (queueProblem === null || connectivity.browserOffline)) {
        await saveOffline(current, content);
        return;
    }
    saving.value = true;
    const result = await deviceStore.book(deviceId, {
        ...content,
        clientRef: refs.refFor(content),
    });
    saving.value = false;
    if (!result.ok && result.error.code === 'network' && queueProblem === null) {
        await saveOffline(current, content);
        return;
    }
    // Die Kopie ist zu alt für die Warteschlange: zusätzlich der Grund (Entscheidung 54).
    if (!result.ok && result.error.code === 'network' && offlineData.syncedAt !== null) {
        offlineProblem.value = queueProblem;
    }
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
    // Geräteliste der Admin-Konsole, falls schon geöffnet (sonst nichts).
    void adminDeviceList.refresh('changed');
    setBookingNotice(bookingNotice(result.data));
    saved.value = true;
    // Zurück, woher man kam (Suchen, Prüffristen, Geräteliste, Gerätedetail oder Hinweis der
    // Admin-Konsole), sonst zu „Suchen“ (Entscheidung 22).
    await returnTo(router, ['search', 'inspections', 'devices', 'device-details', 'notice']);
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
            <p v-if="loadError === null" class="hint" role="status">Ausrüstung wird geladen …</p>
            <div v-else-if="loadError.status === 404" class="notice notice-info" role="alert">
                <p>Diese Ausrüstung gibt es nicht (mehr).</p>
            </div>
            <ErrorNotice v-else :error="loadError">
                <button class="btn" type="button" :disabled="loading" @click="load">
                    Erneut versuchen
                </button>
            </ErrorNotice>
        </div>

        <div v-else-if="device.status === 'retired'" class="device-content">
            <div class="notice notice-info" role="status" data-testid="device-retired">
                <p>Diese Ausrüstung ist ausgesondert und kann nicht umgebucht werden.</p>
            </div>
            <button ref="detailsLink" class="details-link" type="button" @click="openDetails">
                Details und Verlauf
            </button>
            <RouterLink
                v-if="showsDetailLink"
                class="details-link"
                :to="{ name: 'device-details', params: { id: deviceId } }"
            >
                Detailseite (Admin)
            </RouterLink>
        </div>

        <div v-else-if="!canMove && !canSetStatus && !canReportUsed" class="device-content">
            <div class="notice notice-info" role="status" data-testid="device-view-only">
                <p>Sie dürfen diese Ausrüstung nur ansehen, nicht umbuchen.</p>
            </div>
            <button ref="detailsLink" class="details-link" type="button" @click="openDetails">
                Details und Verlauf
            </button>
            <RouterLink
                v-if="showsDetailLink"
                class="details-link"
                :to="{ name: 'device-details', params: { id: deviceId } }"
            >
                Detailseite (Admin)
            </RouterLink>
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
                        Die Ausrüstung wurde inzwischen geändert. Oben steht jetzt der aktuelle
                        Stand. Bitte prüfen Sie Ihre Auswahl und speichern Sie erneut.
                    </p>
                </div>

                <section v-if="showMove" class="device-section" aria-labelledby="location-heading">
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

                <section v-if="showStatus" class="device-section" aria-labelledby="status-heading">
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
                <RouterLink
                    v-if="showsDetailLink"
                    class="details-link"
                    :to="{ name: 'device-details', params: { id: deviceId } }"
                >
                    Detailseite (Admin)
                </RouterLink>
            </div>

            <div class="device-footer">
                <p
                    v-if="usedOnly"
                    class="notice notice-info"
                    role="status"
                    data-testid="used-only-hint"
                >
                    Das ist ein Einmalgerät: Sie können es nur als benutzt melden.
                </p>
                <p v-if="alreadyUsed && canReportUsed" class="notice notice-info" role="status">
                    Diese Ausrüstung ist schon als benutzt gemeldet.
                </p>
                <p v-if="nothingChanged && !usedOnly" class="notice notice-info" role="status">
                    Bitte wählen Sie einen anderen Standort oder Status.
                </p>
                <div
                    v-if="stockBlock !== null"
                    class="notice notice-error"
                    role="alert"
                    data-testid="stock-block"
                >
                    <p>{{ stockBlockText(stockBlock) }}</p>
                    <p>Tauschen Sie die Ausrüstung gegen einen einsatzbereiten Ersatz.</p>
                    <div class="stock-actions">
                        <button
                            v-if="canSwap"
                            class="btn btn-primary"
                            type="button"
                            data-testid="stock-swap"
                            @click="openSwap"
                        >
                            Tauschen …
                        </button>
                        <button
                            v-if="stockBlock.canOverride"
                            class="btn"
                            type="button"
                            data-testid="stock-override"
                            @click="overrideOpen = true"
                        >
                            Trotzdem buchen …
                        </button>
                    </div>
                </div>
                <ErrorNotice v-else-if="saveError !== null" :error="saveError" />
                <p
                    v-if="offlineProblem !== null"
                    class="notice notice-error"
                    role="alert"
                    data-testid="offline-problem"
                >
                    {{ offlineProblem }}
                </p>
                <button
                    v-if="!usedOnly"
                    class="btn btn-primary"
                    type="submit"
                    :disabled="saving || !hasChanges"
                >
                    {{ saving ? 'Wird gespeichert …' : 'Speichern' }}
                </button>
                <button
                    v-if="canSwap"
                    ref="swapButton"
                    class="btn"
                    type="button"
                    data-testid="swap-open"
                    :disabled="saving"
                    @click="openSwap"
                >
                    Tauschen …
                </button>
                <button
                    v-if="canReportUsed"
                    class="btn"
                    :class="{ 'btn-primary': usedOnly }"
                    type="button"
                    data-testid="report-used"
                    :disabled="saving || alreadyUsed"
                    @click="void askReportUsed()"
                >
                    {{ saving && usedOnly ? 'Wird gespeichert …' : 'Benutzt melden' }}
                </button>
            </div>
        </form>
        <ConfirmDialog
            v-model:open="usedConfirmOpen"
            :title="usedConfirmTitle"
            :description="usedConfirmDescription"
            confirm-label="Benutzt melden"
            @confirm="reportUsed"
        />
        <ConfirmDialog
            v-model:open="overrideOpen"
            title="Sollbestand unterschreiten?"
            description="Der Standort hat danach weniger einsatzbereite Ausrüstung als vorgegeben. Die Admins bekommen einen Hinweis."
            confirm-label="Trotzdem buchen"
            @confirm="bookBelowMinimum"
        />
        <SwapDialog
            v-if="canSwap && device !== null"
            v-model:open="swapOpen"
            :device="device"
            :type-ids="stockBlock?.typeIds ?? []"
            :return-focus="swapButton"
            @swapped="onSwapped"
            @queued="onSwapQueued"
            @conflict="onSwapConflict"
        />
    </template>
</template>

<style scoped>
.stock-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

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
    display: inline-flex;
    align-items: center;
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
