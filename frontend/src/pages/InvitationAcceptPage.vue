<script setup lang="ts">
import { Label } from 'reka-ui';
import { computed, ref, useTemplateRef } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import type { ApiError } from '../api/client';
import { acceptInvitation } from '../api/auth';
import { checkPassword, type PasswordContext } from '../auth/passwordRules';
import { resetSession, setLoginNotice } from '../auth/session';
import BrandCard from '../components/BrandCard.vue';
import ErrorNotice from '../components/ErrorNotice.vue';
import NewPasswordField from '../components/NewPasswordField.vue';
import { takeFragmentParam } from '../runtime/fragment';
import { isTenantUnavailable, tenantState } from '../runtime/tenantState';
import { useUnsavedInput } from '../runtime/unsavedInput';

const route = useRoute();
const router = useRouter();
// Token aus dem Mail-Link (#token=…); danach steht es nicht mehr in der Adresszeile.
const token = takeFragmentParam(router, route, 'token');

const name = ref('');
const password = ref('');
const nameError = ref<string | null>(null);
const passwordServerError = ref<string | null>(null);
const submitted = ref(false);
const busy = ref(false);
const error = ref<ApiError | null>(null);
/** Einladung abgelaufen oder benutzt: Formular ausblenden, Weg zu einer neuen Einladung zeigen. */
const tokenInvalid = ref(false);
const done = ref(false);

const nameInput = useTemplateRef<HTMLInputElement>('nameInput');
const passwordField = useTemplateRef<InstanceType<typeof NewPasswordField>>('passwordField');

// Ein noch nicht eingelöster Link zählt wie eine Eingabe: Nach einem Neuladen wäre er weg.
useUnsavedInput(
    () =>
        !done.value &&
        ((token !== null && !tokenInvalid.value) || name.value !== '' || password.value !== ''),
);

const formHidden = computed(() => isTenantUnavailable(tenantState.value.error));

// Die E-Mail-Adresse kennt nur der Server (er prüft sie zusätzlich).
const context = computed<PasswordContext>(() => ({
    email: null,
    names: [name.value],
    tenant: tenantState.value.tenant,
}));

async function onSubmit(): Promise<void> {
    if (busy.value || token === null) {
        return;
    }
    submitted.value = true;
    error.value = null;
    passwordServerError.value = null;
    nameError.value = name.value.trim() === '' ? 'Bitte geben Sie Ihren Namen ein.' : null;
    const passwordOk = checkPassword(password.value, context.value) === 'ok';
    if (nameError.value !== null) {
        nameInput.value?.focus();
        return;
    }
    if (!passwordOk) {
        passwordField.value?.focus();
        return;
    }
    busy.value = true;
    const result = await acceptInvitation(token, name.value.trim(), password.value);
    busy.value = false;
    if (result.ok) {
        done.value = true;
        // Keine Sitzung (wie nach „Passwort vergessen“): zur Anmeldung mit Hinweis.
        resetSession();
        setLoginNotice('invitation_accepted');
        await router.replace({ name: 'login' });
        return;
    }
    const failure = result.error;
    if (failure.code === 'weak_password') {
        passwordServerError.value = failure.message ?? 'Dieses Passwort ist zu unsicher.';
        passwordField.value?.focus();
    } else if (failure.code === 'invalid_request' && failure.message !== null) {
        // Regel für den Namen (Meldung des Servers am Feld).
        nameError.value = failure.message;
        nameInput.value?.focus();
    } else {
        tokenInvalid.value = failure.code === 'invalid_token';
        error.value = failure;
    }
}
</script>

<template>
    <div class="page">
        <BrandCard
            :is-service="false"
            :tenant="tenantState.tenant"
            :loading="tenantState.loading"
        />
        <h1>Einladung annehmen</h1>

        <div v-if="token === null" class="notice notice-error" role="alert">
            <p>
                Dieser Link ist unvollständig. Bitte öffnen Sie den Link aus der Einladungsmail
                erneut. Ist er abgelaufen, bitten Sie den Super-Admin um eine neue Einladung.
            </p>
        </div>

        <ErrorNotice v-if="error !== null" :error="error" context="invitation" />

        <form
            v-if="token !== null && !tokenInvalid && !formHidden"
            class="card"
            novalidate
            @submit.prevent="onSubmit"
        >
            <p class="hint">
                Sie wurden als Admin Ihrer Feuerwehr eingeladen. Legen Sie Ihren Namen und Ihr
                Passwort fest; danach melden Sie sich mit Ihrer E-Mail-Adresse an.
            </p>
            <div class="field">
                <Label for="invitation-name" class="field-label">Name</Label>
                <input
                    id="invitation-name"
                    ref="nameInput"
                    v-model="name"
                    class="input"
                    type="text"
                    autocomplete="name"
                    autocapitalize="words"
                    enterkeyhint="next"
                    :aria-invalid="nameError !== null ? 'true' : undefined"
                    :aria-describedby="nameError !== null ? 'invitation-name-error' : undefined"
                />
                <p v-if="nameError !== null" id="invitation-name-error" class="field-error">
                    {{ nameError }}
                </p>
            </div>
            <NewPasswordField
                id="invitation-password"
                ref="passwordField"
                v-model="password"
                :context="context"
                :server-error="passwordServerError"
                :submitted="submitted"
                @update:model-value="passwordServerError = null"
            />
            <button class="btn btn-primary" type="submit" :disabled="busy">
                {{ busy ? 'Wird gespeichert …' : 'Konto einrichten' }}
            </button>
        </form>

        <p><RouterLink :to="{ name: 'login' }">Zur Anmeldung</RouterLink></p>
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
