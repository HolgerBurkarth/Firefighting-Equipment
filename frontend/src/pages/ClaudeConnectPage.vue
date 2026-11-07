<script setup lang="ts">
import { Label } from 'reka-ui';
import { computed, nextTick, onMounted, ref, shallowRef, useTemplateRef } from 'vue';
import type { ApiError } from '../api/client';
import {
    ACCESS_EXPLANATIONS,
    authorizeClaude,
    authorizeParams,
    checkAuthorization,
    safeRedirect,
    targetText,
    type AuthorizeDecision,
    type AuthorizeSummary,
    type GrantedAccess,
} from '../api/claude';
import { formatCountdown } from '../auth/errorMessages';
import BrandCard from '../components/BrandCard.vue';
import ErrorNotice from '../components/ErrorNotice.vue';
import PasswordField from '../components/PasswordField.vue';
import { useCountdown } from '../runtime/countdown';
import { tenantState } from '../runtime/tenantState';
import { useUnsavedInput } from '../runtime/unsavedInput';

/**
 * „Claude verbinden“ (docs/PLAN-MCP.md, Schritt M4; Ziel von authorization_endpoint): Claude öffnet
 * die Seite mit den Parametern der Anmeldung (OAuth 2.1). Erst prüft der Server die Anfrage
 * (Stadt, Anwendung, Ziel), dann meldet sich die Person mit E-Mail und Passwort an (immer, auch mit
 * Sitzung der App; es entsteht keine), sieht den Zugang und verbindet oder lehnt ab. Zurück zu
 * Claude geht es nur zu einer Adresse, die der Server ausgegeben hat und die genau zur Anfrage
 * passt (safeRedirect).
 */

const params = authorizeParams(window.location.search);

const summary = shallowRef<AuthorizeSummary | null>(null);
const checkError = shallowRef<ApiError | null>(null);
/** Rücksprung mit Fehler für Claude (Client und Adresse geprüft), sonst null. */
const errorRedirect = ref<string | null>(null);

const email = ref('');
const password = ref('');
const emailError = ref<string | null>(null);
const passwordError = ref<string | null>(null);
/** Nach der Anmeldung: gewährter Zugang und Name des Kontos. */
const granted = ref<{ access: GrantedAccess; label: string; name: string | null } | null>(null);
const busy = ref<AuthorizeDecision | null>(null);
const error = shallowRef<ApiError | null>(null);
const leaving = ref(false);

const emailInput = useTemplateRef<HTMLInputElement>('emailInput');
const passwordField = useTemplateRef<InstanceType<typeof PasswordField>>('passwordField');
const countdown = useCountdown();
const blockedSeconds = countdown.seconds;

// Angefangene Anmeldung: kein automatisches Update mitten in der Eingabe (und nicht beim Rücksprung).
useUnsavedInput(() => !leaving.value && (email.value !== '' || password.value !== ''));

const accessMissing = computed(() => error.value?.code === 'claude_access_missing');
const loginLabel = computed(() => {
    if (blockedSeconds.value > 0) {
        return `Anmelden wieder möglich in ${formatCountdown(blockedSeconds.value)}`;
    }
    return busy.value === 'preview' ? 'Anmelden …' : 'Anmelden und fortfahren';
});

onMounted(async () => {
    const result = await checkAuthorization(params);
    if (result.ok) {
        summary.value = result.data;
        await nextTick();
        emailInput.value?.focus();
        return;
    }
    checkError.value = result.error;
    const redirect = result.error.details?.['redirect_url'];
    errorRedirect.value = typeof redirect === 'string' ? safeRedirect(redirect, params) : null;
});

function leave(url: string): void {
    leaving.value = true;
    window.location.assign(url);
}

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

async function send(decision: AuthorizeDecision): Promise<void> {
    if (busy.value !== null || leaving.value) {
        return;
    }
    if (decision !== 'deny' && (blockedSeconds.value > 0 || !validate())) {
        return;
    }
    busy.value = decision;
    error.value = null;
    // Das Passwort wird nie verändert gesendet (auch kein Trimmen).
    const credentials =
        decision === 'deny' ? null : { email: email.value.trim(), password: password.value };
    const result = await authorizeClaude(params, decision, credentials);
    busy.value = null;
    if (!result.ok) {
        error.value = result.error;
        if (result.error.code === 'invalid_credentials') {
            granted.value = null;
            password.value = '';
            await nextTick();
            passwordField.value?.focus();
        } else if (result.error.code === 'too_many_attempts') {
            countdown.start(result.error.retryAfterSeconds ?? 60);
        } else if (result.error.code === 'claude_access_missing') {
            granted.value = null;
        }
        return;
    }
    if (decision === 'preview') {
        const { access, accessLabel, displayName } = result.data;
        granted.value =
            access === null ? null : { access, label: accessLabel ?? access, name: displayName };
        return;
    }
    const target =
        result.data.redirectUrl === null ? null : safeRedirect(result.data.redirectUrl, params);
    if (target === null) {
        error.value = {
            code: 'invalid_response',
            message: null,
            status: null,
            retryAfterSeconds: null,
            details: null,
        };
        return;
    }
    leave(target);
}

function onChangeLogin(): void {
    granted.value = null;
    error.value = null;
    password.value = '';
    void nextTick(() => passwordField.value?.focus());
}
</script>

