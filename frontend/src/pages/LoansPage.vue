<script setup lang="ts">
import { computed, onMounted, ref, shallowRef, useTemplateRef } from 'vue';
import type { ApiError } from '../api/client';
import type { DeviceListItem } from '../api/devices';
import {
    fetchLoans,
    renewReturnKey,
    revokeReturnKey,
    type LoanPlace,
    type LoanState,
    type ReturnPlace,
} from '../api/loans';
import { allows } from '../api/permissions';
import { currentPermissions } from '../auth/session';
import ConfirmDialog from '../components/ConfirmDialog.vue';
import ErrorNotice from '../components/ErrorNotice.vue';
import LendDialog from '../components/LendDialog.vue';
import QrCode from '../components/QrCode.vue';
import ReturnPoster from '../components/ReturnPoster.vue';
import StatusBadge from '../components/StatusBadge.vue';
import { processReturns } from '../data';
import { bulkCounts, bulkErrorText, bulkResultText, type BulkRun } from '../data/deviceBulk';
import { changeTimeText } from '../data/changeTime';
import { dueText, groupLoans, loansSummary, returnAddress, returnLink } from '../data/loans';
import { useNow } from '../runtime/now';
import { copyText } from '../runtime/share';
import { tenantState } from '../runtime/tenantState';
import { useUnsavedInput } from '../runtime/unsavedInput';

/**
 * Seite „Verleih“ (Phase 2, P2.5; Mockup „Verleih“): die offenen Verleihe nach Leihnehmer mit
 * Ansprechpartner und „Rückgabe bis“ (überfällig rot), und „Verleihen …“ für neue Verleihe. Die Rückgabe
 * bucht der Ablauf „Rückgabe“ (P2.6); wer eine Ausrüstung von Hand zurückbucht, beendet den Verleih.
 */
const canLend = computed(() => allows(currentPermissions.value, 'loans', 'write'));
const now = useNow();

const state = shallowRef<LoanState | null>(null);
const error = shallowRef<ApiError | null>(null);
const loading = ref(false);
const dialogOpen = ref(false);
const lendButton = useTemplateRef<HTMLButtonElement>('lendButton');
const resultText = ref<string | null>(null);
const resultOk = ref(true);
const failures = ref<{ id: number; identifier: string; text: string }[]>([]);

async function load(): Promise<void> {
    loading.value = true;
    const result = await fetchLoans();
    loading.value = false;
    if (result.ok) {
        state.value = result.data;
        error.value = null;
    } else {
        error.value = result.error;
    }
}

onMounted(() => {
    void load();
});

const groups = computed(() => groupLoans(state.value?.loans ?? []));
const lendPossible = computed(() => (state.value?.borrowers.length ?? 0) > 0);

// --- Rückgabe (P2.6): Aushang der Rückgabestellen und „Rückgaben bearbeiten“ ---

/** Frisch erzeugter Schlüssel: erscheint nur jetzt (in der Datenbank steht nur der Hash). */
const issued = shallowRef<{ place: ReturnPlace; key: string } | null>(null);
const keyError = shallowRef<ApiError | null>(null);
const keyBusy = ref(false);
const copied = ref<string | null>(null);
useUnsavedInput(() => issued.value !== null);

const tenantName = computed(() => tenantState.value.tenant?.name ?? 'Feuerwehr');
const logoUrl = computed(
    () => tenantState.value.tenant?.logoUrls?.['512'] ?? 'api/v1/app-icon/512',
);
const issuedLink = computed(() => (issued.value === null ? '' : returnLink(issued.value.key)));

const keyConfirmOpen = ref(false);
const keyConfirm = shallowRef<{ place: ReturnPlace; revoke: boolean } | null>(null);

function askKey(place: ReturnPlace, revoke: boolean): void {
    if (revoke || place.linkCreatedAt !== null) {
        keyConfirm.value = { place, revoke };
        keyConfirmOpen.value = true;
        return;
    }
    void renew(place);
}

