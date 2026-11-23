<script setup lang="ts">
import { onMounted } from 'vue';
import { adminOverview } from '../data';
import { tenantState } from '../runtime/tenantState';
import AdminMenuNav from './AdminMenuNav.vue';
import MenuDrawer from './MenuDrawer.vue';

/**
 * Menü der Admin-Konsole (KONZEPT.md, "Admin-Konsole – Entscheidungen", 34): ab 768 px links (am
 * Tablet eingeklappt auf Symbole, ab 1024 px mit Text), darunter eine Kopfleiste in der Stadtfarbe
 * mit ☰, das eine Schublade öffnet (`MenuDrawer`, auch auf den Handy-Seiten von Konten mit
 * Konsole). Unter `service` dasselbe Menü mit den
 * Einträgen des Super-Admins (KONZEPT.md, "Super-Admin – Entscheidungen", 40).
 */
const props = defineProps<{
    /** Bereich des Super-Admins (`service`): eigenes Menü, keine Stadt, keine Zähler. */
    service: boolean;
}>();
// Zähler im Menü (wartende Freischaltungen, offene Hinweise) auf jeder Seite der Konsole.
onMounted(() => {
    if (!props.service) {
        void adminOverview.refresh('open');
    }
});
</script>

<template>
    <header class="admin-topbar">
        <MenuDrawer :service="service" />
        <div class="topbar-text">
            <span class="topbar-title" data-testid="app-title">
                {{ service ? 'Service' : (tenantState.tenant?.name ?? 'Feuerwehr') }}
            </span>
            <span class="topbar-subtitle">{{
                service ? 'Super-Admin' : 'Ausrüstungsverwaltung'
            }}</span>
        </div>
    </header>

    <aside class="admin-sidebar">
        <AdminMenuNav variant="sidebar" :service="service" />
    </aside>
</template>

<style scoped>
/* Am Handy fest oben (Benutzertest Gerätewart am Handy, G3): Das Menü bleibt auf langen Seiten
   erreichbar. Höhe = --admin-sticky-top in App.vue; unter den Fenstern (Abdunklung 10). */
.admin-topbar {
    position: sticky;
    z-index: 5;
    top: 0;
    display: flex;
    align-items: center;
    gap: 8px;
    height: var(--admin-sticky-top);
    padding: 0 8px;
    background: var(--accent);
    color: var(--on-accent);
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

/* Je eine Zeile, damit die feste Kopfleiste immer gleich hoch ist (--admin-sticky-top). */
.topbar-title,
.topbar-subtitle {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.topbar-subtitle {
    font-size: var(--font-size-sm);
    opacity: 0.85;
}

.admin-sidebar {
    display: none;
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
