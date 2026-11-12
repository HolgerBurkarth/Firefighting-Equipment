<script setup lang="ts">
import { computed, ref, useTemplateRef } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import {
    checkReturn,
    postReturn,
    type ReturnCondition,
    type ReturnDone,
    type ReturnFound,
} from '../api/loans';
import type { ApiError } from '../api/client';
import ErrorNotice from '../components/ErrorNotice.vue';
import { RETURN_CONDITIONS, returnProblem } from '../data/loans';
import HowToSteps from '../components/HowToSteps.vue';
import { RETURN_STEPS } from '../data/howTo';
import { takeFragmentParam } from '../runtime/fragment';
import { isTenantUnavailable, tenantState } from '../runtime/tenantState';
import { useUnsavedInput } from '../runtime/unsavedInput';

/**
 * Rückgabe ohne Anmeldung (Phase 2, P2.6; Mockup „Handy-Rückgabe“): Der QR-Code des Aushangs führt
 * hierher (`rueckgabe#schluessel=…`). Der Schlüssel erlaubt nur diese Rückgabe, sonst nichts: Nummer
 * eingeben (oder per Handscanner), prüfen, Name und Zustand angeben, zurückgeben. Die Seite gibt nichts
 * preis außer Nummer und Typ der verliehenen Ausrüstung. Ohne Verbindung geht sie nicht.
 */
const route = useRoute();
const router = useRouter();

const KEY_STORAGE = 'ff-return-key';

function storedKey(): string | null {
    try {
        return sessionStorage.getItem(KEY_STORAGE);
    } catch {
        return null;
    }
}

// Schlüssel aus dem Fragment; danach steht er nicht mehr in der Adresszeile, übersteht aber ein Neuladen
// (sessionStorage dieses Tabs).
const linkKey = takeFragmentParam(router, route, 'schluessel');
const key = ref<string | null>(linkKey ?? storedKey());
if (linkKey !== null) {
    try {
        sessionStorage.setItem(KEY_STORAGE, linkKey);
    } catch {
        // Nur für diesen Seitenaufruf.
    }
}

const code = ref('');
const name = ref('');
const condition = ref<ReturnCondition>('ok');
const comment = ref('');
const found = ref<ReturnFound | null>(null);
const done = ref<ReturnDone | null>(null);
const busy = ref(false);
const error = ref<ApiError | null>(null);
const problem = ref<string | null>(null);
const submitted = ref(false);
/** Der Link gilt nicht (mehr): Formular ausblenden. */
const keyInvalid = ref(false);

const codeInput = useTemplateRef<HTMLInputElement>('codeInput');
const nameInput = useTemplateRef<HTMLInputElement>('nameInput');

useUnsavedInput(() => done.value === null && (found.value !== null || name.value.trim() !== ''));

const formHidden = computed(() => isTenantUnavailable(tenantState.value.error));

function reset(): void {
    code.value = '';
    found.value = null;
    done.value = null;
    comment.value = '';
    condition.value = 'ok';
    submitted.value = false;
    error.value = null;
    problem.value = null;
    codeInput.value?.focus();
}

function onFailure(failure: ApiError): void {
    if (failure.code === 'invalid_request' && failure.message !== null) {
        problem.value = failure.message;
        keyInvalid.value = failure.message.includes('Rückgabe-Link');
        return;
    }
    error.value = failure;
}

async function onCheck(): Promise<void> {
    if (busy.value || key.value === null) {
        return;
    }
    error.value = null;
    problem.value = null;
    found.value = null;
    if (code.value.trim() === '') {
        problem.value = 'Bitte geben Sie die Nummer der Ausrüstung ein.';
        codeInput.value?.focus();
        return;
    }
    busy.value = true;
    const result = await checkReturn(key.value, code.value.trim());
    busy.value = false;
    if (result.ok) {
        found.value = result.data;
        nameInput.value?.focus();
        return;
    }
    onFailure(result.error);
}

async function onSubmit(): Promise<void> {
    if (busy.value || key.value === null) {
        return;
    }
    submitted.value = true;
    error.value = null;
    problem.value = returnProblem(code.value, name.value);
    if (problem.value !== null) {
        (name.value.trim() === '' ? nameInput : codeInput).value?.focus();
        return;
    }
    busy.value = true;
    const text = comment.value.trim().replace(/\s+/g, ' ');
    const result = await postReturn({
        key: key.value,
        code: code.value.trim(),
        name: name.value.trim(),
        condition: condition.value,
        ...(text !== '' && { comment: text }),
    });
    busy.value = false;
    if (result.ok) {
        done.value = result.data;
        found.value = null;
        return;
    }
    onFailure(result.error);
}
</script>

