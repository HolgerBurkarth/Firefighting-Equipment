<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import type { CreatedTenant } from '../api/tenants';
import ErrorNotice from '../components/ErrorNotice.vue';
import NewTenantDialog from '../components/NewTenantDialog.vue';
import TenantTable from '../components/TenantTable.vue';
import { tenantList } from '../data/service';
import { countText, createdTenantText } from '../data/tenants';

/**
 * Städte des Super-Admins (KONZEPT.md, "Super-Admin – Entscheidungen", 42): Suche in Name und
 * Kürzel, Tabelle (am Handy Karten) mit Status und Zählern; „Neue Stadt“ (Entscheidung 43).
 */
const state = computed(() => tenantList.state);
const tenants = computed(() => state.value.items ?? []);
const query = ref('');
const created = ref<{ text: string; ok: boolean } | null>(null);
const newOpen = ref(false);
const opener = ref<HTMLElement | null>(null);

function openNew(event: Event): void {
    opener.value = event.currentTarget instanceof HTMLElement ? event.currentTarget : null;
    created.value = null;
    newOpen.value = true;
}

function onCreated(result: CreatedTenant): void {
    created.value = createdTenantText(result);
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
        <h1>Städte</h1>
        <button class="btn btn-accent" type="button" @click="openNew">Neue Stadt</button>
    </div>

    <p
        v-if="created !== null"
        class="notice"
        :class="created.ok ? 'notice-success' : 'notice-error'"
        :role="created.ok ? 'status' : 'alert'"
        data-testid="created-notice"
    >
        {{ created.text }}
    </p>

    <div class="toolbar">
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
            <span class="visually-hidden">Städte filtern</span>
            <input v-model="query" type="search" placeholder="Filtern, z. B. Immendingen" />
        </label>
        <span v-if="state.items !== null" class="hint" data-testid="tenant-count">
            {{ countText(tenants.length, 'Stadt', 'Städte') }}
        </span>
    </div>

    <ErrorNotice v-if="state.error !== null" :error="state.error">
        <button class="btn retry" type="button" :disabled="state.loading" @click="retry">
            Erneut versuchen
        </button>
    </ErrorNotice>
    <p v-if="state.items === null && state.error === null" class="hint" role="status">
        Wird geladen …
    </p>
    <p v-else-if="state.items !== null && tenants.length === 0" class="hint">
        Noch keine Stadt angelegt.
    </p>
    <TenantTable v-if="tenants.length > 0" :tenants="tenants" :query="query" />

    <NewTenantDialog v-model:open="newOpen" :return-focus="opener" @created="onCreated" />
</template>

<style scoped>
.hint {
    margin: 0;
    color: var(--muted);
}

.retry {
    align-self: flex-start;
}
</style>
