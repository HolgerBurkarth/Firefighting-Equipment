<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import type { ApiError } from '../api/client';
import { ROLE_LABELS, userLabel } from '../api/auth';
import { logout, session } from '../auth/session';
import { bookingQueue } from '../data';
import { unsentWarning } from '../data/bookingQueue';
import ConfirmDialog from './ConfirmDialog.vue';
import ErrorNotice from './ErrorNotice.vue';

const router = useRouter();
const busy = ref(false);
const error = ref<ApiError | null>(null);

const label = computed(() => (session.value === null ? '' : userLabel(session.value.user)));
const role = computed(() => (session.value === null ? '' : ROLE_LABELS[session.value.user.role]));

/** Ungesendete Buchungen: erst fragen (KONZEPT.md, "Offline-Modus – Entscheidungen", 55). */
const confirmOpen = ref(false);
const warning = computed(() => unsentWarning(bookingQueue.unsent.length));

function askLogout(): void {
    if (warning.value !== null) {
        confirmOpen.value = true;
        return;
    }
    void onLogout();
}

async function onLogout(): Promise<void> {
    busy.value = true;
    error.value = await logout();
    busy.value = false;
    if (error.value === null) {
        await router.push({ name: 'login' });
    }
}
</script>

<template>
    <section v-if="session !== null" class="card" aria-label="Angemeldetes Konto">
        <p class="account-text">
            Angemeldet als <strong data-testid="account-name">{{ label }}</strong>
            <span class="account-role">({{ role }})</span>
        </p>
        <ErrorNotice v-if="error !== null" :error="error" />
        <button class="btn" type="button" :disabled="busy" @click="askLogout">Abmelden</button>
        <ConfirmDialog
            v-model:open="confirmOpen"
            title="Trotzdem abmelden?"
            :description="warning ?? ''"
            confirm-label="Abmelden"
            @confirm="onLogout"
        />
    </section>
</template>

<style scoped>
.account-text {
    margin: 0;
}

.account-role {
    color: var(--muted);
}
</style>
