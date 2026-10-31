<script setup lang="ts">
import { computed, ref, shallowRef, useId, watch } from 'vue';
import type { ApiError } from '../api/client';
import type { CreatedInvitationCode, InvitationCode } from '../api/invitationCodes';
import { createInvitationCode, invitationCodeStore, revokeInvitationCode } from '../data';
import {
    codeTitle,
    dateText,
    dateTimeText,
    expiryPreview,
    invitationProblems,
    invitationRequest,
    isUsable,
    MAX_USES,
    MAX_VALID_DAYS,
    newInvitationForm,
    registerAddress,
    registerLink,
    shareContent,
    usesText,
    visibleCodes,
    type InvitationForm,
} from '../data/invitations';
import { copyText, shareLink } from '../runtime/share';
import { tenantState } from '../runtime/tenantState';
import { useUnsavedInput } from '../runtime/unsavedInput';
import ConfirmDialog from './ConfirmDialog.vue';
import ErrorNotice from './ErrorNotice.vue';
import FormDialog from './FormDialog.vue';
import InvitationPoster from './InvitationPoster.vue';
import QrCode from './QrCode.vue';

/**
 * Reiter „Einladungscodes“ der Benutzerverwaltung (KONZEPT.md "Admin-Konsole – Entscheidungen",
 * 30): Codes der Stadt mit Status und Nutzungen, „Neuer Einladungscode“ und Widerrufen. Ein neuer
 * Code erscheint genau einmal (der Server kennt danach nur den Hash): groß, mit QR-Code, Kopieren,
 * „Link teilen“ und „Aushang drucken“. Solange er zu sehen ist, zählt er als ungespeicherte Eingabe
 * (ein Update lädt die Seite dann nicht von selbst neu).
 */
const props = defineProps<{
    /** Reiter sichtbar: erst dann laden (die Konten brauchen die Codes nicht). */
    active: boolean;
}>();

const id = useId();
const state = computed(() => invitationCodeStore.state);
const codes = computed(() => state.value.codes ?? []);
const showInactive = ref(false);
const rows = computed(() => visibleCodes(codes.value, showInactive.value));
const inactiveCount = computed(() => codes.value.filter((code) => !isUsable(code)).length);

watch(
    () => props.active,
    (active) => {
        if (active) {
            void invitationCodeStore.refresh('open');
        }
    },
    { immediate: true },
);

const busy = ref(false);
const success = ref<string | null>(null);
const pageError = ref<ApiError | null>(null);
const opener = ref<HTMLElement | null>(null);

function startAction(event: Event): void {
    opener.value = event.currentTarget instanceof HTMLElement ? event.currentTarget : null;
    success.value = null;
    pageError.value = null;
}

// Fenster „Neuer Einladungscode“.
const dialogOpen = ref(false);
const form = ref<InvitationForm>(newInvitationForm());
const touched = ref(false);
const dialogError = ref<ApiError | null>(null);
const problems = computed(() => invitationProblems(form.value));
const shown = computed(() => (touched.value ? problems.value : {}));
const preview = computed(() => expiryPreview(form.value, new Date()));

function openDialog(event: Event): void {
    startAction(event);
    form.value = newInvitationForm();
    touched.value = false;
    dialogError.value = null;
    dialogOpen.value = true;
}

function setField(field: keyof InvitationForm, value: string): void {
    form.value = { ...form.value, [field]: value };
    touched.value = true;
}

/** Der neue Code, solange er zu sehen ist (nur in dieser Antwort im Klartext). */
const fresh = shallowRef<CreatedInvitationCode | null>(null);
const feedback = ref<{ readonly ok: boolean; readonly text: string } | null>(null);

/** Ungespeicherte Eingaben im Fenster „Neuer Einladungscode“ (auch für FormDialog). */
const dialogUnsaved = computed(() => {
    const initial = newInvitationForm();
    return (
        dialogOpen.value &&
        (form.value.label !== initial.label ||
            form.value.validDays !== initial.validDays ||
            form.value.maxUses !== initial.maxUses)
    );
});
useUnsavedInput(() => fresh.value !== null || dialogUnsaved.value);

