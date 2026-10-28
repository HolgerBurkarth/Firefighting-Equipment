<script setup lang="ts">
import { Label } from 'reka-ui';
import { computed, ref, useTemplateRef } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import type { ApiError } from '../api/client';
import { register } from '../api/auth';
import { errorMessage } from '../auth/errorMessages';
import { formatInvitationCode, normalizeInvitationCode } from '../auth/invitationCode';
import { checkPassword, type PasswordContext } from '../auth/passwordRules';
import BrandCard from '../components/BrandCard.vue';
import ErrorNotice from '../components/ErrorNotice.vue';
import MailHint from '../components/MailHint.vue';
import NewPasswordField from '../components/NewPasswordField.vue';
import { isTenantUnavailable, tenantState } from '../runtime/tenantState';
import { takeFragmentParam } from '../runtime/fragment';
import { useUnsavedInput } from '../runtime/unsavedInput';

const route = useRoute();
const router = useRouter();

// Code aus dem QR-Link (#code=…); danach steht er nicht mehr in der Adresszeile.
const linkCode = takeFragmentParam(router, route, 'code');
const linkNormalized = linkCode === null ? null : normalizeInvitationCode(linkCode);

const code = ref(linkNormalized !== null ? formatInvitationCode(linkNormalized) : (linkCode ?? ''));
const name = ref('');
const email = ref('');
const password = ref('');
const codeError = ref<string | null>(null);
const nameError = ref<string | null>(null);
const emailError = ref<string | null>(null);
const passwordServerError = ref<string | null>(null);
const submitted = ref(false);
const busy = ref(false);
const error = ref<ApiError | null>(null);
const sentMessage = ref<string | null>(null);

const codeInput = useTemplateRef<HTMLInputElement>('codeInput');
const nameInput = useTemplateRef<HTMLInputElement>('nameInput');
const emailInput = useTemplateRef<HTMLInputElement>('emailInput');
const passwordField = useTemplateRef<InstanceType<typeof NewPasswordField>>('passwordField');

// Auch ein Code aus dem Link zählt: Er stünde nach einem Neuladen nicht mehr in der Adresse.
useUnsavedInput(
    () =>
        sentMessage.value === null &&
        (code.value !== '' || name.value !== '' || email.value !== '' || password.value !== ''),
);

const formHidden = computed(() => isTenantUnavailable(tenantState.value.error));

const context = computed<PasswordContext>(() => ({
    email: email.value,
    names: [name.value],
    tenant: tenantState.value.tenant,
}));

const CODE_FORMAT_MESSAGE =
    'Der Einladungscode hat 12 Zeichen (Buchstaben und Ziffern, z. B. ABCD-EFGH-JKMN). Bitte prüfen Sie die Eingabe.';

/** Gültigen Code beim Verlassen des Feldes einheitlich als XXXX-XXXX-XXXX anzeigen. */
function onCodeBlur(): void {
    const normalized = normalizeInvitationCode(code.value);
    if (normalized !== null) {
        code.value = formatInvitationCode(normalized);
    }
}

function validate(): { readonly code: string } | null {
    const normalized = normalizeInvitationCode(code.value);
    codeError.value =
        code.value.trim() === ''
            ? 'Bitte geben Sie den Einladungscode ein.'
            : normalized === null
              ? CODE_FORMAT_MESSAGE
              : null;
    nameError.value = name.value.trim() === '' ? 'Bitte geben Sie Ihren Namen ein.' : null;
    emailError.value =
        email.value.trim() === '' ? 'Bitte geben Sie Ihre E-Mail-Adresse ein.' : null;
    const passwordOk = checkPassword(password.value, context.value) === 'ok';

    if (codeError.value !== null) {
        codeInput.value?.focus();
    } else if (nameError.value !== null) {
        nameInput.value?.focus();
    } else if (emailError.value !== null) {
        emailInput.value?.focus();
    } else if (!passwordOk) {
        passwordField.value?.focus();
    }
    return normalized !== null &&
        nameError.value === null &&
        emailError.value === null &&
        passwordOk
        ? { code: normalized }
        : null;
}