async function renew(place: ReturnPlace): Promise<void> {
    keyBusy.value = true;
    keyError.value = null;
    copied.value = null;
    const result = await renewReturnKey(place.id);
    keyBusy.value = false;
    if (result.ok) {
        issued.value = { place, key: result.data };
        await load();
    } else {
        keyError.value = result.error;
    }
}

async function confirmKey(): Promise<void> {
    const target = keyConfirm.value;
    if (target === null) {
        return;
    }
    if (!target.revoke) {
        await renew(target.place);
        return;
    }
    keyBusy.value = true;
    keyError.value = null;
    const result = await revokeReturnKey(target.place.id);
    keyBusy.value = false;
    if (result.ok) {
        issued.value = null;
        await load();
    } else {
        keyError.value = result.error;
    }
}

const keyConfirmTitle = computed(() =>
    keyConfirm.value?.revoke === true
        ? `Aushang für „${keyConfirm.value.place.name}“ ungültig machen?`
        : `Neuen Aushang für „${keyConfirm.value?.place.name ?? ''}“ erzeugen?`,
);
const keyConfirmText = computed(() =>
    keyConfirm.value?.revoke === true
        ? 'Der QR-Code des Aushangs funktioniert danach nicht mehr.'
        : 'Der bisherige Aushang gilt danach nicht mehr. Der neue Link erscheint nur einmal.',
);

async function copyLink(): Promise<void> {
    copied.value = (await copyText(issuedLink.value))
        ? 'Link kopiert.'
        : 'Kopieren geht hier nicht. Bitte den Link markieren und kopieren.';
}

function closeIssued(): void {
    issued.value = null;
    copied.value = null;
}

const pickedReturns = ref<number[]>([]);
const processBusy = ref(false);
const processProgress = ref('');
const processConfirmOpen = ref(false);
const processKind = ref<'to-collection' | 'to-store'>('to-store');
useUnsavedInput(() => processBusy.value);

const inReturns = computed(() => state.value?.inReturns ?? []);
const pickedDevices = computed(() =>
    inReturns.value.filter((device) => pickedReturns.value.includes(device.id)),
);
const allReturnsPicked = computed(
    () => inReturns.value.length > 0 && pickedDevices.value.length === inReturns.value.length,
);

function toggleReturn(id: number): void {
    pickedReturns.value = pickedReturns.value.includes(id)
        ? pickedReturns.value.filter((entry) => entry !== id)
        : [...pickedReturns.value, id];
}

function toggleAllReturns(): void {
    pickedReturns.value = allReturnsPicked.value ? [] : inReturns.value.map((device) => device.id);
}

function askProcess(kind: 'to-collection' | 'to-store'): void {
    processKind.value = kind;
    processConfirmOpen.value = true;
}

const processTitle = computed(() => {
    const count = pickedDevices.value.length;
    const what = count === 1 ? '1 Ausrüstung' : `${String(count)} Ausrüstungen`;
    return processKind.value === 'to-store'
        ? `${what} ins Lager bringen?`
        : `${what} in die Sammelstelle bringen?`;
});
const processText = computed(() => {
    const they = pickedDevices.value.length === 1 ? 'Sie steht' : 'Sie stehen';
    return processKind.value === 'to-store'
        ? `${they} dann im Standard-Lager mit dem Status „einsatzbereit“.`
        : 'Benutzte kommen in die Sammelstelle für Benutzte, defekte in die für Defekte; der Status bleibt.';
});

function showRun(text: string, run: BulkRun, devices: readonly DeviceListItem[] = []): void {
    const counts = bulkCounts(run);
    resultText.value = text;
    resultOk.value = counts.errors === 0 && run.error === null && run.unsent.length === 0;
    failures.value = run.results
        .filter((result) => result.outcome === 'error')
        .map((result) => ({
            id: result.id,
            identifier:
                result.device?.identifier ??
                devices.find((device) => device.id === result.id)?.identifier ??
                `#${String(result.id)}`,
            text: bulkErrorText(result),
        }));
}