async function submit(): Promise<void> {
    touched.value = true;
    dialogError.value = null;
    const request = invitationRequest(form.value);
    if (request === null || busy.value) {
        return;
    }
    busy.value = true;
    const result = await createInvitationCode(request);
    busy.value = false;
    if (!result.ok) {
        dialogError.value = result.error;
        return;
    }
    fresh.value = result.data;
    feedback.value = null;
    dialogOpen.value = false;
}

const tenantName = computed(() => tenantState.value.tenant?.name ?? 'Feuerwehr');
const logoUrl = computed(
    () => tenantState.value.tenant?.logoUrls?.['512'] ?? 'api/v1/app-icon/512',
);
const link = computed(() => (fresh.value === null ? '' : registerLink(fresh.value.code)));
const address = computed(() => registerAddress());
const freshExpires = computed(() =>
    fresh.value === null ? '' : dateTimeText(fresh.value.invitationCode.expiresAt),
);

async function copyCode(): Promise<void> {
    if (fresh.value === null) {
        return;
    }
    feedback.value = (await copyText(fresh.value.code))
        ? { ok: true, text: 'Code kopiert.' }
        : { ok: false, text: 'Kopieren ist hier nicht möglich. Bitte den Code abschreiben.' };
}

async function share(): Promise<void> {
    const current = fresh.value;
    if (current === null) {
        return;
    }
    const outcome = await shareLink(
        shareContent(tenantName.value, current.code, current.invitationCode.expiresAt),
    );
    switch (outcome) {
        case 'shared':
            feedback.value = { ok: true, text: 'Link geteilt.' };
            break;
        case 'copied':
            feedback.value = {
                ok: true,
                text: 'Link kopiert. Sie können ihn jetzt z. B. in eine Nachricht einfügen.',
            };
            break;
        case 'cancelled':
            feedback.value = null;
            break;
        case 'failed':
            feedback.value = {
                ok: false,
                text: 'Teilen und Kopieren sind hier nicht möglich. Bitte den Link oben markieren und kopieren.',
            };
            break;
    }
}

function printPoster(): void {
    window.print();
}

function closeFresh(): void {
    fresh.value = null;
    feedback.value = null;
}

// Widerrufen mit Rückfrage.
const confirmOpen = ref(false);
const confirmTarget = shallowRef<InvitationCode | null>(null);

function askRevoke(code: InvitationCode, event: Event): void {
    startAction(event);
    confirmTarget.value = code;
    confirmOpen.value = true;
}

async function revoke(): Promise<void> {
    const code = confirmTarget.value;
    if (code === null) {
        return;
    }
    busy.value = true;
    const result = await revokeInvitationCode(code.id);
    busy.value = false;
    if (!result.ok) {
        pageError.value = result.error;
        return;
    }
    if (fresh.value?.invitationCode.id === code.id) {
        closeFresh();
    }
    success.value = `${codeTitle(code)} ist widerrufen. Bereits registrierte Konten bleiben.`;
}

function retry(): void {
    void invitationCodeStore.refresh('changed');
}
</script>

