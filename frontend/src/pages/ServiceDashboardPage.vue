<script setup lang="ts">
import { computed, onMounted, ref, shallowRef, watch } from 'vue';
import type { ApiError } from '../api/client';
import { fetchTenantUsers, type ManagedTenant } from '../api/tenants';
import ErrorNotice from '../components/ErrorNotice.vue';
import TenantTable from '../components/TenantTable.vue';
import { resendInvitation, tenantList } from '../data/service';
import { countText, openInvitations, serviceTiles, type OpenInvitation } from '../data/tenants';
import { createdText } from '../data/users';

/**
 * Dashboard des Super-Admins (Mockup „SuperAdmin“, KONZEPT.md, "Super-Admin – Entscheidungen", 41):
 * Kacheln Städte, offene Admin-Einladungen und Benutzer gesamt, darunter die offenen Einladungen mit
 * „Erneut senden“ und die Städte.
 */
const state = computed(() => tenantList.state);
const tenants = computed(() => state.value.items ?? []);
const tiles = computed(() => (state.value.items === null ? null : serviceTiles(tenants.value)));

const invitations = shallowRef<OpenInvitation[] | null>(null);
const invitationError = shallowRef<ApiError | null>(null);
const success = ref<string | null>(null);
const actionError = shallowRef<ApiError | null>(null);
const busyId = ref<number | null>(null);
let loadGeneration = 0;

/** Eingeladene Konten je Stadt mit offener Einladung (die Liste der Städte nennt nur die Zahl). */
async function loadInvitations(list: readonly ManagedTenant[]): Promise<void> {
    const generation = ++loadGeneration;
    const withInvitations = list.filter((tenant) => tenant.adminsInvited > 0);
    const results = await Promise.all(
        withInvitations.map(async (tenant) => ({
            tenant,
            result: await fetchTenantUsers(tenant.id, 'invited'),
        })),
    );
    if (generation !== loadGeneration) {
        return;
    }
    const failed = results.find((entry) => !entry.result.ok);
    if (failed !== undefined && !failed.result.ok) {
        invitationError.value = failed.result.error;
        return;
    }
    invitationError.value = null;
    invitations.value = openInvitations(
        results.map(({ tenant, result }) => ({ tenant, users: result.ok ? result.data : [] })),
    );
}

watch(
    () => state.value.items,
    (items) => {
        if (items !== null) {
            void loadInvitations(items);
        }
    },
    { immediate: true },
);

async function resend(invitation: OpenInvitation): Promise<void> {
    busyId.value = invitation.user.id;
    success.value = null;
    actionError.value = null;
    const result = await resendInvitation(invitation.tenant.id, invitation.user.id);
    busyId.value = null;
    if (!result.ok) {
        actionError.value = result.error;
        return;
    }
    const email = result.data.user.email ?? invitation.user.label;
    success.value = result.data.mailSent
        ? `Neue Einladung an ${email} gesendet (${invitation.tenant.name}). Der Link gilt 24 Stunden.`
        : `Die Mail an ${email} konnte nicht gesendet werden. Bitte später erneut versuchen.`;
}

function retry(): void {
    void tenantList.refresh('changed');
}

onMounted(() => {
    void tenantList.refresh('open');
});
</script>