async function runProcess(): Promise<void> {
    const devices = pickedDevices.value;
    if (devices.length === 0 || processBusy.value) {
        return;
    }
    processBusy.value = true;
    resultText.value = null;
    failures.value = [];
    const action = processKind.value;
    const run = await processReturns(action, devices, (done, total) => {
        processProgress.value = `${String(done)} von ${String(total)} gesendet …`;
    });
    processBusy.value = false;
    processProgress.value = '';
    showRun(bulkResultText(action, run), run, devices);
    const done = new Set(
        run.results.filter((result) => result.outcome !== 'error').map((result) => result.id),
    );
    pickedReturns.value = pickedReturns.value.filter((id) => !done.has(id));
    void load();
}

function onDone(run: BulkRun, borrower: LoanPlace | null): void {
    const target = borrower === null ? '' : ` an „${borrower.name}“`;
    showRun(`${bulkResultText('lend', run).replace(/\.$/, '')}${target}.`, run);
    void load();
}

function printPoster(): void {
    window.print();
}
</script>

<template>
    <div class="page-head">
        <h1>Verleih</h1>
        <button
            v-if="canLend"
            ref="lendButton"
            class="btn btn-primary"
            type="button"
            data-testid="lend-open"
            :disabled="!lendPossible"
            @click="dialogOpen = true"
        >
            Verleihen …
        </button>
    </div>

    <ErrorNotice v-if="error !== null" :error="error">
        <button class="btn" type="button" :disabled="loading" @click="load">
            Erneut versuchen
        </button>
    </ErrorNotice>
    <p v-else-if="state === null" class="hint" role="status">Wird geladen …</p>

    <p
        v-if="resultText !== null"
        class="notice"
        :class="resultOk ? 'notice-success' : 'notice-error'"
        role="status"
        data-testid="loan-result"
    >
        {{ resultText }}
    </p>
    <ul v-if="failures.length > 0" class="failures" data-testid="loan-failures">
        <li v-for="failure in failures" :key="failure.id">
            <strong>{{ failure.identifier }}</strong
            >: {{ failure.text }}
        </li>
    </ul>

    <template v-if="state !== null">
        <p class="summary" data-testid="loan-summary">{{ loansSummary(state) }}</p>
        <p v-if="canLend && !lendPossible" class="hint" data-testid="no-borrower">
            Es gibt noch keinen Leihnehmer. Der Admin vergibt die Aufgabe „{{
                state.borrowerLabel
            }}“ bei den Standorten.
        </p>

        <section
            v-for="group in groups"
            :key="group.borrower.id"
            class="group"
            data-testid="loan-group"
            :aria-labelledby="`group-${String(group.borrower.id)}`"
        >
            <h2 :id="`group-${String(group.borrower.id)}`">
                {{ group.borrower.name }}
                <span class="count">{{ group.loans.length }}</span>
                <span v-if="group.overdue > 0" class="badge badge-defective">
                    {{ group.overdue }} überfällig
                </span>
            </h2>
            <ul class="loans">
                <li v-for="loan in group.loans" :key="loan.id" class="loan" data-testid="loan-row">
                    <RouterLink
                        class="identifier"
                        :to="{ name: 'device-details', params: { id: loan.device.id } }"
                        >{{ loan.device.identifier }}</RouterLink
                    >
                    <span class="type">{{ loan.device.deviceType.name }}</span>
                    <StatusBadge :status="loan.device.status" :label="loan.device.statusLabel" />
                    <span class="due" :class="{ overdue: loan.overdue }" data-testid="loan-due">{{
                        dueText(loan)
                    }}</span>
                    <span v-if="loan.contact !== null" class="contact">{{ loan.contact }}</span>
                    <span class="since"
                        >verliehen {{ changeTimeText(loan.lentAt, now) }} von
                        {{ loan.lentBy }}</span
                    >
                    <span v-if="loan.comment !== null" class="comment">{{ loan.comment }}</span>
                </li>
            </ul>
        </section>
        <p v-if="state.loans.length < state.open" class="hint">
            Angezeigt werden die ersten {{ state.loans.length }} von {{ state.open }}.
        </p>

        <section
            v-if="state.returns.length > 0"
            class="group"
            data-testid="returns-section"
            aria-labelledby="returns-title"
        >
            <h2 id="returns-title">{{ state.returnsLabel }}</h2>
            <p class="hint">
                Wer verliehene Ausrüstung zurückbringt, scannt den QR-Code des Aushangs und gibt sie
                ohne Anmeldung zurück. Sie steht dann hier, bis sie weiterbewegt wird.
            </p>
            <ErrorNotice v-if="keyError !== null" :error="keyError" />
            <ul class="loans">
                <li
                    v-for="place in state.returns"
                    :key="place.id"
                    class="loan"
                    data-testid="return-place"
                >
                    <strong class="identifier">{{ place.name }}</strong>
                    <span class="since" data-testid="return-link-state">{{
                        place.linkCreatedAt === null
                            ? 'Noch kein Aushang'
                            : `Aushang gültig seit ${changeTimeText(place.linkCreatedAt, now)}`
                    }}</span>
                    <template v-if="canLend">
                        <button
                            class="btn"
                            type="button"
                            data-testid="renew-key"
                            :disabled="keyBusy"
                            @click="askKey(place, false)"
                        >
                            {{
                                place.linkCreatedAt === null
                                    ? 'Aushang erzeugen …'
                                    : 'Aushang erneuern …'
                            }}
                        </button>
                        <button
                            v-if="place.linkCreatedAt !== null"
                            class="btn"
                            type="button"
                            data-testid="revoke-key"
                            :disabled="keyBusy"
                            @click="askKey(place, true)"
                        >
                            Widerrufen …
                        </button>
                    </template>
                </li>
            </ul>

            <div v-if="issued !== null" class="issued" data-testid="issued-key">
                <p class="notice notice-info">
                    Der Link für „{{ issued.place.name }}“ erscheint nur jetzt. Drucken Sie den
                    Aushang oder kopieren Sie den Link, bevor Sie diese Ansicht schließen; sonst
                    erzeugen Sie einfach einen neuen.
                </p>
                <div class="issued-body">
                    <div class="issued-qr">
                        <QrCode :value="issuedLink" label="QR-Code mit dem Link zur Rückgabe" />
                    </div>
                    <div class="issued-text">
                        <p class="issued-link" data-testid="issued-link">{{ issuedLink }}</p>
                        <div class="actions">
                            <button class="btn btn-primary" type="button" @click="printPoster">
                                Aushang drucken
                            </button>
                            <button class="btn" type="button" @click="copyLink">
                                Link kopieren
                            </button>
                            <button class="btn" type="button" @click="closeIssued">
                                Schließen
                            </button>
                        </div>
                        <p v-if="copied !== null" class="hint" role="status">{{ copied }}</p>
                    </div>
                </div>
                <ReturnPoster
                    :tenant-name="tenantName"
                    :logo-url="logoUrl"
                    :place-name="issued.place.name"
                    :link="issuedLink"
                    :address="returnAddress()"
                />
            </div>
        </section>

        <section
            v-if="state.returns.length > 0"
            class="group"
            data-testid="in-returns"
            aria-labelledby="in-returns-title"
        >
            <h2 id="in-returns-title">
                In der Rückgabe
                <span class="count">{{ inReturns.length }}</span>
            </h2>
            <p v-if="inReturns.length === 0" class="hint">Zurzeit steht nichts in der Rückgabe.</p>
            <template v-else>
                <div v-if="canLend" class="actions">
                    <button
                        class="btn btn-primary"
                        type="button"
                        data-testid="to-store"
                        :disabled="processBusy || pickedDevices.length === 0"
                        @click="askProcess('to-store')"
                    >
                        In Ordnung ins Lager …
                    </button>
                    <button
                        class="btn"
                        type="button"
                        data-testid="to-collection"
                        :disabled="processBusy || pickedDevices.length === 0"
                        @click="askProcess('to-collection')"
                    >
                        Benutzte und Defekte in die Sammelstelle …
                    </button>
                    <span v-if="processProgress !== ''" class="hint" role="status">{{
                        processProgress
                    }}</span>
                </div>
                <ul class="loans">
                    <li v-if="canLend" class="loan">
                        <label class="check">
                            <input
                                type="checkbox"
                                :checked="allReturnsPicked"
                                :disabled="processBusy"
                                aria-label="Alle Ausrüstungen in der Rückgabe auswählen"
                                @change="toggleAllReturns"
                            />
                            Alle auswählen
                        </label>
                    </li>
                    <li
                        v-for="device in inReturns"
                        :key="device.id"
                        class="loan"
                        data-testid="return-device"
                    >
                        <input
                            v-if="canLend"
                            type="checkbox"
                            :checked="pickedReturns.includes(device.id)"
                            :disabled="processBusy"
                            :aria-label="`${device.identifier} auswählen`"
                            @change="toggleReturn(device.id)"
                        />
                        <RouterLink
                            class="identifier"
                            :to="{ name: 'device-details', params: { id: device.id } }"
                            >{{ device.identifier }}</RouterLink
                        >
                        <span class="type">{{ device.deviceType.name }}</span>
                        <StatusBadge :status="device.status" :label="device.statusLabel" />
                        <span class="since">{{
                            device.stateChangedAt === null
                                ? '–'
                                : changeTimeText(device.stateChangedAt, now)
                        }}</span>
                    </li>
                </ul>
            </template>
        </section>
    </template>

    <ConfirmDialog
        v-model:open="keyConfirmOpen"
        :title="keyConfirmTitle"
        :description="keyConfirmText"
        :confirm-label="keyConfirm?.revoke === true ? 'Ungültig machen' : 'Neuen Aushang erzeugen'"
        @confirm="confirmKey"
    />
    <ConfirmDialog
        v-model:open="processConfirmOpen"
        :title="processTitle"
        :description="processText"
        confirm-label="Bringen"
        @confirm="runProcess"
    />
    <LendDialog
        v-if="state !== null"
        v-model:open="dialogOpen"
        :borrowers="state.borrowers"
        :borrower-label="state.borrowerLabel"
        :return-focus="lendButton"
        @done="onDone"
    />
