<script setup lang="ts">
import { Label } from 'reka-ui';
import { ref, useTemplateRef } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import type { ApiError } from '../api/client';
import { resendVerification, verifyEmail } from '../api/auth';
import ErrorNotice from '../components/ErrorNotice.vue';
import MailHint from '../components/MailHint.vue';
import { takeFragmentParam } from '../runtime/fragment';
import { useUnsavedInput } from '../runtime/unsavedInput';

const route = useRoute();
const router = useRouter();
// Token aus dem Mail-Link (#token=…); danach steht es nicht mehr in der Adresszeile.
const token = takeFragmentParam(router, route, 'token');

const busy = ref(false);
const error = ref<ApiError | null>(null);
const verifiedMessage = ref<string | null>(null);
/** Link abgelaufen oder benutzt: Knopf ausblenden, neuen Link anbieten. */
const tokenInvalid = ref(false);

const resendEmail = ref('');
const resendEmailError = ref<string | null>(null);
const resendBusy = ref(false);
const resendError = ref<ApiError | null>(null);
const resendMessage = ref<string | null>(null);
const resendInput = useTemplateRef<HTMLInputElement>('resendInput');

// Ein noch nicht eingelöster Link zählt wie eine Eingabe: Nach einem Neuladen wäre er weg.
useUnsavedInput(
    () =>
        (token !== null && verifiedMessage.value === null && !tokenInvalid.value) ||
        (resendEmail.value.trim() !== '' && resendMessage.value === null),
);

/**
 * Bestätigen erst per Knopf, nicht beim Öffnen: Virenscanner mancher Mailprogramme öffnen Links
 * vorab und würden die Adresse sonst ohne den Menschen bestätigen.
 */
async function onVerify(): Promise<void> {
    if (busy.value || token === null) {
        return;
    }
    busy.value = true;
    error.value = null;
    const result = await verifyEmail(token);
    busy.value = false;
    if (result.ok) {
        verifiedMessage.value = result.data.message;
        return;
    }
    tokenInvalid.value = result.error.code === 'invalid_token';
    error.value = result.error;
}

async function onResend(): Promise<void> {
    if (resendBusy.value) {
        return;
    }
    resendEmailError.value =
        resendEmail.value.trim() === '' ? 'Bitte geben Sie Ihre E-Mail-Adresse ein.' : null;
    if (resendEmailError.value !== null) {
        resendInput.value?.focus();
        return;
    }
    resendBusy.value = true;
    resendError.value = null;
    const result = await resendVerification(resendEmail.value.trim());
    resendBusy.value = false;
    if (result.ok) {
        resendMessage.value = result.data.message;
    } else if (result.error.code === 'invalid_request') {
        resendEmailError.value = result.error.message ?? 'Bitte prüfen Sie die E-Mail-Adresse.';
        resendInput.value?.focus();
    } else {
        resendError.value = result.error;
    }
}
</script>

<template>
    <div class="page">
        <h1>E-Mail-Adresse bestätigen</h1>

        <template v-if="verifiedMessage !== null">
            <p class="notice notice-success" role="status" data-testid="email-verified">
                {{ verifiedMessage }}
            </p>
            <p>
                <RouterLink class="btn btn-primary" :to="{ name: 'login' }">
                    Zur Anmeldung
                </RouterLink>
            </p>
        </template>

        <template v-else>
            <div v-if="token !== null && !tokenInvalid" class="card">
                <p>
                    Bitte bestätigen Sie, dass diese E-Mail-Adresse Ihnen gehört. Danach schaltet
                    ein Admin Ihrer Feuerwehr Ihr Konto frei.
                </p>
                <ErrorNotice v-if="error !== null" :error="error" context="email_verify" />
                <button class="btn btn-primary" type="button" :disabled="busy" @click="onVerify">
                    {{ busy ? 'Wird bestätigt …' : 'E-Mail-Adresse bestätigen' }}
                </button>
            </div>

            <ErrorNotice
                v-if="tokenInvalid && error !== null"
                :error="error"
                context="email_verify"
            />

            <p v-if="token === null" class="hint">
                Bitte öffnen Sie den Link aus der Bestätigungsmail. Keine Mail erhalten oder der
                Link ist abgelaufen? Dann lassen Sie sich hier einen neuen Link schicken.
            </p>

            <template v-if="token === null || tokenInvalid">
                <template v-if="resendMessage !== null">
                    <p class="notice notice-success" role="status" data-testid="resend-sent">
                        {{ resendMessage }}
                    </p>
                    <p class="hint">Der neue Link ist 24 Stunden gültig.</p>
                    <MailHint />
                </template>
                <form v-else class="card" novalidate @submit.prevent="onResend">
                    <h2 class="card-title">Neuen Bestätigungslink anfordern</h2>
                    <div class="field">
                        <Label for="resend-email" class="field-label">E-Mail-Adresse</Label>
                        <input
                            id="resend-email"
                            ref="resendInput"
                            v-model="resendEmail"
                            class="input"
                            type="email"
                            autocomplete="username"
                            inputmode="email"
                            autocapitalize="none"
                            spellcheck="false"
                            enterkeyhint="send"
                            :aria-invalid="resendEmailError !== null ? 'true' : undefined"
                            :aria-describedby="
                                resendEmailError !== null ? 'resend-email-error' : undefined
                            "
                        />
                        <p
                            v-if="resendEmailError !== null"
                            id="resend-email-error"
                            class="field-error"
                        >
                            {{ resendEmailError }}
                        </p>
                    </div>
                    <ErrorNotice v-if="resendError !== null" :error="resendError" />
                    <button class="btn" type="submit" :disabled="resendBusy">
                        {{ resendBusy ? 'Wird gesendet …' : 'Neuen Link senden' }}
                    </button>
                </form>
            </template>
        </template>

        <p v-if="verifiedMessage === null">
            <RouterLink :to="{ name: 'login' }">Zur Anmeldung</RouterLink>
        </p>
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

.card-title {
    margin: 0;
    font-size: var(--font-size-md);
}

.btn {
    text-decoration: none;
}
</style>
