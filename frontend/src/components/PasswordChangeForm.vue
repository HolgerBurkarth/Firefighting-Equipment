<script setup lang="ts">
import { Label } from 'reka-ui';
import { computed, nextTick, ref, useTemplateRef } from 'vue';
import type { ApiError } from '../api/client';
import { changePassword } from '../api/auth';
import { formatCountdown } from '../auth/errorMessages';
import { checkPassword, type PasswordContext } from '../auth/passwordRules';
import { session, setSession } from '../auth/session';
import { useCountdown } from '../runtime/countdown';
import { useUnsavedInput } from '../runtime/unsavedInput';
import ErrorNotice from './ErrorNotice.vue';
import NewPasswordField from './NewPasswordField.vue';
import PasswordField from './PasswordField.vue';

const emit = defineEmits<{
    /** Passwort geändert: Der Server hat alle anderen Sitzungen beendet. */
    changed: [];
}>();

const open = ref(false);
const current = ref('');
const next = ref('');
const submitted = ref(false);
const busy = ref(false);
const currentError = ref<string | null>(null);
const serverError = ref<string | null>(null);
const error = ref<ApiError | null>(null);
const success = ref(false);
useUnsavedInput(() => open.value && (current.value !== '' || next.value !== ''));

// Falsche aktuelle Passwörter zählen in die Bremse der Anmeldung (429 mit Retry-After).
const countdown = useCountdown();
const blockedSeconds = countdown.seconds;

const toggleButton = useTemplateRef<HTMLButtonElement>('toggleButton');
const currentField = useTemplateRef<InstanceType<typeof PasswordField>>('currentField');
const newField = useTemplateRef<InstanceType<typeof NewPasswordField>>('newField');

// Live-Prüfung mit E-Mail, Name, Anzeigename und Stadt (wie der Server).
const context = computed<PasswordContext>(() => {
    const user = session.value?.user;
    return {
        email: user?.email ?? null,
        names: [user?.name, user?.displayName].filter(
            (name): name is string => typeof name === 'string',
        ),
        tenant: session.value?.tenant ?? null,
    };
});

const submitLabel = computed(() => {
    if (blockedSeconds.value > 0) {
        return `Wieder möglich in ${formatCountdown(blockedSeconds.value)}`;
    }
    return busy.value ? 'Wird gespeichert …' : 'Passwort speichern';
});

function clear(): void {
    current.value = '';
    next.value = '';
    submitted.value = false;
    currentError.value = null;
    serverError.value = null;
    error.value = null;
}

async function openForm(): Promise<void> {
    success.value = false;
    open.value = true;
    await nextTick();
    currentField.value?.focus();
}

async function closeForm(): Promise<void> {
    clear();
    open.value = false;
    await nextTick();
    toggleButton.value?.focus();
}

async function onSubmit(): Promise<void> {
    if (busy.value || blockedSeconds.value > 0) {
        return;
    }
    submitted.value = true;
    currentError.value =
        current.value === '' ? 'Bitte geben Sie Ihr aktuelles Passwort ein.' : null;
    if (currentError.value !== null) {
        currentField.value?.focus();
        return;
    }
    if (checkPassword(next.value, context.value) !== 'ok') {
        newField.value?.focus();
        return;
    }
    busy.value = true;
    error.value = null;
    serverError.value = null;
    // Passwörter werden unverändert gesendet (kein Trimmen).
    const result = await changePassword(current.value, next.value);
    busy.value = false;
    if (result.ok) {
        // Neue Sitzung für dieses Gerät (neues Cookie), dasselbe Konto.
        setSession(result.data);
        await closeForm();
        success.value = true;
        emit('changed');
        return;
    }
    switch (result.error.code) {
        case 'invalid_current_password':
            currentError.value = 'Das aktuelle Passwort ist falsch.';
            current.value = '';
            await nextTick();
            currentField.value?.focus();
            break;
        case 'weak_password':
            serverError.value = result.error.message ?? 'Dieses Passwort ist zu unsicher.';
            newField.value?.focus();
            break;
        case 'too_many_attempts':
            countdown.start(result.error.retryAfterSeconds ?? 60);
            error.value = result.error;
            break;
        default:
            error.value = result.error;
    }
}
</script>

<!-- Eigenes Passwort ändern: aufklappbar, Fehler am jeweiligen Feld (403 aktuelles, 422 neues). -->
<template>
    <section class="card" aria-labelledby="password-heading">
        <h2 id="password-heading">Passwort</h2>

        <p
            v-if="success"
            class="notice notice-success"
            role="status"
            data-testid="password-changed"
        >
            Ihr Passwort wurde geändert. Alle anderen Geräte wurden abgemeldet.
        </p>

        <button
            v-if="!open"
            ref="toggleButton"
            class="btn"
            type="button"
            aria-expanded="false"
            aria-controls="password-form"
            @click="openForm"
        >
            Passwort ändern
        </button>

        <form v-else id="password-form" class="form" novalidate @submit.prevent="onSubmit">
            <!-- Für Passwort-Manager: zu welchem Konto das neue Passwort gehört. -->
            <input
                v-if="context.email !== null"
                class="visually-hidden"
                type="email"
                autocomplete="username"
                :value="context.email"
                readonly
                tabindex="-1"
                aria-hidden="true"
            />
            <div class="field">
                <Label for="current-password" class="field-label">Aktuelles Passwort</Label>
                <PasswordField
                    id="current-password"
                    ref="currentField"
                    v-model="current"
                    autocomplete="current-password"
                    :invalid="currentError !== null"
                    :describedby="currentError !== null ? 'current-password-error' : undefined"
                    @update:model-value="currentError = null"
                />
                <p
                    v-if="currentError !== null"
                    id="current-password-error"
                    class="field-error"
                    data-testid="current-password-error"
                >
                    {{ currentError }}
                </p>
            </div>
            <NewPasswordField
                id="new-password"
                ref="newField"
                v-model="next"
                :context="context"
                :server-error="serverError"
                :submitted="submitted"
                @update:model-value="serverError = null"
            />
            <ErrorNotice v-if="error !== null" :error="error" />
            <div class="actions">
                <button
                    class="btn btn-primary"
                    type="submit"
                    :disabled="busy || blockedSeconds > 0"
                    data-testid="password-submit"
                >
                    {{ submitLabel }}
                </button>
                <button class="btn" type="button" :disabled="busy" @click="closeForm">
                    Abbrechen
                </button>
            </div>
        </form>
    </section>
</template>

<style scoped>
.form {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
}

.actions .btn-primary {
    flex: 1 1 220px;
}
</style>