<template>
    <main class="return-page" data-testid="return-page">
        <h1>Ausrüstung zurückgeben</h1>

        <p v-if="formHidden" class="notice notice-error" role="alert">
            Diese Stadt ist nicht erreichbar. Bitte prüfen Sie den Aushang.
        </p>
        <template v-else-if="key === null || keyInvalid">
            <p class="notice notice-error" role="alert" data-testid="return-key-missing">
                {{
                    keyInvalid && problem !== null
                        ? problem
                        : 'Dieser Link ist unvollständig. Bitte scannen Sie den QR-Code des Aushangs noch einmal.'
                }}
            </p>
        </template>
        <template v-else>
            <section v-if="done !== null" class="card" data-testid="return-done" role="status">
                <h2>Danke!</h2>
                <p>
                    <strong>{{ done.identifier }}</strong> ({{ done.deviceType }}) ist zurückgegeben
                    und steht jetzt in „{{ done.location }}“.
                </p>
                <button
                    class="btn btn-primary"
                    type="button"
                    data-testid="return-next"
                    @click="reset"
                >
                    Weitere Ausrüstung zurückgeben
                </button>
            </section>
            <section v-else class="card" aria-label="So geht die Rückgabe">
                <HowToSteps :steps="RETURN_STEPS" variant="screen" />
            </section>
            <form v-if="done === null" class="card" novalidate @submit.prevent="onSubmit">
                <p class="hint">
                    Nummer der Ausrüstung eingeben (oder mit einem Handscanner einlesen), prüfen und
                    Ihren Namen angeben. Eine Anmeldung ist nicht nötig.
                </p>
                <div class="field">
                    <label class="field-label" for="return-code">Nummer der Ausrüstung</label>
                    <div class="row">
                        <input
                            id="return-code"
                            ref="codeInput"
                            v-model="code"
                            class="input"
                            type="text"
                            autocomplete="off"
                            autocapitalize="characters"
                            :disabled="busy"
                            data-testid="return-code"
                            @keydown.enter.prevent="onCheck"
                            @input="found = null"
                        />
                        <button
                            class="btn"
                            type="button"
                            :disabled="busy"
                            data-testid="return-check"
                            @click="onCheck"
                        >
                            Prüfen
                        </button>
                    </div>
                </div>

                <p v-if="found !== null" class="found" role="status" data-testid="return-found">
                    ✓ <strong>{{ found.identifier }}</strong> · {{ found.deviceType }} – wird in „{{
                        found.location
                    }}“ abgegeben.
                </p>

                <template v-if="found !== null">
                    <div class="field">
                        <label class="field-label" for="return-name">Ihr Name</label>
                        <input
                            id="return-name"
                            ref="nameInput"
                            v-model="name"
                            class="input"
                            type="text"
                            autocomplete="name"
                            maxlength="100"
                            :disabled="busy"
                            data-testid="return-name"
                            :aria-invalid="submitted && name.trim() === ''"
                        />
                    </div>
                    <fieldset class="field conditions">
                        <legend class="field-label">Zustand</legend>
                        <label v-for="entry in RETURN_CONDITIONS" :key="entry.value" class="check">
                            <input
                                v-model="condition"
                                type="radio"
                                name="return-condition"
                                :value="entry.value"
                                :disabled="busy"
                            />
                            {{ entry.label }}
                        </label>
                    </fieldset>
                    <div class="field">
                        <label class="field-label" for="return-comment">Hinweis (optional)</label>
                        <input
                            id="return-comment"
                            v-model="comment"
                            class="input"
                            type="text"
                            autocomplete="off"
                            maxlength="500"
                            :disabled="busy"
                        />
                    </div>
                    <button
                        class="btn btn-primary"
                        type="submit"
                        :disabled="busy"
                        data-testid="return-submit"
                    >
                        {{ busy ? 'Wird gesendet …' : 'Zurückgeben' }}
                    </button>
                </template>

                <p
                    v-if="problem !== null"
                    class="notice notice-error"
                    role="alert"
                    data-testid="return-problem"
                >
                    {{ problem }}
                </p>
                <ErrorNotice v-if="error !== null" :error="error" />
            </form>
        </template>
    </main>
</template>

<style scoped>
.return-page {
    display: flex;
    flex-direction: column;
    gap: 16px;
    box-sizing: border-box;
    width: 100%;
    max-width: 560px;
    margin: 0 auto;
    padding: 16px;
}

.card {
    display: flex;
    flex-direction: column;
    gap: 14px;
    padding: 16px;
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    background: var(--surface);
}

.hint {
    margin: 0;
    color: var(--muted);
}

.row {
    display: flex;
    gap: 8px;
}

.row .input {
    flex: 1 1 0;
    width: 0;
    min-width: 0;
}

.found {
    margin: 0;
    color: var(--status-ready-fg);
}

.conditions {
    display: flex;
    flex-wrap: wrap;
    gap: 6px 18px;
    border: 0;
}
</style>