</template>

<style scoped>
.hint {
    margin: 0 0 12px;
    color: var(--muted);
}

.summary {
    margin: 0 0 16px;
    font-weight: var(--font-weight-semibold);
}

.group {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-bottom: 24px;
    padding: 16px;
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    background: var(--surface);
}

.group h2 {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 6px 10px;
    margin: 0;
}

.count {
    color: var(--muted);
    font-weight: var(--font-weight-regular);
}

.loans {
    display: flex;
    flex-direction: column;
    margin: 0;
    padding: 0;
    list-style: none;
}

.loan {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 4px 14px;
    min-height: var(--touch-min);
    padding: 6px 4px;
    border-top: 1px solid var(--border-subtle);
}

.identifier {
    min-width: 5em;
    font-weight: var(--font-weight-bold);
}

.type {
    min-width: 9em;
}

.due.overdue {
    color: var(--status-defective-fg);
    font-weight: var(--font-weight-semibold);
}

.contact,
.since,
.comment {
    color: var(--muted);
}

.actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
}

.issued {
    display: flex;
    flex-direction: column;
    gap: 12px;
    padding-top: 12px;
    border-top: 1px solid var(--border-subtle);
}

.issued-body {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
}

.issued-qr {
    width: 220px;
    max-width: 100%;
}

.issued-text {
    display: flex;
    flex: 1 1 260px;
    flex-direction: column;
    gap: 10px;
}

.issued-link {
    margin: 0;
    font-family: var(--font-mono, monospace);
    overflow-wrap: anywhere;
}

.failures {
    margin: 0 0 16px;
    padding-left: 20px;
}
</style>
