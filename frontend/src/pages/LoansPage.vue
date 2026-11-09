<script setup lang="ts">
import { computed, onMounted, ref, shallowRef, useTemplateRef } from 'vue';
import type { ApiError } from '../api/client';
import { fetchLoans, type LoanPlace, type LoanState } from '../api/loans';
import { allows } from '../api/permissions';
import { currentPermissions } from '../auth/session';
import ErrorNotice from '../components/ErrorNotice.vue';
import LendDialog from '../components/LendDialog.vue';
import StatusBadge from '../components/StatusBadge.vue';
import { bulkCounts, bulkErrorText, bulkResultText, type BulkRun } from '../data/deviceBulk';
import { dueText, groupLoans, loansSummary } from '../data/loans';
import { changeTimeText } from '../data/changeTime';
import { useNow } from '../runtime/now';

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

function onDone(run: BulkRun, borrower: LoanPlace | null): void {
    const counts = bulkCounts(run);
    const target = borrower === null ? '' : ` an „${borrower.name}“`;
    resultText.value = `${bulkResultText('lend', run).replace(/\.$/, '')}${target}.`;
    resultOk.value = counts.errors === 0 && run.error === null && run.unsent.length === 0;
    failures.value = run.results
        .filter((result) => result.outcome === 'error')
        .map((result) => ({
            id: result.id,
            identifier: result.device?.identifier ?? `#${String(result.id)}`,
            text: bulkErrorText(result),
        }));
    void load();
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
    </template>

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

.failures {
    margin: 0 0 16px;
    padding-left: 20px;
}
</style>