<template>
    <div class="codes">
        <div class="codes-head">
            <p class="hint">
                Mit einem Einladungscode registrieren sich neue Mitglieder selbst; danach schalten
                Sie jede Registrierung frei.
            </p>
            <button class="btn btn-accent" type="button" :disabled="busy" @click="openDialog">
                Neuer Einladungscode
            </button>
        </div>

        <section
            v-if="fresh !== null"
            class="fresh"
            aria-labelledby="fresh-heading"
            data-testid="fresh-code"
        >
            <h2 id="fresh-heading">
                Neuer Einladungscode{{
                    fresh.invitationCode.label === null ? '' : ` „${fresh.invitationCode.label}“`
                }}
            </h2>
            <p class="notice notice-info">
                Der Code erscheint nur jetzt. Drucken oder teilen Sie ihn, bevor Sie diese Ansicht
                schließen; sonst erzeugen Sie einfach einen neuen.
            </p>
            <div class="fresh-body">
                <div class="fresh-qr">
                    <QrCode :value="link" label="QR-Code mit dem Link zur Registrierung" />
                </div>
                <div class="fresh-text">
                    <p class="fresh-code" data-testid="fresh-code-value">{{ fresh.code }}</p>
                    <p class="hint">
                        Gültig bis {{ freshExpires }} Uhr ·
                        {{ usesText(fresh.invitationCode) }}
                    </p>
                    <p class="fresh-link" data-testid="fresh-link">{{ link }}</p>
                    <div class="fresh-actions">
                        <button class="btn btn-accent" type="button" @click="printPoster">
                            Aushang drucken
                        </button>
                        <button class="btn" type="button" @click="share">Link teilen</button>
                        <button class="btn" type="button" @click="copyCode">Code kopieren</button>
                        <button class="btn" type="button" @click="closeFresh">Schließen</button>
                    </div>
                    <p
                        v-if="feedback !== null"
                        class="feedback"
                        :class="feedback.ok ? 'feedback-ok' : 'feedback-error'"
                        role="status"
                        data-testid="share-feedback"
                    >
                        {{ feedback.text }}
                    </p>
                </div>
            </div>
            <InvitationPoster
                v-if="active"
                :tenant-name="tenantName"
                :logo-url="logoUrl"
                :code="fresh.code"
                :link="link"
                :address="address"
                :expires-text="freshExpires"
            />
        </section>

        <p
            v-if="success !== null"
            class="notice notice-success"
            role="status"
            data-testid="code-success"
        >
            {{ success }}
        </p>
        <ErrorNotice v-if="pageError !== null" :error="pageError" />
        <ErrorNotice v-if="state.error !== null" :error="state.error">
            <button class="btn retry" type="button" :disabled="state.loading" @click="retry">
                Erneut versuchen
            </button>
        </ErrorNotice>

        <p v-if="state.codes === null && state.error === null" class="hint" role="status">
            Wird geladen …
        </p>
        <p v-else-if="state.codes !== null && codes.length === 0" class="hint">
            Noch keine Einladungscodes.
        </p>

        <label v-if="inactiveCount > 0" class="check">
            <input v-model="showInactive" type="checkbox" />
            Abgelaufene, aufgebrauchte und widerrufene anzeigen ({{ inactiveCount }})
        </label>

        <p v-if="codes.length > 0 && rows.length === 0" class="hint">Kein Code ist mehr aktiv.</p>

        <div v-if="rows.length > 0" class="table-card">
            <table class="list-table" data-testid="code-table">
                <thead>
                    <tr>
                        <th scope="col">Bezeichnung</th>
                        <th scope="col">Status</th>
                        <th scope="col">Genutzt</th>
                        <th scope="col">Gültig bis</th>
                        <th scope="col">Angelegt</th>
                        <th scope="col" class="col-actions">
                            <span class="visually-hidden">Aktionen</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="code in rows"
                        :key="code.id"
                        :class="{ 'row-inactive': !isUsable(code) }"
                        :data-testid="`code-${String(code.id)}`"
                    >
                        <td class="col-label">
                            <strong>{{ codeTitle(code) }}</strong>
                        </td>
                        <td class="col-status">
                            <span :class="{ 'status-active': isUsable(code) }">{{
                                code.statusLabel
                            }}</span>
                        </td>
                        <td class="col-uses">{{ usesText(code) }}</td>
                        <td class="col-expires">
                            <span class="cell-label">Gültig bis </span
                            >{{ dateTimeText(code.expiresAt) }}
                        </td>
                        <td class="col-created">
                            <span class="cell-label">Angelegt </span
                            >{{ dateText(code.createdAt) }} · {{ code.createdBy.label }}
                        </td>
                        <td class="col-actions">
                            <button
                                v-if="isUsable(code)"
                                class="btn small-btn"
                                type="button"
                                :disabled="busy"
                                :aria-label="`${codeTitle(code)} widerrufen`"
                                @click="askRevoke(code, $event)"
                            >
                                Widerrufen …
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <FormDialog
        v-model:open="dialogOpen"
        :unsaved="dialogUnsaved"
        title="Neuer Einladungscode"
        description="Der Code ist nur direkt nach dem Erzeugen zu sehen. Danach drucken Sie einen Aushang oder teilen den Link."
        submit-label="Code erzeugen"
        :busy="busy"
        :can-submit="!touched || Object.keys(problems).length === 0"
        :error="dialogError"
        :return-focus="opener"
        @submit="submit"
    >
        <label class="field">
            <span class="field-label">Bezeichnung (freiwillig)</span>
            <input
                class="input"
                type="text"
                autocomplete="off"
                :value="form.label"
                :aria-invalid="shown.label !== undefined"
                :aria-describedby="`${id}-label-hint`"
                placeholder="z. B. Dienstabend November"
                @input="setField('label', ($event.target as HTMLInputElement).value)"
            />
            <p v-if="shown.label !== undefined" :id="`${id}-label-hint`" class="field-error">
                {{ shown.label }}
            </p>
            <p v-else :id="`${id}-label-hint`" class="field-hint">
                Nur für Sie, steht nicht auf dem Aushang.
            </p>
        </label>
        <div class="field-row">
            <label class="field">
                <span class="field-label">Gültig (Tage)</span>
                <input
                    class="input"
                    type="text"
                    inputmode="numeric"
                    autocomplete="off"
                    :value="form.validDays"
                    :aria-invalid="shown.validDays !== undefined"
                    :aria-describedby="`${id}-days-hint`"
                    @input="setField('validDays', ($event.target as HTMLInputElement).value)"
                />
                <p v-if="shown.validDays !== undefined" :id="`${id}-days-hint`" class="field-error">
                    {{ shown.validDays }}
                </p>
                <p v-else :id="`${id}-days-hint`" class="field-hint">
                    1 bis {{ MAX_VALID_DAYS
                    }}<template v-if="preview !== null">, bis etwa {{ preview }} Uhr</template>
                </p>
            </label>
            <label class="field">
                <span class="field-label">Registrierungen höchstens</span>
                <input
                    class="input"
                    type="text"
                    inputmode="numeric"
                    autocomplete="off"
                    :value="form.maxUses"
                    :aria-invalid="shown.maxUses !== undefined"
                    :aria-describedby="`${id}-uses-hint`"
                    @input="setField('maxUses', ($event.target as HTMLInputElement).value)"
                />
                <p v-if="shown.maxUses !== undefined" :id="`${id}-uses-hint`" class="field-error">
                    {{ shown.maxUses }}
                </p>
                <p v-else :id="`${id}-uses-hint`" class="field-hint">1 bis {{ MAX_USES }}</p>
            </label>
        </div>
    </FormDialog>

    <ConfirmDialog
        v-model:open="confirmOpen"
        :title="`${confirmTarget === null ? '' : codeTitle(confirmTarget)} widerrufen?`"
        description="Mit diesem Code kann sich danach niemand mehr registrieren, auch nicht über einen gedruckten Aushang. Bereits registrierte Konten bleiben."
        confirm-label="Widerrufen"
        @confirm="revoke"
    />
