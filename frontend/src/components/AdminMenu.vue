<script setup lang="ts">
import {
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogOverlay,
    DialogPortal,
    DialogRoot,
    DialogTitle,
    DialogTrigger,
} from 'reka-ui';
import { onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { adminOverview } from '../data';
import { tenantState } from '../runtime/tenantState';
import AdminMenuNav from './AdminMenuNav.vue';

/**
 * Menü der Admin-Konsole (KONZEPT.md, "Admin-Konsole – Entscheidungen", 34): ab 768 px links (am
 * Tablet eingeklappt auf Symbole, ab 1024 px mit Text), darunter eine Kopfleiste in der Stadtfarbe
 * mit ☰, das eine Schublade öffnet (Reka UI Dialog: Fokus bleibt darin, Escape schließt, danach
 * zurück auf ☰). Ein Seitenwechsel schließt die Schublade.
 */
const drawerOpen = ref(false);
const route = useRoute();

watch(
    () => route.fullPath,
    () => {
        drawerOpen.value = false;
    },
);

// Zähler im Menü (wartende Freischaltungen, offene Hinweise) auf jeder Seite der Konsole.
onMounted(() => {
    void adminOverview.refresh('open');
});
</script>

<template>
    <header class="admin-topbar">
        <DialogRoot v-model:open="drawerOpen">
            <DialogTrigger class="topbar-button" aria-label="Menü öffnen">
                <svg
                    width="24"
                    height="24"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    aria-hidden="true"
                >
                    <path d="M4 6h16" />
                    <path d="M4 12h16" />
                    <path d="M4 18h16" />
                </svg>
            </DialogTrigger>
            <DialogPortal>
                <DialogOverlay class="drawer-overlay" />
                <DialogContent class="drawer" data-testid="menu-drawer">
                    <DialogTitle class="visually-hidden">Menü</DialogTitle>
                    <DialogDescription class="visually-hidden">
                        Seiten der Geräteverwaltung
                    </DialogDescription>
                    <DialogClose class="drawer-close" aria-label="Menü schließen">
                        <svg
                            width="22"
                            height="22"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            aria-hidden="true"
                        >
                            <path d="M6 6l12 12" />
                            <path d="M18 6L6 18" />
                        </svg>
                    </DialogClose>
                    <AdminMenuNav variant="drawer" @navigate="drawerOpen = false" />
                </DialogContent>
            </DialogPortal>
        </DialogRoot>
        <div class="topbar-text">
            <span class="topbar-title" data-testid="app-title">
                {{ tenantState.tenant?.name ?? 'Feuerwehr' }}
            </span>
            <span class="topbar-subtitle">Geräteverwaltung</span>
        </div>
    </header>

    <aside class="admin-sidebar">
        <AdminMenuNav variant="sidebar" />
    </aside>
</template>

<style scoped>
.admin-topbar {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 8px;
    background: var(--accent);
    color: var(--on-accent);
}

.topbar-button {
    display: inline-flex;
    flex: none;
    align-items: center;
    justify-content: center;
    width: var(--touch-min);
    height: var(--touch-min);
    border: 0;
    border-radius: var(--radius-sm);
    background: none;
    color: inherit;
    cursor: pointer;
}

.topbar-button:hover {
    background: var(--on-accent-soft);
}

.topbar-button:focus-visible {
    outline-color: var(--on-accent);
}

.topbar-text {
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.topbar-title {
    font-size: 18px;
    font-weight: var(--font-weight-bold);
}

.topbar-subtitle {
    font-size: var(--font-size-sm);
    opacity: 0.85;
}

.admin-sidebar {
    display: none;
}

.drawer-overlay {
    position: fixed;
    inset: 0;
    background: var(--overlay);
}

.drawer {
    position: fixed;
    top: 0;
    bottom: 0;
    left: 0;
    width: min(300px, calc(100vw - 48px));
    overflow-y: auto;
    background: var(--surface);
    color: var(--text);
    box-shadow: var(--shadow-raised);
}

.drawer-close {
    position: absolute;
    top: 12px;
    right: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: var(--touch-min);
    height: var(--touch-min);
    border: 0;
    border-radius: var(--radius-sm);
    background: none;
    color: var(--text-secondary);
    cursor: pointer;
}

.drawer-close:hover {
    background: var(--nav-hover);
}

/* Platz für „Menü schließen“ neben dem Namen der Stadt. */
.drawer :deep(.menu-brand) {
    padding-right: 48px;
}

@media (min-width: 768px) {
    .admin-topbar {
        display: none;
    }

    .admin-sidebar {
        position: sticky;
        top: 0;
        display: block;
        flex: none;
        width: 76px;
        height: 100dvh;
        overflow-y: auto;
        background: var(--surface);
        border-right: 1px solid var(--border);
    }
}

@media (min-width: 1024px) {
    .admin-sidebar {
        width: 240px;
    }
}
</style>
