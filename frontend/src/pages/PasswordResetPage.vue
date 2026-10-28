<script setup lang="ts">
import { computed, ref, useTemplateRef } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import type { ApiError } from '../api/client';
import { confirmPasswordReset } from '../api/auth';
import { checkPassword, type PasswordContext } from '../auth/passwordRules';
import { resetSession, setLoginNotice } from '../auth/session';
import ErrorNotice from '../components/ErrorNotice.vue';
import NewPasswordField from '../components/NewPasswordField.vue';
import { currentAppBase } from '../runtime/appBase';
import { takeFragmentParam } from '../runtime/fragment';
import { tenantState } from '../runtime/tenantState';
import { useUnsavedInput } from '../runtime/unsavedInput';

const base = currentAppBase();
const route = useRoute();
const router = useRouter();
// Token aus dem Mail-Link (#token=…); danach steht es nicht mehr in der Adresszeile.
const token = takeFragmentParam(router, route, 'token');

const password = ref('');
const submitted = ref(false);
const busy = ref(false);
const error = ref<ApiError | null>(null);
const serverError = ref<string | null>(null);
/** Link abgelaufen oder benutzt: Formular ausblenden, Weg zu einem neuen Link zeigen. */
const tokenInvalid = ref(false);
useUnsavedInput(() => password.value !== '' && !tokenInvalid.value);
const field = useTemplateRef<InstanceType<typeof NewPasswordField>>('field');

// Die Seite kennt nur die Stadt (E-Mail und Name prüft der Server zusätzlich).
const context = computed<PasswordContext>(() => ({
    email: null,
    names: [],
    tenant: tenantState.value.tenant,
}));

function onInput(): void {
    serverError.value = null;
}

async function onSubmit(): Promise<void> {
    if (busy.value || token === null) {
        return;
    }
    submitted.value = true;
    if (checkPassword(password.value, context.value) !== 'ok') {
        field.value?.focus();
        return;
    }
    busy.value = true;
    error.value = null;
    serverError.value = null;
    const result = await confirmPasswordReset(token, password.value);
    busy.value = false;
    if (result.ok) {
        // Der Server hat alle Sitzungen des Kontos beendet.
        resetSession();
        setLoginNotice('password_reset');
        await router.replace({ name: 'login' });
        return;
    }
    if (result.error.code === 'weak_password') {
        serverError.value = result.error.message ?? 'Dieses Passwort ist zu unsicher.';
        field.value?.focus();
    } else {
        tokenInvalid.value = result.error.code === 'invalid_token';
        error.value = result.error;
    }
}
</script>

<template>
    <div class="page">
        <h1>Neues Passwort festlegen</h1>

        <div v-if="token === null" class="notice notice-error" role="alert">
            <p>
                Dieser Link ist unvollständig. Bitte öffnen Sie den Link aus der E-Mail erneut oder
                fordern Sie einen neuen an.
            </p>
        </div>

        <ErrorNotice
            v-if="error !== null"
            :error="error"
            context="password_reset"
            :is-service="base.isService"
        />

        <form
            v-if="token !== null && !tokenInvalid"
            class="card"
            novalidate
            @submit.prevent="onSubmit"
        >
            <NewPasswordField
                id="reset-password"
                ref="field"
                v-model="password"
                :context="context"
                :server-error="serverError"
                :submitted="submitted"
                @update:model-value="onInput"
            />
            <button class="btn btn-primary" type="submit" :disabled="busy">
                {{ busy ? 'Wird gespeichert …' : 'Passwort speichern' }}
            </button>
        </form>

        <p v-if="token === null || tokenInvalid">
            <RouterLink class="btn" :to="{ name: 'password-forgot' }">
                Neuen Link anfordern
            </RouterLink>
        </p>
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

.page > p {
    margin: 0;
}

.btn {
    text-decoration: none;
}
</style>
