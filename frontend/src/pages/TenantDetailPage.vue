<script setup lang="ts">
import { computed, onMounted, ref, shallowRef, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import type { ApiError } from '../api/client';
import { fetchTenant, fetchTenantUsers, type ManagedTenant } from '../api/tenants';
import type { AdminUser } from '../api/users';
import ConfirmDialog from '../components/ConfirmDialog.vue';
import ErrorNotice from '../components/ErrorNotice.vue';
import FormDialog from '../components/FormDialog.vue';
import { phpTrim } from '../data/masterData';
import {
    inviteAdmin,
    renameTenant,
    resendInvitation,
    setTenantLocked,
    tenantList,
} from '../data/service';
import {
    TENANT_STATE_LABELS,
    countText,
    emailProblem,
    invitationText,
    tenantAddress,
    tenantNameProblem,
    tenantState,
    tenantUserRows,
} from '../data/tenants';
import { createdText, statusText } from '../data/users';
import { goBack } from '../router/back';
import { useUnsavedInput } from '../runtime/unsavedInput';

/**
 * Stadtdetail des Super-Admins (KONZEPT.md, "Super-Admin – Entscheidungen", 44): Name, Adresse
 * (nur als Text), Status und Zahlen; Umbenennen, Sperren/Entsperren mit Rückfrage, Admin einladen;
 * darunter die Konten der Stadt nur zum Ansehen, eingeladene Admins mit „Einladung erneut senden“.
 */
const route = useRoute();
const router = useRouter();
const id = computed(() => Number(route.params['id']));

const loaded = shallowRef<ManagedTenant | null>(null);
/** Zuerst aus der Liste der Städte, dann der Stand des Servers. */
const tenant = computed(
    () => loaded.value ?? tenantList.state.items?.find((entry) => entry.id === id.value) ?? null,
);
const loadError = shallowRef<ApiError | null>(null);
const users = shallowRef<AdminUser[] | null>(null);
const usersError = shallowRef<ApiError | null>(null);
const query = ref('');
const rows = computed(() => tenantUserRows(users.value ?? [], query.value));

const success = ref<string | null>(null);
const failure = ref<string | null>(null);
const pageError = shallowRef<ApiError | null>(null);
const busy = ref(false);
const resendingId = ref<number | null>(null);

async function loadUsers(): Promise<void> {
    const result = await fetchTenantUsers(id.value);
    if (result.ok) {
        users.value = result.data;
        usersError.value = null;
    } else {
        usersError.value = result.error;
    }
}

async function load(): Promise<void> {
    loadError.value = null;
    const [tenantResult] = await Promise.all([fetchTenant(id.value), loadUsers()]);
    if (tenantResult.ok) {
        loaded.value = tenantResult.data;
    } else {
        loadError.value = tenantResult.error;
    }
}

function clearMessages(): void {
    success.value = null;
    failure.value = null;
    pageError.value = null;
}

function back(): Promise<void> {
    return goBack(router, { name: 'tenants' });
}

/** Ergebnis einer Änderung an der Stadt: neuer Stand, sonst Fehler auf der Seite. */
function applyTenant(result: Awaited<ReturnType<typeof renameTenant>>): boolean {
    if (result.ok) {
        loaded.value = result.data;
        return true;
    }
    if (result.error.code === 'not_found') {
        loadError.value = result.error;
    }
    return false;
}

// Umbenennen
const renameOpen = ref(false);
const newName = ref('');
const renameTouched = ref(false);
const renameError = shallowRef<ApiError | null>(null);
const renameFieldError = ref<string | null>(null);
const opener = ref<HTMLElement | null>(null);

// Admin einladen
const inviteOpen = ref(false);
const inviteEmail = ref('');
const inviteTouched = ref(false);
const inviteError = shallowRef<ApiError | null>(null);
const inviteFieldError = ref<string | null>(null);

const unsaved = computed(
    () =>
        (renameOpen.value && newName.value !== (tenant.value?.name ?? '')) ||
        (inviteOpen.value && inviteEmail.value !== ''),
);
useUnsavedInput(() => unsaved.value);

const renameProblem = computed(() => tenantNameProblem(newName.value));
const shownRenameError = computed(
    () => renameFieldError.value ?? (renameTouched.value ? renameProblem.value : null),
);
const inviteProblem = computed(() => emailProblem(inviteEmail.value));
const shownInviteError = computed(
    () => inviteFieldError.value ?? (inviteTouched.value ? inviteProblem.value : null),
);

function remember(event: Event): void {
    opener.value = event.currentTarget instanceof HTMLElement ? event.currentTarget : null;
    clearMessages();
}

function openRename(event: Event): void {
    remember(event);
    newName.value = tenant.value?.name ?? '';
    renameTouched.value = false;
    renameError.value = null;
    renameFieldError.value = null;
    renameOpen.value = true;
}

async function submitRename(): Promise<void> {
    const current = tenant.value;
    renameTouched.value = true;
    renameError.value = null;
    renameFieldError.value = null;
    if (current === null || renameProblem.value !== null || busy.value) {
        return;
    }
    const name = phpTrim(newName.value);
    if (name === current.name) {
        renameOpen.value = false;
        return;
    }
    busy.value = true;
    const result = await renameTenant(current.id, name);
    busy.value = false;
    if (!applyTenant(result)) {
        if (!result.ok && result.error.code === 'invalid_request') {
            renameFieldError.value = result.error.message;
        } else if (!result.ok) {
            renameError.value = result.error;
        }
        return;
    }
    success.value = `„${current.name}“ heißt jetzt „${name}“.`;
    renameOpen.value = false;
}

function openInvite(event: Event): void {
    remember(event);
    inviteEmail.value = '';
    inviteTouched.value = false;
    inviteError.value = null;
    inviteFieldError.value = null;
    inviteOpen.value = true;
}

async function submitInvite(): Promise<void> {
    const current = tenant.value;
    inviteTouched.value = true;
    inviteError.value = null;
    inviteFieldError.value = null;
    if (current === null || inviteProblem.value !== null || busy.value) {
        return;
    }
    busy.value = true;
    const result = await inviteAdmin(current.id, phpTrim(inviteEmail.value));
    busy.value = false;
    if (!result.ok) {
        if (result.error.code === 'email_taken' || result.error.code === 'invalid_request') {
            inviteFieldError.value = result.error.message;
        } else {
            inviteError.value = result.error;
        }
        return;
    }
    const text = invitationText(result.data);
    if (text.ok) {
        success.value = text.text;
    } else {
        failure.value = text.text;
    }
    inviteEmail.value = '';
    inviteOpen.value = false;
    await Promise.all([loadUsers(), refreshTenant()]);
}

async function refreshTenant(): Promise<void> {
    const result = await fetchTenant(id.value);
    if (result.ok) {
        loaded.value = result.data;
    }
}

async function resend(user: AdminUser): Promise<void> {
    clearMessages();
    resendingId.value = user.id;
    const result = await resendInvitation(id.value, user.id);
    resendingId.value = null;
    if (!result.ok) {
        pageError.value = result.error;
        await loadUsers();
        return;
    }
    const text = invitationText(result.data);
    if (text.ok) {
        success.value = text.text;
    } else {
        failure.value = text.text;
    }
}

// Sperren und Entsperren
const lockOpen = ref(false);
const lockDescription = computed(() =>
    tenant.value?.active === true
        ? 'Alle Konten der Stadt werden sofort abgemeldet und können sich nicht mehr anmelden. Die Daten bleiben erhalten; Sie können die Stadt jederzeit wieder entsperren.'
        : 'Die Konten der Stadt können sich danach wieder anmelden.',
);

function askLock(event: Event): void {
    remember(event);
    lockOpen.value = true;
}

async function confirmLock(): Promise<void> {
    const current = tenant.value;
    if (current === null) {
        return;
    }
    busy.value = true;
    const result = await setTenantLocked(current.id, current.active);
    busy.value = false;
    if (!applyTenant(result)) {
        if (!result.ok) {
            pageError.value = result.error;
        }
        return;
    }
    success.value = current.active
        ? `„${current.name}“ ist gesperrt. Alle Konten der Stadt sind abgemeldet.`
        : `„${current.name}“ ist wieder entsperrt.`;
}

const stateBadge = computed(() => {
    const current = tenant.value;
    if (current === null) {
        return null;
    }
    const state = tenantState(current);
    const className = {
        active: 'badge-ready',
        invitation: 'badge-maintenance',
        locked: 'badge-retired',
    }[state];
    const icon = { active: '✓', invitation: '◷', locked: '✕' }[state];
    return { className, icon, label: TENANT_STATE_LABELS[state] };
});

/** Datum „12.10.2026“ in Ortszeit. */
function dayText(utc: string): string {
    return new Date(utc).toLocaleDateString('de-DE', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    });
}

