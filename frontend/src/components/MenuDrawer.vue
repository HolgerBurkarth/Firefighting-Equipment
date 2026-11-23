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
import { ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { adminOverview } from '../data';
import AdminMenuNav from './AdminMenuNav.vue';

/**
 * ☰ und die Schublade mit dem Menü der Konsole (Reka UI Dialog: Fokus bleibt darin, Escape schließt,
 * danach zurück auf ☰). Ein Seitenwechsel schließt sie. In der Kopfleiste der Konsole am Handy
 * (`AdminMenu`) und seit 23.11.2026 auf den Handy-Seiten (Suchen, Termine, Profil) von Konten mit
 * Konsole (`App.vue`, Wunsch Holger); dort lädt erst das Öffnen die Zähler des Menüs.
 */
const props = defineProps<{
    /** Bereich des Super-Admins (`service`): eigenes Menü, keine Stadt, keine Zähler. */
    service: boolean;
}>();
const drawerOpen = ref(false);
const route = useRoute();

watch(
    () => route.fullPath,
    () => {
        drawerOpen.value = false;
    },
);

watch(drawerOpen, (open) => {
    if (open && !props.service) {
        void adminOverview.refresh('open');
    }
});
</script>

<template>
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
                    Seiten der Ausrüstungsverwaltung
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
                <AdminMenuNav variant="drawer" :service="service" @navigate="drawerOpen = false" />
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>

<style scoped>
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
</style>
