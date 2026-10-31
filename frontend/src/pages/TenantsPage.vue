<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import ErrorNotice from '../components/ErrorNotice.vue';
import TenantTable from '../components/TenantTable.vue';
import { tenantList } from '../data/service';
import { countText } from '../data/tenants';

/**
 * Städte des Super-Admins (KONZEPT.md, "Super-Admin – Entscheidungen", 42): Suche in Name und
 * Kürzel, Tabelle (am Handy Karten) mit Status und Zählern.
 */
const state = computed(() => tenantList.state);
const tenants = computed(() => state.value.items ?? []);
const query = ref('');

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
    </div>

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
