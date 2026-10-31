<script setup lang="ts">
import { computed, onMounted, ref, shallowRef } from 'vue';
import type { ApiError } from '../api/client';
import type { SuperAdmin } from '../api/tenants';
import { session } from '../auth/session';
import ConfirmDialog from '../components/ConfirmDialog.vue';
import ErrorNotice from '../components/ErrorNotice.vue';
import { setSuperAdminLocked, superAdminList } from '../data/service';
import { countText } from '../data/tenants';
import { statusText } from '../data/users';

/**
 * Super-Admins (KONZEPT.md, "Super-Admin – Entscheidungen", 45): Liste mit Sperren und Entsperren
 * (je mit Rückfrage). Das geschützte und das eigene Konto haben keinen Knopf; Anlegen, Passwort und
 * Schutz gibt es nur auf der Kommandozeile des Servers.
 */
const state = computed(() => superAdminList.state);
const admins = computed(() => state.value.items ?? []);
const ownId = computed(() => session.value?.user.id ?? null);

const success = ref<string | null>(null);
const pageError = shallowRef<ApiError | null>(null);
const busy = ref(false);
const target = shallowRef<SuperAdmin | null>(null);
const confirmOpen = ref(false);

/** Warum ein Konto keinen Knopf hat; null = sperren bzw. entsperren möglich. */
function noButtonReason(admin: SuperAdmin): string | null {
    if (admin.id === ownId.value) {
        return 'Ihr eigenes Konto';
    }
    if (admin.protected) {
        return 'geschützt (nur per Kommandozeile änderbar)';
    }
    return null;
}

function ask(admin: SuperAdmin): void {
    success.value = null;
    pageError.value = null;
    target.value = admin;
    confirmOpen.value = true;
}

const confirmDescription = computed(() =>
    target.value?.locked === false
        ? 'Das Konto wird sofort überall abgemeldet und kann sich nicht mehr anmelden, bis Sie es entsperren.'
        : 'Das Konto kann sich danach wieder anmelden.',
);

async function confirm(): Promise<void> {
    const admin = target.value;
    if (admin === null) {
        return;
    }
    busy.value = true;
    const result = await setSuperAdminLocked(admin.id, !admin.locked);
    busy.value = false;
    if (!result.ok) {
        pageError.value = result.error;
        return;
    }
    success.value = result.data.locked
        ? `${admin.label} ist gesperrt und überall abgemeldet.`
        : `${admin.label} ist wieder entsperrt.`;
}

function retry(): void {
    void superAdminList.refresh('changed');
}

onMounted(() => {
    void superAdminList.refresh('open');
});
</script>

<template>
    <div class="page-head">
        <h1>Super-Admins</h1>
        <span v-if="state.items !== null" class="hint">
            {{ countText(admins.length, 'Konto', 'Konten') }}
        </span>
    </div>

    <p
        v-if="success !== null"
        class="notice notice-success"
        role="status"
        data-testid="success-notice"
    >
        {{ success }}
    </p>
    <ErrorNotice v-if="pageError !== null" :error="pageError" />

    <ErrorNotice v-if="state.error !== null" :error="state.error">
        <button class="btn retry" type="button" :disabled="state.loading" @click="retry">
            Erneut versuchen
        </button>
    </ErrorNotice>
    <p v-if="state.items === null && state.error === null" class="hint" role="status">
        Wird geladen …
    </p>

    <ul v-if="admins.length > 0" class="admin-list">
        <li
            v-for="admin in admins"
            :key="admin.id"
            class="admin"
            :class="{ 'admin-locked': admin.locked }"
            :data-testid="`super-admin-${String(admin.id)}`"
        >
            <div class="admin-main">
                <span class="admin-name">
                    {{ admin.label }}
                    <span v-if="admin.id === ownId" class="badge badge-ready">Sie</span>
                    <span v-if="admin.protected" class="badge badge-maintenance">geschützt</span>
                </span>
                <span v-if="admin.email !== null" class="hint">{{ admin.email }}</span>
            </div>
            <span class="admin-status">{{ statusText(admin) }}</span>
            <span v-if="noButtonReason(admin) !== null" class="hint admin-reason">
                {{ noButtonReason(admin) }}
            </span>
            <button
                v-else
                class="btn"
                :class="{ 'btn-danger': !admin.locked }"
                type="button"
                :disabled="busy"
                @click="ask(admin)"
            >
                {{ admin.locked ? `${admin.label} entsperren …` : `${admin.label} sperren …` }}
            </button>
        </li>
    </ul>

    <p class="hint footnote">
        Neue Super-Admins, Passwörter und der Schutz eines Kontos werden auf dem Server per
        Kommandozeile gepflegt (<code>bin/user.php</code>).
    </p>

    <ConfirmDialog
        v-model:open="confirmOpen"
        :title="
            target?.locked === false
                ? `${target.label} sperren?`
                : `${target?.label ?? ''} entsperren?`
        "
        :description="confirmDescription"
        :confirm-label="target?.locked === false ? 'Sperren' : 'Entsperren'"
        @confirm="confirm"
    />
</template>

<style scoped>
.hint {
    margin: 0;
    color: var(--muted);
}

.retry {
    align-self: flex-start;
}

.admin-list {
    display: flex;
    flex-direction: column;
    margin: 0;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    background: var(--surface);
    list-style: none;
}

.admin {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px 20px;
    padding: 12px 16px;
    border-top: 1px solid var(--border-subtle);
}

.admin:first-child {
    border-top: 0;
}

.admin-main {
    display: flex;
    flex: 1 1 240px;
    flex-direction: column;
    min-width: 0;
}

.admin-name {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    font-weight: var(--font-weight-semibold);
    overflow-wrap: anywhere;
}

.admin-main .hint {
    overflow-wrap: anywhere;
}

.admin-locked .admin-name {
    color: var(--muted);
}

.admin-status {
    min-width: 90px;
}

.admin-reason {
    font-size: var(--font-size-sm);
}

.footnote {
    font-size: var(--font-size-sm);
}
</style>
