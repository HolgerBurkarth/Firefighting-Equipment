<script setup lang="ts">
import { computed, onMounted, ref, shallowRef, useId, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import type { ApiError } from '../api/client';
import type { DeviceDetail } from '../api/devices';
import type { Notice } from '../api/notices';
import BookingNoticeMessage from '../components/BookingNoticeMessage.vue';
import ErrorNotice from '../components/ErrorNotice.vue';
import FormDialog from '../components/FormDialog.vue';
import StatusBadge from '../components/StatusBadge.vue';
import { deviceStore, loadNotice, noticeList, reopenNotice, resolveNotice } from '../data';
import { takeBookingNotice } from '../data/booking';
import { dateTimeText, eventTimeText } from '../data/changeTime';
import {
    inquiryMailLink,
    noticeExplanation,
    noticeNote,
    noticeNoteProblem,
    otherStateHeading,
} from '../data/notices';
import { goBack } from '../router/back';
import { useNow } from '../runtime/now';
import { useUnsavedInput } from '../runtime/unsavedInput';

/**
 * Ein Hinweis des Protokolls (`protokoll/<id>`, Ziel der Hinweis-Mails; KONZEPT.md, "Hinweise für
 * Admins"): was geschehen ist, die Buchung und der andere Stand mit Konten (Rückfrage per Mail) und
 * Zeiten, der jetzige Stand des Geräts mit „Umbuchen / Status ändern“ (danach zurück hierher) und
 * „Erledigen“ mit Notiz bzw. „Wieder öffnen“, darunter der Verlauf des Hinweises.
 */
const route = useRoute();
const router = useRouter();
const noticeId = Number(route.params['id']);
const now = useNow();
const id = useId();

const notice = shallowRef<Notice | null>(noticeList.find(noticeId));
const loadError = shallowRef<ApiError | null>(null);
const loading = ref(false);
const device = shallowRef<DeviceDetail | null>(null);
const deviceError = shallowRef<ApiError | null>(null);
const success = ref<string | null>(null);
const pageError = ref<ApiError | null>(null);
const busy = ref(false);
/** Nach „Umbuchen / Status ändern“ zurück hierher (Entscheidung 22). */
const bookingNotice = shallowRef(takeBookingNotice());

async function load(): Promise<void> {
    loading.value = true;
    const result = await loadNotice(noticeId);
    loading.value = false;
    if (result.ok) {
        notice.value = result.data;
        loadError.value = null;
    } else {
        loadError.value = result.error;
        if (result.error.status === 404) {
            notice.value = null;
        }
    }
}

async function loadDevice(deviceId: number): Promise<void> {
    const result = await deviceStore.fetchCurrent(deviceId);
    if (result.ok) {
        device.value = result.data;
        deviceError.value = null;
    } else {
        deviceError.value = result.error;
    }
}

watch(
    () => notice.value?.device.id,
    (deviceId) => {
        if (deviceId !== undefined && device.value?.id !== deviceId) {
            void loadDevice(deviceId);
        }
    },
    { immediate: true },
);

const explanation = computed(() =>
    notice.value === null ? null : noticeExplanation(notice.value),
);
const bookingMail = computed(() =>
    notice.value === null ? null : inquiryMailLink(notice.value, 'booking'),
);
const otherMail = computed(() =>
    notice.value === null ? null : inquiryMailLink(notice.value, 'other'),
);

// Erledigen mit Notiz (Fenster).
const dialogOpen = ref(false);
const note = ref('');
const noteError = ref<string | null>(null);
const dialogError = ref<ApiError | null>(null);
const opener = ref<HTMLElement | null>(null);
const liveProblem = computed(() => noticeNoteProblem(note.value));

/** Ungespeicherte Eingaben im Fenster (auch für FormDialog: Klick daneben, Escape). */
const unsaved = computed(() => dialogOpen.value && note.value.trim() !== '');
useUnsavedInput(() => unsaved.value);

function openResolve(event: Event): void {
    opener.value = event.currentTarget instanceof HTMLElement ? event.currentTarget : null;
    success.value = null;
    pageError.value = null;
    bookingNotice.value = null;
    note.value = '';
    noteError.value = null;
    dialogError.value = null;
    dialogOpen.value = true;
}

async function resolve(): Promise<void> {
    noteError.value = null;
    dialogError.value = null;
    if (liveProblem.value !== null || busy.value) {
        return;
    }
    busy.value = true;
    const result = await resolveNotice(noticeId, noticeNote(note.value));
    busy.value = false;
    if (!result.ok) {
        if (result.error.code === 'invalid_request') {
            noteError.value = result.error.message;
        } else {
            dialogError.value = result.error;
        }
        return;
    }
    notice.value = result.data;
    success.value = 'Der Hinweis ist erledigt.';
    dialogOpen.value = false;
}

async function reopen(): Promise<void> {
    if (busy.value) {
        return;
    }
    success.value = null;
    pageError.value = null;
    bookingNotice.value = null;
    busy.value = true;
    const result = await reopenNotice(noticeId);
    busy.value = false;
    if (!result.ok) {
        pageError.value = result.error;
        return;
    }
    notice.value = result.data;
    success.value = 'Der Hinweis ist wieder offen.';
}

function back(): Promise<void> {
    return goBack(router, { name: 'notices' });
}

onMounted(() => {
    void load();
});
</script>

<template>
    <div class="detail-nav">
        <button class="back-link" type="button" @click="back">
            <svg
                width="18"
                height="18"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                aria-hidden="true"
            >
                <path d="M15 6l-6 6 6 6" />
            </svg>
            Zurück
        </button>
    </div>

    <template v-if="notice === null">
        <h1>Hinweis</h1>
        <p v-if="loadError === null" class="hint" role="status">Hinweis wird geladen …</p>
        <div v-else-if="loadError.status === 404" class="notice notice-info" role="alert">
            <p>Diesen Hinweis gibt es nicht.</p>
        </div>
        <ErrorNotice v-else :error="loadError">
            <button class="btn retry" type="button" :disabled="loading" @click="load">
                Erneut versuchen
            </button>
        </ErrorNotice>
    </template>

    <template v-else>
        <div class="head">
            <h1>{{ notice.kindLabel }}</h1>
            <span
                class="badge head-badge"
                :class="notice.status === 'open' ? 'badge-maintenance' : 'badge-ready'"
                data-testid="notice-status"
                >{{ notice.statusLabel }}</span
            >
        </div>
        <p class="lead">
            <RouterLink :to="{ name: 'device-details', params: { id: notice.device.id } }">
                {{ notice.device.identifier }}
            </RouterLink>
            · {{ notice.device.deviceType }} · Hinweis vom {{ dateTimeText(notice.createdAt) }}
        </p>
        <p v-if="explanation !== null" class="explanation" data-testid="notice-explanation">
            {{ explanation }}
        </p>

        <ErrorNotice v-if="loadError !== null" :error="loadError" />
        <p
            v-if="success !== null"
            class="notice notice-success"
            role="status"
            data-testid="success-notice"
        >
            {{ success }}
        </p>
        <ErrorNotice v-if="pageError !== null" :error="pageError" />
        <BookingNoticeMessage v-if="bookingNotice !== null" :notice="bookingNotice" />

        <div class="states">
            <section class="card state" aria-labelledby="booking-heading" data-testid="booking">
                <h2 id="booking-heading">Buchung</h2>
                <dl class="facts">
                    <dt>Von</dt>
                    <dd>
                        {{ notice.booking.user.name
                        }}<span v-if="notice.booking.user.email !== null" class="email">{{
                            notice.booking.user.email
                        }}</span>
                    </dd>
                    <dt>Erfasst</dt>
                    <dd>{{ eventTimeText(notice.booking.occurredAt, now) }}</dd>
                    <dt>Eingang beim Server</dt>
                    <dd>{{ eventTimeText(notice.booking.receivedAt, now) }}</dd>
                    <dt v-if="notice.booking.location !== null">Gewünschter Standort</dt>
                    <dd v-if="notice.booking.location !== null">
                        {{ notice.booking.location.path }}
                    </dd>
                    <dt v-if="notice.booking.status !== null">Gewünschter Status</dt>
                    <dd v-if="notice.booking.status !== null">
                        <StatusBadge
                            :status="notice.booking.status.value"
                            :label="notice.booking.status.label"
                        />
                    </dd>
                    <dt>Kommentar</dt>
                    <dd class="comment">{{ notice.booking.comment ?? '–' }}</dd>
                </dl>
                <a
                    v-if="bookingMail !== null"
                    class="btn mail"
                    :href="bookingMail"
                    :aria-label="`Rückfrage per E-Mail an ${notice.booking.user.name}`"
                    >Rückfrage per E-Mail</a
                >
            </section>

            <section class="card state" aria-labelledby="other-heading" data-testid="other">
                <h2 id="other-heading">{{ otherStateHeading(notice) }}</h2>
                <dl class="facts">
                    <dt>Von</dt>
                    <dd>
                        <template v-if="notice.other.user === null">–</template>
                        <template v-else
                            >{{ notice.other.user.name
                            }}<span v-if="notice.other.user.email !== null" class="email">{{
                                notice.other.user.email
                            }}</span></template
                        >
                    </dd>
                    <dt>Erfasst</dt>
                    <dd>{{ eventTimeText(notice.other.occurredAt, now) }}</dd>
                    <dt>Standort</dt>
                    <dd>{{ notice.other.location.path }}</dd>
                    <dt>Status</dt>
                    <dd>
                        <StatusBadge
                            :status="notice.other.status.value"
                            :label="notice.other.status.label"
                        />
                    </dd>
                </dl>
                <a
                    v-if="otherMail !== null && notice.other.user !== null"
                    class="btn mail"
                    :href="otherMail"
                    :aria-label="`Rückfrage per E-Mail an ${notice.other.user.name}`"
                    >Rückfrage per E-Mail</a
                >
            </section>

            <section class="card state" aria-labelledby="now-heading" data-testid="device-now">
                <h2 id="now-heading">Gerät jetzt</h2>
                <template v-if="device !== null">
                    <span class="now-location">{{ device.location.path }}</span>
                    <StatusBadge :status="device.status" :label="device.statusLabel" />
                    <RouterLink
                        v-if="device.status !== 'retired'"
                        class="btn btn-accent"
                        :to="{ name: 'device', params: { id: device.id } }"
                    >
                        Umbuchen / Status ändern
                    </RouterLink>
                    <p v-else class="hint">
                        Das Gerät ist ausgesondert; im Gerätedetail lässt es sich wieder aufnehmen.
                    </p>
                </template>
                <ErrorNotice v-else-if="deviceError !== null" :error="deviceError" />
                <p v-else class="hint" role="status">Wird geladen …</p>
            </section>
        </div>

        <div class="actions">
            <button
                v-if="notice.status === 'open'"
                class="btn btn-accent"
                type="button"
                :disabled="busy"
                @click="openResolve"
            >
                Erledigen …
            </button>
            <button v-else class="btn" type="button" :disabled="busy" @click="reopen">
                Wieder öffnen
            </button>
        </div>

        <section class="section" aria-labelledby="history-heading">
            <h2 id="history-heading" class="section-heading">Verlauf des Hinweises</h2>
            <p v-if="notice.history.length === 0" class="hint">
                Noch nicht bearbeitet (offen seit {{ dateTimeText(notice.createdAt) }}).
            </p>
            <ol v-else class="history" data-testid="notice-history">
                <li v-for="entry in notice.history" :key="entry.id" class="history-entry">
                    <span class="history-head">
                        <strong>{{ entry.actionLabel }}</strong>
                        · {{ eventTimeText(entry.at, now) }} · von {{ entry.user.name }}
                    </span>
                    <p v-if="entry.note !== null" class="history-note">{{ entry.note }}</p>
                </li>
            </ol>
        </section>

        <FormDialog
            v-model:open="dialogOpen"
            :unsaved="unsaved"
            title="Hinweis erledigen"
            :description="`${notice.kindLabel} · ${notice.device.identifier}`"
            submit-label="Erledigen"
            :busy="busy"
            :can-submit="liveProblem === null"
            :error="dialogError"
            :return-focus="opener"
            @submit="resolve"
        >
            <label class="field">
                <span class="field-label">Notiz (freiwillig)</span>
                <textarea
                    v-model="note"
                    class="input note-input"
                    rows="4"
                    :aria-invalid="(noteError ?? liveProblem) !== null"
                    :aria-describedby="`${id}-note`"
                    @input="noteError = null"
                ></textarea>
                <p
                    v-if="(noteError ?? liveProblem) !== null"
                    :id="`${id}-note`"
                    class="field-error"
                >
                    {{ noteError ?? liveProblem }}
                </p>
                <p v-else :id="`${id}-note`" class="field-hint">
                    Zum Beispiel, was Sie geklärt oder korrigiert haben. Die Notiz steht im Verlauf
                    des Hinweises; er lässt sich später wieder öffnen.
                </p>
            </label>
        </FormDialog>
    </template>
</template>

<style scoped>
.head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px 14px;
}