async function onSubmit(): Promise<void> {
    if (busy.value) {
        return;
    }
    submitted.value = true;
    error.value = null;
    passwordServerError.value = null;
    const valid = validate();
    if (valid === null) {
        return;
    }
    busy.value = true;
    const result = await register({
        code: formatInvitationCode(valid.code),
        email: email.value.trim(),
        password: password.value,
        name: name.value.trim(),
    });
    busy.value = false;
    if (result.ok) {
        sentMessage.value = result.data.message;
        // Passwort nicht länger im Speicher der Seite halten.
        password.value = '';
        return;
    }
    const failure = result.error;
    if (failure.code === 'invalid_invitation_code') {
        codeError.value = errorMessage(failure, { context: 'register' });
        codeInput.value?.focus();
    } else if (failure.code === 'weak_password') {
        passwordServerError.value = errorMessage(failure);
        passwordField.value?.focus();
    } else if (failure.code === 'invalid_request' && failure.message !== null) {
        // Schreibweise von E-Mail oder Name (Meldung der Regel des Servers am passenden Feld).
        if (failure.message.includes('E-Mail')) {
            emailError.value = failure.message;
            emailInput.value?.focus();
        } else if (failure.message.includes('Name')) {
            nameError.value = failure.message;
            nameInput.value?.focus();
        } else {
            error.value = failure;
        }
    } else {
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
        <h1>Registrieren</h1>

        <template v-if="sentMessage !== null">
            <p class="notice notice-success" role="status" data-testid="register-sent">
                {{ sentMessage }}
            </p>
            <p class="hint">
                Der Link in der E-Mail ist 24 Stunden gültig. Nach der Bestätigung schaltet ein
                Admin Ihrer Feuerwehr Ihr Konto frei; auch darüber erhalten Sie eine E-Mail.
            </p>
            <MailHint />
            <p>
                <RouterLink :to="{ name: 'email-verify' }">
                    Bestätigungsmail erneut senden
                </RouterLink>
            </p>
        </template>

        <form v-else-if="!formHidden" class="card" novalidate @submit.prevent="onSubmit">
            <p class="hint">
                Mit dem Einladungscode Ihrer Feuerwehr legen Sie hier Ihr Konto an. Nach der
                Bestätigung Ihrer E-Mail-Adresse schaltet ein Admin es frei.
            </p>
            <div class="field">
                <Label for="register-code" class="field-label">Einladungscode</Label>
                <input
                    id="register-code"
                    ref="codeInput"
                    v-model="code"
                    class="input input-code"
                    type="text"
                    autocomplete="off"
                    autocapitalize="characters"
                    spellcheck="false"
                    enterkeyhint="next"
                    :aria-invalid="codeError !== null ? 'true' : undefined"
                    :aria-describedby="codeError !== null ? 'register-code-error' : undefined"
                    @blur="onCodeBlur"
                />
                <p v-if="codeError !== null" id="register-code-error" class="field-error">
                    {{ codeError }}
                </p>
            </div>
            <div class="field">
                <Label for="register-name" class="field-label">Name</Label>
                <input
                    id="register-name"
                    ref="nameInput"
                    v-model="name"
                    class="input"
                    type="text"
                    autocomplete="name"
                    autocapitalize="words"
                    enterkeyhint="next"
                    :aria-invalid="nameError !== null ? 'true' : undefined"
                    :aria-describedby="nameError !== null ? 'register-name-error' : undefined"
                />
                <p v-if="nameError !== null" id="register-name-error" class="field-error">
                    {{ nameError }}
                </p>
            </div>
            <div class="field">
                <Label for="register-email" class="field-label">E-Mail-Adresse</Label>
                <input
                    id="register-email"
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
                    :aria-describedby="emailError !== null ? 'register-email-error' : undefined"
                />
                <p v-if="emailError !== null" id="register-email-error" class="field-error">
                    {{ emailError }}
                </p>
            </div>
            <NewPasswordField
                id="register-password"
                ref="passwordField"
                v-model="password"
                label="Passwort"
                :context="context"
                :server-error="passwordServerError"
                :submitted="submitted"
                @update:model-value="passwordServerError = null"
            />
            <ErrorNotice v-if="error !== null" :error="error" context="register" />
            <button class="btn btn-primary" type="submit" :disabled="busy">
                {{ busy ? 'Wird gesendet …' : 'Registrieren' }}
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

.input-code {
    font-variant-numeric: tabular-nums;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}
</style>