</template>

<style scoped>
.codes {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.codes-head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.hint {
    margin: 0;
    color: var(--muted);
    font-size: var(--font-size-sm);
}

.retry {
    align-self: flex-start;
}

/* Der neue Code: einmal sichtbar, groß (KONZEPT.md, "Bereiche des Admins", Benutzer). */
.fresh {
    display: flex;
    flex-direction: column;
    gap: 12px;
    padding: 16px;
    border: 2px solid var(--line);
    border-radius: var(--radius-lg);
    background: var(--surface);
}

.fresh h2 {
    margin: 0;
}

.fresh-body {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    gap: 20px;
}

.fresh-qr {
    flex: none;
    width: 200px;
    padding: 8px;
    border-radius: var(--radius-md);
    background: var(--paper);
}

.fresh-text {
    display: flex;
    flex: 1 1 280px;
    flex-direction: column;
    gap: 8px;
    min-width: 0;
}

.fresh-text p {
    margin: 0;
}

.fresh-code {
    /* Am Handy kleiner, damit der Code in einer Zeile bleibt. */
    font-size: min(var(--font-size-kpi), 7.5vw);
    font-weight: var(--font-weight-bold);
    letter-spacing: 0.06em;
    font-variant-numeric: tabular-nums;
    overflow-wrap: anywhere;
    user-select: all;
}

.fresh-link {
    color: var(--text-secondary);
    font-size: var(--font-size-sm);
    overflow-wrap: anywhere;
    user-select: all;
}

.fresh-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 4px;
}

