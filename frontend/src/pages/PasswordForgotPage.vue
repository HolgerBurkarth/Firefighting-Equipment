<script setup lang="ts">
import { Label } from 'reka-ui';
import { ref, useTemplateRef } from 'vue';
import type { ApiError } from '../api/client';
import { requestPasswordReset } from '../api/auth';
import ErrorNotice from '../components/ErrorNotice.vue';
import MailHint from '../components/MailHint.vue';
import { currentAppBase } from '../runtime/appBase';

const base = currentAppBase();
const email = ref('');
const emailError = ref<string | null>(null);
const busy = ref(false);
const error = ref<ApiError | null>(null);
const sentMessage = ref<string | null>(null);
const emailInput = useTemplateRef<HTMLInputElement>('emailInput');

async function onSubmit(): Promise<void> {
    if (busy.value) {
        return;
    }
    emailError.value =
        email.value.trim() === '' ? 'Bitte geben Sie Ihre E-Mail-Adresse ein.' : null;
    if (emailError.value !== null) {
        emailInput.value?.focus();
        return;
    }
    busy.value = true;
    error.value = null;
    const result = await requestPasswordReset(email.value.trim());
    busy.value = false;
    if (result.ok) {
        sentMessage.value = result.data.message;
    } else if (result.error.code === 'invalid_request') {
        // Schreibweise der Adresse ungültig: Meldung des Servers am Feld.
        emailError.value = result.error.message ?? 'Bitte prüfen Sie die E-Mail-Adresse.';
        emailInput.value?.focus();
    } else {
        error.value = result.error;
    }
}
</script>

<template>
    <div class="page">
        <h1>Passwort vergessen</h1>

        <template v-if="sentMessage !== null">
            <p class="notice notice-success" role="status" data-testid="reset-sent">
                {{ sentMessage }}
            </p>
            <p class="hint">Der Link in der E-Mail ist eine Stunde gültig.</p>
            <MailHint />
        </template>

        <form v-else class="card" novalidate @submit.prevent="onSubmit">
            <p class="hint">
                Geben Sie die E-Mail-Adresse Ihres Kontos ein. Sie erhalten dann einen Link, mit dem
                Sie ein neues Passwort festlegen.
            </p>
            <div class="field">
                <Label for="forgot-email" class="field-label">E-Mail-Adresse</Label>
                <input
                    id="forgot-email"
                    ref="emailInput"
                    v-model="email"
                    class="input"
                    type="email"
                    autocomplete="username"
                    inputmode="email"
                    autocapitalize="none"
                    spellcheck="false"
                    enterkeyhint="send"
                    :aria-invalid="emailError !== null ? 'true' : undefined"
                    :aria-describedby="emailError !== null ? 'forgot-email-error' : undefined"
                />
                <p v-if="emailError !== null" id="forgot-email-error" class="field-error">
                    {{ emailError }}
                </p>
            </div>
            <ErrorNotice v-if="error !== null" :error="error" :is-service="base.isService" />
            <button class="btn btn-primary" type="submit" :disabled="busy">
                {{ busy ? 'Wird gesendet …' : 'Link anfordern' }}
            </button>
        </form>

        <p><RouterLink :to="{ name: 'login' }">Zurück zur Anmeldung</RouterLink></p>
    </div>
</template>

<style scoped>
.page {
    display: flex;
    flex-direction: column;
    gap: 20px;
    width: 100%;
    max-width: 440px;
}

.page p {
    margin: 0;
}

.hint {
    color: var(--muted);
}
</style>
