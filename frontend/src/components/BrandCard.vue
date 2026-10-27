<script setup lang="ts">
import { computed } from 'vue';
import type { TenantInfo } from '../api/tenant';

const props = defineProps<{
    isService: boolean;
    tenant: TenantInfo | null;
    /** Solange die Stadt lädt, kein Bild (sonst zuerst das Standard-Symbol, dann das Logo). */
    loading: boolean;
}>();

/** Logo der Stadt (512 px, versioniert), sonst das Standard-Symbol (relativ zu <base>). */
const logo = computed(() => props.tenant?.logoUrls?.['512'] ?? 'api/v1/app-icon/512');
const name = computed(() =>
    props.isService ? 'Super-Admin' : (props.tenant?.name ?? 'Feuerwehr'),
);
</script>

<template>
    <div class="brand">
        <img
            v-if="!loading"
            class="brand-logo"
            :src="logo"
            alt=""
            width="88"
            height="88"
            data-testid="brand-logo"
        />
        <div class="brand-text">
            <p class="brand-name" data-testid="brand-name">{{ name }}</p>
            <p class="brand-sub">Geräteverwaltung</p>
        </div>
    </div>
</template>

<style scoped>
.brand {
    display: flex;
    align-items: center;
    gap: 16px;
    min-height: 88px;
}

.brand-logo {
    flex: none;
    border-radius: var(--radius-lg);
    background: var(--surface);
    box-shadow: var(--shadow-group);
}

.brand-text p {
    margin: 0;
}

.brand-name {
    font-size: var(--font-size-xl);
    font-weight: var(--font-weight-bold);
    border-left: 4px solid var(--accent);
    padding-left: 10px;
}

.brand-sub {
    padding-left: 14px;
    color: var(--muted);
}
</style>
