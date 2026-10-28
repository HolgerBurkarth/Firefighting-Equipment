<script setup lang="ts">
import { Label } from 'reka-ui';
import { computed, nextTick, onBeforeUnmount, ref, useTemplateRef } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import type { ApiError } from '../api/client';
import { login, resendVerification } from '../api/auth';
import { formatCountdown } from '../auth/errorMessages';
import { afterLogin } from '../auth/redirect';
import { setSession, takeLoginNotice, type LoginNotice } from '../auth/session';
import BrandCard from '../components/BrandCard.vue';
import ErrorNotice from '../components/ErrorNotice.vue';
import MailHint from '../components/MailHint.vue';
import PasswordField from '../components/PasswordField.vue';
import { currentAppBase } from '../runtime/appBase';
import { isTenantUnavailable, tenantState } from '../runtime/tenantState';
import { useUnsavedInput } from '../runtime/unsavedInput';

const NOTICES: Readonly<Record<LoginNotice, string>> = {
    logged_out: 'Sie wurden abgemeldet.',
    session_expired: 'Ihre Sitzung ist abgelaufen. Bitte melden Sie sich erneut an.',
    password_reset: 'Das Passwort wurde geändert. Bitte melden Sie sich mit dem neuen Passwort an.',
};

const base = currentAppBase();
const route = useRoute();
const router = useRouter();
const notice = takeLoginNotice();

const email = ref('');
const password = ref('');
// Angefangene Anmeldung: kein automatisches Update mitten in der Eingabe.
useUnsavedInput(() => email.value !== '' || password.value !== '');
const emailError = ref<string | null>(null);
const passwordError = ref<string | null>(null);
const busy = ref(false);
const error = ref<ApiError | null>(null);

const emailInput = useTemplateRef<HTMLInputElement>('emailInput');
const passwordField = useTemplateRef<InstanceType<typeof PasswordField>>('passwordField');

// Bremse (429): Knopf gesperrt, bis die Wartezeit aus Retry-After abgelaufen ist.
const blockedSeconds = ref(0);
let timer: ReturnType<typeof setInterval> | null = null;

function startCountdown(seconds: number): void {
    stopCountdown();
    const until = Date.now() + seconds * 1000;
    blockedSeconds.value = seconds;
    timer = setInterval(() => {
        blockedSeconds.value = Math.max(0, Math.ceil((until - Date.now()) / 1000));
        if (blockedSeconds.value === 0) {
            stopCountdown();
        }
    }, 250);
}

function stopCountdown(): void {
    if (timer !== null) {
        clearInterval(timer);
        timer = null;
    }
}

onBeforeUnmount(stopCountdown);

// „Mail erneut senden“ bei email_not_verified
const resendBusy = ref(false);
const resendMessage = ref<string | null>(null);
const resendError = ref<ApiError | null>(null);

const formHidden = computed(() => isTenantUnavailable(tenantState.value.error));
const submitLabel = computed(() => {
    if (blockedSeconds.value > 0) {
        return `Anmelden wieder möglich in ${formatCountdown(blockedSeconds.value)}`;
    }
    return busy.value ? 'Anmelden …' : 'Anmelden';
});

function validate(): boolean {
    emailError.value =
        email.value.trim() === '' ? 'Bitte geben Sie Ihre E-Mail-Adresse ein.' : null;
    passwordError.value = password.value === '' ? 'Bitte geben Sie Ihr Passwort ein.' : null;
    if (emailError.value !== null) {
        emailInput.value?.focus();
        return false;
    }
    if (passwordError.value !== null) {
        passwordField.value?.focus();
        return false;
    }
    return true;
}

async function onSubmit(): Promise<void> {
    if (busy.value || blockedSeconds.value > 0 || !validate()) {
        return;
    }
    busy.value = true;
    error.value = null;
    resendMessage.value = null;
    resendError.value = null;
    // Das Passwort wird nie verändert gesendet (auch kein Trimmen).
    const result = await login(email.value.trim(), password.value);
    busy.value = false;
    if (result.ok) {
        setSession(result.data);
        await router.replace(afterLogin(router, result.data.user.role, route.query['weiter']));
        return;
    }
    error.value = result.error;
    if (result.error.code === 'invalid_credentials') {
        password.value = '';
        await nextTick();
        passwordField.value?.focus();
    } else if (result.error.code === 'too_many_attempts') {
        startCountdown(result.error.retryAfterSeconds ?? 60);
    }
}

async function onResend(): Promise<void> {
    resendBusy.value = true;
    resendError.value = null;
    const result = await resendVerification(email.value.trim());
    resendBusy.value = false;
    if (result.ok) {
        resendMessage.value = result.data.message;
    } else {
        resendError.value = result.error;
    }
}
</script>

<template>
    <div class="login">
        <BrandCard
            :is-service="base.isService"
            :tenant="tenantState.tenant"
            :loading="tenantState.loading"
        />
        <h1>Anmelden</h1>

        <p
            v-if="notice !== null"
            class="notice"
            :class="notice === 'session_expired' ? 'notice-info' : 'notice-success'"
            role="status"
            data-testid="login-notice"
        >
            {{ NOTICES[notice] }}
        </p>

        <form v-if="!formHidden" class="card" novalidate @submit.prevent="onSubmit">
            <div class="field">
                <Label for="login-email" class="field-label">E-Mail-Adresse</Label>
                <input
                    id="login-email"
                    ref="emailInput"
                    v-model="email"
                    class="input"
                    type="email"
                    autocomplete="username"
                    inputmode="email"
                    autocapitalize="none"
                    spellcheck="false"
                    enterkeyhint="next"
                    :aria-invalid="emailError !== null ? 'true' : undefined"
                    :aria-describedby="emailError !== null ? 'login-email-error' : undefined"
                />
                <p v-if="emailError !== null" id="login-email-error" class="field-error">
                    {{ emailError }}
                </p>
            </div>
            <div class="field">
                <Label for="login-password" class="field-label">Passwort</Label>
                <PasswordField
                    id="login-password"
                    ref="passwordField"
                    v-model="password"
                    autocomplete="current-password"
                    :invalid="passwordError !== null"
                    :describedby="passwordError !== null ? 'login-password-error' : undefined"
                />
                <p v-if="passwordError !== null" id="login-password-error" class="field-error">
                    {{ passwordError }}
                </p>
            </div>

            <ErrorNotice
                v-if="error !== null"
                :error="error"
                context="login"
                :is-service="base.isService"
            >
                <button
                    v-if="error.code === 'email_not_verified' && resendMessage === null"
                    class="btn"
                    type="button"
                    :disabled="resendBusy"
                    @click="onResend"
                >
                    Bestätigungsmail erneut senden
                </button>
            </ErrorNotice>
            <template v-if="resendMessage !== null">
                <p class="notice notice-success" role="status">{{ resendMessage }}</p>
                <MailHint />
            </template>
            <ErrorNotice v-if="resendError !== null" :error="resendError" />

            <button
                class="btn btn-primary"
                type="submit"
                :disabled="busy || blockedSeconds > 0"
                data-testid="login-submit"
            >
                {{ submitLabel }}
            </button>
            <RouterLink class="forgot" :to="{ name: 'password-forgot' }">
                Passwort vergessen?
            </RouterLink>
        </form>
    </div>
</template>

<style scoped>
.forgot {
    display: inline-flex;
    align-items: center;
    align-self: center;
    min-height: var(--touch-min);
}

.login {
    display: flex;
    flex-direction: column;
    gap: 20px;
    width: 100%;
    max-width: 440px;
}
</style>