.head h1 {
    overflow-wrap: anywhere;
}

.head-badge {
    font-size: var(--font-size-md);
}

.lead {
    margin: 0;
    color: var(--text-secondary);
    font-size: var(--font-size-lg);
    overflow-wrap: anywhere;
}

.explanation {
    margin: 0;
    max-width: 75ch;
}

.hint {
    margin: 0;
    color: var(--muted);
}

.retry {
    align-self: flex-start;
}

.states {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 16px;
}

.state h2 {
    font-size: var(--font-size-lg);
}

.facts {
    display: grid;
    grid-template-columns: max-content minmax(0, 1fr);
    gap: 6px 14px;
    margin: 0;
}

.facts dt {
    color: var(--muted);
}

.facts dd {
    margin: 0;
    overflow-wrap: anywhere;
}

.email {
    display: block;
    color: var(--text-secondary);
}

.comment {
    white-space: pre-wrap;
}

.mail {
    align-self: flex-start;
    text-decoration: none;
}

.now-location {
    font-size: 20px;
    font-weight: var(--font-weight-bold);
    overflow-wrap: anywhere;
}

.state .badge {
    align-self: flex-start;
}

.state .btn-accent {
    align-self: flex-start;
    text-decoration: none;
}

.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.section {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.section-heading {
    font-size: var(--font-size-lg);
    font-weight: var(--font-weight-bold);
}

.history {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin: 0;
    padding: 0;
    list-style: none;
}

.history-entry {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 10px 14px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface);
}

.history-note {
    margin: 0;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

.note-input {
    min-height: 96px;
    resize: vertical;
}

.field-hint {
    margin: 0;
    font-size: var(--font-size-sm);
    color: var(--muted);
}

/* Am PC die drei Stände nebeneinander. */
@media (min-width: 1100px) {
    .states {
        grid-template-columns: repeat(3, minmax(0, 1fr));
        align-items: start;
    }
}

@media (max-width: 767.98px) {
    .facts {
        grid-template-columns: minmax(0, 1fr);
        gap: 0;
    }

    .facts dd {
        margin-bottom: 8px;
    }

    .actions .btn,
    .state .btn {
        align-self: stretch;
        flex: 1 1 100%;
    }
}
</style>