watch(id, () => {
    loaded.value = null;
    users.value = null;
    void load();
});

onMounted(() => {
    void tenantList.refresh('open');
    void load();
});
</script>

<template>
    <div class="detail-nav">
        <button class="back-link" type="button" @click="back">
            <svg
                width="18"
                height="18"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                aria-hidden="true"
            >
                <path d="M15 6l-6 6 6 6" />
            </svg>
            Zurück
        </button>
    </div>

    <template v-if="tenant === null || loadError?.code === 'not_found'">
        <h1>Stadt</h1>
        <p v-if="loadError?.code === 'not_found'" class="hint">Diese Stadt gibt es nicht.</p>
        <ErrorNotice v-else-if="loadError !== null" :error="loadError">
            <button class="btn retry" type="button" @click="load">Erneut versuchen</button>
        </ErrorNotice>
        <p v-else class="hint" role="status">Stadt wird geladen …</p>
    </template>

    <template v-else>
        <div class="page-head">
            <div class="title">
                <h1>{{ tenant.name }}</h1>
                <span v-if="stateBadge !== null" class="badge" :class="stateBadge.className">
                    <span aria-hidden="true">{{ stateBadge.icon }}</span>
                    {{ stateBadge.label }}
                </span>
            </div>
        </div>

        <p
            v-if="success !== null"
            class="notice notice-success"
            role="status"
            data-testid="success-notice"
        >
            {{ success }}
        </p>
        <p v-if="failure !== null" class="notice notice-error" role="alert">{{ failure }}</p>
        <ErrorNotice v-if="pageError !== null" :error="pageError" />
        <ErrorNotice v-if="loadError !== null" :error="loadError" />

        <section class="card" aria-labelledby="tenant-facts" data-testid="tenant-facts">
            <h2 id="tenant-facts" class="visually-hidden">Angaben</h2>
            <dl class="facts">
                <div>
                    <dt>Adresse</dt>
                    <dd class="address">{{ tenantAddress(tenant.slug) }}</dd>
                </div>
                <div>
                    <dt>Kürzel</dt>
                    <dd>{{ tenant.slug }} <span class="hint">(nicht änderbar)</span></dd>
                </div>
                <div>
                    <dt>Admins</dt>
                    <dd>
                        {{ tenant.adminsActive }} aktiv<template v-if="tenant.adminsInvited > 0"
                            >,
                            {{
                                countText(tenant.adminsInvited, 'eingeladen', 'eingeladen')
                            }}</template
                        >
                    </dd>
                </div>
                <div>
                    <dt>Benutzer</dt>
                    <dd>{{ tenant.usersActive }} aktive Konten</dd>
                </div>
                <div>
                    <dt>Angelegt</dt>
                    <dd>{{ dayText(tenant.createdAt) }}</dd>
                </div>
            </dl>
            <div class="actions">
                <button
                    class="btn btn-accent"
                    type="button"
                    :disabled="!tenant.active"
                    @click="openInvite"
                >
                    Admin einladen …
                </button>
                <button class="btn" type="button" @click="openRename">Umbenennen …</button>
            </div>
            <p v-if="!tenant.active" class="hint">
                In eine gesperrte Stadt kann niemand eingeladen werden.
            </p>
        </section>

        <section class="card danger-zone" aria-labelledby="lock-heading">
            <h2 id="lock-heading">{{ tenant.active ? 'Stadt sperren' : 'Stadt entsperren' }}</h2>
            <p class="hint">
                {{
                    tenant.active
                        ? 'Gesperrt kann sich niemand der Stadt anmelden; Daten bleiben erhalten. Städte werden nie gelöscht.'
                        : 'Die Stadt ist gesperrt: niemand kann sich anmelden.'
                }}
            </p>
            <button
                class="btn"
                :class="{ 'btn-danger': tenant.active }"
                type="button"
                :disabled="busy"
                @click="askLock"
            >
                {{ tenant.active ? `${tenant.name} sperren …` : `${tenant.name} entsperren …` }}
            </button>
        </section>

        <section class="users" aria-labelledby="users-heading">
            <div class="section-head">
                <h2 id="users-heading">Konten</h2>
                <span v-if="users !== null" class="hint">
                    {{ countText(users.length, 'Konto', 'Konten') }} · nur ansehen
                </span>
            </div>
            <ErrorNotice v-if="usersError !== null" :error="usersError">
                <button class="btn retry" type="button" @click="loadUsers">Erneut versuchen</button>
            </ErrorNotice>
            <p v-else-if="users === null" class="hint" role="status">Wird geladen …</p>
            <p v-else-if="users.length === 0" class="hint">Noch keine Konten.</p>
            <template v-else>
                <label class="filter-field">
                    <svg
                        width="20"
                        height="20"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        aria-hidden="true"
                    >
                        <circle cx="11" cy="11" r="7" />
                        <path d="M20 20l-4-4" />
                    </svg>
                    <span class="visually-hidden">Konten filtern</span>
                    <input v-model="query" type="search" placeholder="Name oder E-Mail" />
                </label>
                <p v-if="rows.length === 0" class="hint">
                    Kein Konto passt zu „{{ query.trim() }}“.
                </p>
                <ul class="user-list">
                    <li
                        v-for="user in rows"
                        :key="user.id"
                        class="user"
                        :data-testid="`account-${String(user.id)}`"
                    >
                        <div class="user-main">
                            <span class="user-name">{{ user.label }}</span>
                            <span
                                v-if="user.email !== null && user.email !== user.label"
                                class="hint"
                            >
                                {{ user.email }}
                            </span>
                        </div>
                        <div class="user-meta">
                            <span>{{ user.roleLabel }}</span>
                            <span>{{ statusText(user) }}</span>
                            <span class="hint">seit {{ createdText(user) }}</span>
                        </div>
                        <button
                            v-if="user.status === 'invited' && !user.locked && tenant.active"
                            class="btn"
                            type="button"
                            :disabled="resendingId !== null"
                            :aria-label="`Einladung an ${user.email ?? user.label} erneut senden`"
                            @click="resend(user)"
                        >
                            {{
                                resendingId === user.id
                                    ? 'Wird gesendet …'
                                    : 'Einladung erneut senden'
                            }}
                        </button>
                    </li>
                </ul>
            </template>
        </section>

        <FormDialog
            v-model:open="renameOpen"
            :unsaved="unsaved"
            :title="`${tenant.name} umbenennen`"
            description="Das Kürzel und damit die Adresse bleiben gleich."
            submit-label="Speichern"
            :busy="busy"
            :can-submit="!renameTouched || renameProblem === null"
            :error="renameError"
            :return-focus="opener"
            @submit="submitRename"
        >
            <label class="field">
                <span class="field-label">Name der Stadt</span>
                <input
                    v-model="newName"
                    class="input"
                    type="text"
                    autocomplete="off"
                    :aria-invalid="shownRenameError !== null"
                    :aria-describedby="shownRenameError !== null ? 'rename-error' : undefined"
                    @input="
                        renameTouched = true;
                        renameFieldError = null;
                    "
                />
                <p v-if="shownRenameError !== null" id="rename-error" class="field-error">
                    {{ shownRenameError }}
                </p>
            </label>
        </FormDialog>

        <FormDialog
            v-model:open="inviteOpen"
            :unsaved="unsaved"
            title="Admin einladen"
            :description="`Die Person bekommt einen Link für ${tenant.name}, der 24 Stunden gültig ist.`"
            submit-label="Einladen"
            :busy="busy"
            :can-submit="!inviteTouched || inviteProblem === null"
            :error="inviteError"
            :return-focus="opener"
            @submit="submitInvite"
        >
            <label class="field">
                <span class="field-label">E-Mail-Adresse</span>
                <input
                    v-model="inviteEmail"
                    class="input"
                    type="email"
                    autocomplete="off"
                    :aria-invalid="shownInviteError !== null"
                    :aria-describedby="shownInviteError !== null ? 'invite-error' : undefined"
                    @input="
                        inviteTouched = true;
                        inviteFieldError = null;
                    "
                />
                <p v-if="shownInviteError !== null" id="invite-error" class="field-error">
                    {{ shownInviteError }}
                </p>
            </label>
        </FormDialog>

        <ConfirmDialog
            v-model:open="lockOpen"
            :title="tenant.active ? `${tenant.name} sperren?` : `${tenant.name} entsperren?`"
            :description="lockDescription"
            :confirm-label="tenant.active ? 'Sperren' : 'Entsperren'"
            @confirm="confirmLock"
        />
    </template>