.feedback {
    font-size: var(--font-size-sm);
    font-weight: var(--font-weight-semibold);
}

.feedback-ok {
    color: var(--success);
}

.feedback-error {
    color: var(--danger);
}

.table-card {
    overflow: hidden;
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    background: var(--surface);
}

.list-table {
    width: 100%;
    border-collapse: collapse;
}

.list-table th {
    padding: 12px 14px;
    background: var(--surface-hover);
    color: var(--text-secondary);
    font-size: var(--font-size-md);
    text-align: left;
    white-space: nowrap;
}

.list-table td {
    padding: 8px 14px;
    border-top: 1px solid var(--border-subtle);
    vertical-align: middle;
}

.list-table tbody tr:hover td {
    background: var(--surface-hover);
}

.list-table tbody tr:hover td:first-child {
    box-shadow: inset 4px 0 0 var(--line);
}

.col-label {
    overflow-wrap: anywhere;
}

.row-inactive .col-label,
.row-inactive .col-status {
    color: var(--muted);
}

.status-active {
    color: var(--success);
    font-weight: var(--font-weight-semibold);
}

.col-expires,
.col-uses {
    white-space: nowrap;
}

/* Beschriftung nur auf den Karten am Handy (am PC steht sie im Spaltenkopf). */
.cell-label {
    display: none;
}

.col-actions {
    width: 140px;
    text-align: right;
    white-space: nowrap;
}

.small-btn {
    min-height: var(--touch-min);
    padding: 0 12px;
}

.field-row {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
}

.field-row .field {
    flex: 1 1 160px;
}

.field-hint {
    margin: 0;
    font-size: var(--font-size-sm);
    color: var(--muted);
}

/* Am Handy: Karten statt Tabelle, Knöpfe volle Breite (KONZEPT.md, "Listen"). */
@media (max-width: 767.98px) {
    .fresh-qr {
        width: min(240px, 100%);
        margin: 0 auto;
    }

    .fresh-actions .btn,
    .codes-head .btn {
        flex: 1 1 100%;
    }

    .table-card {
        overflow: visible;
        border: 0;
        background: none;
    }

    .list-table thead {
        display: none;
    }

    .list-table,
    .list-table tbody {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .list-table tbody tr {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        align-items: center;
        gap: 2px 12px;
        padding: 10px 12px 10px 16px;
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        background: var(--surface);
    }

    .list-table td {
        padding: 0;
        border: 0;
        font-size: var(--font-size-sm);
    }

    .list-table tbody tr:hover td,
    .list-table tbody tr:hover td:first-child {
        background: none;
        box-shadow: none;
    }

    .list-table td:not(.col-actions) {
        grid-column: 1;
    }

    .col-label {
        font-size: var(--font-size-base);
    }

    .col-created {
        color: var(--muted);
    }

    .cell-label {
        display: inline;
    }

    .col-actions {
        grid-row: 1 / span 5;
        grid-column: 2;
        width: auto;
    }
}
</style>