<template>
    <div class="connect">
        <BrandCard
            :is-service="false"
            :tenant="tenantState.tenant"
            :loading="tenantState.loading"
        />
        <h1>Claude verbinden</h1>

        <p v-if="summary === null && checkError === null" class="hint" role="status">
            Anfrage wird geprüft …
        </p>

        <section v-if="checkError !== null" class="card" data-testid="claude-check-error">
            <ErrorNotice :error="checkError" />
            <template v-if="errorRedirect !== null">
                <p class="hint">Claude bekommt dazu eine Fehlermeldung.</p>
                <button class="btn" type="button" :disabled="leaving" @click="leave(errorRedirect)">
                    Zurück zu Claude
                </button>
            </template>
            <p v-else class="hint">Bitte starten Sie die Verbindung in Claude neu.</p>
        </section>

        <template v-if="summary !== null">
            <section
                class="card request"
                aria-labelledby="claude-request-heading"
                data-testid="claude-request"
            >
                <h2 id="claude-request-heading" class="visually-hidden">Anfrage</h2>
                <p>
                    <strong>{{ summary.clientName ?? 'Claude' }}</strong> möchte in Ihrem Namen auf
                    die Ausrüstungsverwaltung von
                    <strong>{{ summary.tenantName }}</strong> zugreifen.
                </p>
                <dl class="facts">
                    <div>
                        <dt>Stadt</dt>
                        <dd data-testid="claude-tenant">{{ summary.tenantName }}</dd>
                    </div>
                    <div>
                        <dt>Ziel</dt>
                        <dd data-testid="claude-target">{{ targetText(summary.target) }}</dd>
                    </div>
                    <div>
                        <dt>{{ granted === null ? 'Angefragter Zugang' : 'Zugang' }}</dt>
                        <dd data-testid="claude-access">
                            <strong>{{ granted?.label ?? summary.requestedAccessLabel }}</strong>
                        </dd>
                    </div>
                </dl>
                <p class="hint">
                    {{ ACCESS_EXPLANATIONS[granted?.access ?? summary.requestedAccess] }}
                    <template v-if="granted === null">
                        Wirksam ist höchstens der Claude-Zugang, den Ihr Admin für Ihr Konto
                        freigegeben hat.
                    </template>
                </p>
            </section>

            <form
                v-if="granted === null && !accessMissing"
                class="card"
                novalidate
                data-testid="claude-login"
                @submit.prevent="send('preview')"
            >
                <p class="hint">
                    Bitte melden Sie sich zur Bestätigung mit Ihrem Konto bei
                    {{ summary.tenantName }} an.
                </p>
                <div class="field">
                    <Label for="claude-email" class="field-label">E-Mail-Adresse</Label>
                    <input
                        id="claude-email"
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
                        :aria-describedby="emailError !== null ? 'claude-email-error' : undefined"
                    />
                    <p v-if="emailError !== null" id="claude-email-error" class="field-error">
                        {{ emailError }}
                    </p>
                </div>
                <div class="field">
                    <Label for="claude-password" class="field-label">Passwort</Label>
                    <PasswordField
                        id="claude-password"
                        ref="passwordField"
                        v-model="password"
                        autocomplete="current-password"
                        :invalid="passwordError !== null"
                        :describedby="passwordError !== null ? 'claude-password-error' : undefined"
                    />
                    <p v-if="passwordError !== null" id="claude-password-error" class="field-error">
                        {{ passwordError }}
                    </p>
                </div>
                <ErrorNotice v-if="error !== null" :error="error" context="login" />
                <div class="actions">
                    <button
                        class="btn btn-primary"
                        type="submit"
                        :disabled="busy !== null || blockedSeconds > 0 || leaving"
                        data-testid="claude-login-submit"
                    >
                        {{ loginLabel }}
                    </button>
                    <button
                        class="btn"
                        type="button"
                        :disabled="busy !== null || leaving"
                        @click="send('deny')"
                    >
                        Ablehnen
                    </button>
                </div>
            </form>

            <section v-else class="card" data-testid="claude-consent">
                <template v-if="accessMissing && error !== null">
                    <ErrorNotice :error="error" />
                    <button class="btn" type="button" @click="onChangeLogin">
                        Mit anderem Konto anmelden
                    </button>
                </template>
                <template v-else-if="granted !== null">
                    <p>
                        Angemeldet als <strong>{{ granted.name ?? email }}</strong
                        >. Claude bekommt Zugang:
                        <strong data-testid="claude-granted">{{ granted.label }}</strong
                        >.
                    </p>
                    <ErrorNotice v-if="error !== null" :error="error" context="login" />
                    <p v-if="leaving" class="hint" role="status">Zurück zu Claude …</p>
                    <div class="actions">
                        <button
                            class="btn btn-primary"
                            type="button"
                            :disabled="busy !== null || blockedSeconds > 0 || leaving"
                            data-testid="claude-allow"
                            @click="send('allow')"
                        >
                            {{ busy === 'allow' ? 'Verbinden …' : 'Verbinden' }}
                        </button>
                        <button
                            class="btn"
                            type="button"
                            :disabled="busy !== null || leaving"
                            @click="send('deny')"
                        >
                            Ablehnen
                        </button>
                    </div>
                    <button
                        class="btn"
                        type="button"
                        :disabled="busy !== null || leaving"
                        @click="onChangeLogin"
                    >
                        Mit anderem Konto anmelden
                    </button>
                </template>
            </section>
        </template>
    </div>
</template>

<style scoped>
.connect {
    display: flex;
    flex-direction: column;
    gap: 20px;
    width: 100%;
    max-width: 480px;
}

.request p {
    margin: 0;
}

.facts {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin: 0;
}

.facts div {
    display: flex;
    flex-wrap: wrap;
    gap: 4px 12px;
}

.facts dt {
    min-width: 9em;
    color: var(--muted);
}

.facts dd {
    margin: 0;
    overflow-wrap: anywhere;
}

.hint {
    margin: 0;
    color: var(--muted);
    font-size: var(--font-size-md);
}

.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
}
</style>