</template>

<style scoped>
.hint {
    margin: 0;
    color: var(--muted);
}

.retry {
    align-self: flex-start;
}

.title {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px;
}

.facts {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px 24px;
    margin: 0;
}

.facts dt {
    color: var(--muted);
    font-size: var(--font-size-sm);
    font-weight: var(--font-weight-semibold);
}

.facts dd {
    margin: 2px 0 0;
    overflow-wrap: anywhere;
}

.address {
    font-weight: var(--font-weight-semibold);
}

.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
}

.danger-zone h2 {
    margin: 0;
}

.danger-zone .btn {
    align-self: flex-start;
}

.users {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.section-head {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 12px;
}

.section-head h2 {
    margin: 0;
}

.user-list {
    display: flex;
    flex-direction: column;
    margin: 0;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    background: var(--surface);
    list-style: none;
}

.user {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px 20px;
    padding: 12px 16px;
    border-top: 1px solid var(--border-subtle);
}

.user:first-child {
    border-top: 0;
}

.user-main {
    display: flex;
    flex: 1 1 220px;
    flex-direction: column;
    min-width: 0;
}

.user-name {
    font-weight: var(--font-weight-semibold);
    overflow-wrap: anywhere;
}

.user-main .hint {
    overflow-wrap: anywhere;
}

.user-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 4px 16px;
    font-size: var(--font-size-md);
}
</style>