<template>
    <div class="page-head">
        <h1>Dashboard</h1>
    </div>

    <ErrorNotice v-if="state.error !== null" :error="state.error">
        <button class="btn retry" type="button" :disabled="state.loading" @click="retry">
            Erneut versuchen
        </button>
    </ErrorNotice>
    <p v-if="tiles === null && state.error === null" class="hint" role="status">Wird geladen …</p>

    <template v-if="tiles !== null">
        <div class="tiles">
            <RouterLink
                class="tile tile-link tile-ready"
                :to="{ name: 'tenants' }"
                data-testid="tile-tenants"
            >
                <span class="tile-label">Städte</span>
                <span class="tile-value">{{ tiles.active }} aktiv</span>
                <span class="tile-detail">{{ tiles.locked }} gesperrt</span>
            </RouterLink>
            <div class="tile tile-maintenance" data-testid="tile-invitations">
                <span class="tile-label">Offene Admin-Einladungen</span>
                <span class="tile-value">{{ tiles.invited }}</span>
                <span class="tile-detail">Ein Einladungslink gilt 24 Stunden ab dem Senden.</span>
            </div>
            <div class="tile tile-accent" data-testid="tile-users">
                <span class="tile-label">Benutzer gesamt</span>
                <span class="tile-value">{{ tiles.users }}</span>
                <span class="tile-detail">aktive Konten in allen Städten</span>
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
        <ErrorNotice v-if="actionError !== null" :error="actionError" />

        <section
            v-if="tiles.invited > 0"
            class="card"
            aria-labelledby="invitations-heading"
            data-testid="open-invitations"
        >
            <h2 id="invitations-heading">Offene Admin-Einladungen</h2>
            <ErrorNotice v-if="invitationError !== null" :error="invitationError" />
            <p v-else-if="invitations === null" class="hint" role="status">Wird geladen …</p>
            <p v-else-if="invitations.length === 0" class="hint">
                Die eingeladenen Konten sind gesperrt; erneut senden lässt sich nur an nicht
                gesperrte.
            </p>
            <ul v-else class="invitation-list">
                <li
                    v-for="invitation in invitations"
                    :key="invitation.user.id"
                    class="invitation"
                    :data-testid="`invitation-${String(invitation.user.id)}`"
                >
                    <div class="invitation-text">
                        <span class="invitation-email">{{ invitation.user.email }}</span>
                        <span class="hint">
                            {{ invitation.tenant.name }} · eingeladen seit
                            {{ createdText(invitation.user) }}
                        </span>
                    </div>
                    <button
                        class="btn"
                        type="button"
                        :disabled="busyId !== null"
                        :aria-label="`Einladung an ${invitation.user.email ?? ''} erneut senden`"
                        @click="resend(invitation)"
                    >
                        {{ busyId === invitation.user.id ? 'Wird gesendet …' : 'Erneut senden' }}
                    </button>
                </li>
            </ul>
        </section>

        <section class="tenants" aria-labelledby="tenants-heading">
            <div class="section-head">
                <h2 id="tenants-heading">Städte</h2>
                <span class="hint">{{ countText(tenants.length, 'Stadt', 'Städte') }}</span>
            </div>
            <p v-if="tenants.length === 0" class="hint">Noch keine Stadt angelegt.</p>
            <TenantTable v-else :tenants="tenants" query="" />
        </section>
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

.tiles {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
    gap: 16px;
}

.tile {
    display: flex;
    flex-direction: column;
    gap: 6px;
    padding: 20px;
    border: 1px solid var(--border);
    border-top-width: 6px;
    border-radius: var(--radius-lg);
    background: var(--surface);
    color: var(--text);
    text-decoration: none;
}

.tile-link {
    transition:
        box-shadow 0.12s,
        transform 0.12s;
}

/* Kacheln heben sich am PC an (KONZEPT.md, "Oberfläche: Grundsätze"). */
.tile-link:hover {
    transform: translateY(-2px);
    border-color: var(--border-strong);
    box-shadow: var(--shadow-raised);
    color: var(--text);
}

.tile-ready {
    border-top-color: var(--status-ready-fg);
}

.tile-maintenance {
    border-top-color: var(--status-maintenance-fg);
}

.tile-accent {
    border-top-color: var(--line);
}

.tile-label {
    font-size: var(--font-size-md);
    font-weight: var(--font-weight-semibold);
    color: var(--muted);
}

.tile-value {
    font-size: var(--font-size-kpi);
    font-weight: var(--font-weight-bold);
    line-height: 1.1;
}

.tile-detail {
    font-size: var(--font-size-md);
}

.invitation-list {
    display: flex;
    flex-direction: column;
    margin: 0;
    padding: 0;
    list-style: none;
}

.invitation {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 8px 16px;
    padding: 10px 0;
    border-top: 1px solid var(--border-subtle);
}

.invitation:first-child {
    border-top: 0;
}

.invitation-text {
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.invitation-email {
    font-weight: var(--font-weight-semibold);
    overflow-wrap: anywhere;
}

.tenants {
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
</style>
