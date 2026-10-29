<script setup lang="ts">
import { computed } from 'vue';
import { session } from '../auth/session';

/** Nur bei Handy-Breite (z. B. auf dem Dashboard, das am PC ein eigenes Menü bekommt). */
defineProps<{ mobileOnly?: boolean }>();

/** Admins öffnen die Handy-Seiten auch; für sie zusätzlich der Weg zurück zum Dashboard. */
const isAdmin = computed(() => session.value?.user.role === 'admin');
</script>

<!-- Leiste unten der Handy-Seiten (Layout "user"): Suchen, Prüffristen, Profil. -->
<template>
    <nav class="tab-bar" :class="{ 'tab-bar-mobile-only': mobileOnly }" aria-label="Hauptmenü">
        <div class="tab-bar-inner">
            <RouterLink v-if="isAdmin" class="tab" :to="{ name: 'dashboard' }">
                <svg
                    width="24"
                    height="24"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    aria-hidden="true"
                >
                    <rect x="4" y="4" width="7" height="7" rx="1" />
                    <rect x="13" y="4" width="7" height="7" rx="1" />
                    <rect x="4" y="13" width="7" height="7" rx="1" />
                    <rect x="13" y="13" width="7" height="7" rx="1" />
                </svg>
                Dashboard
            </RouterLink>
            <RouterLink class="tab" :to="{ name: 'search' }">
                <svg
                    width="24"
                    height="24"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    aria-hidden="true"
                >
                    <circle cx="11" cy="11" r="7" />
                    <path d="M20 20l-4-4" />
                </svg>
                Suchen
            </RouterLink>
            <RouterLink class="tab" :to="{ name: 'inspections' }">
                <svg
                    width="24"
                    height="24"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    aria-hidden="true"
                >
                    <rect x="4" y="5" width="16" height="16" rx="2" />
                    <path d="M8 3v4" />
                    <path d="M16 3v4" />
                    <path d="M4 10h16" />
                </svg>
                Prüffristen
            </RouterLink>
            <RouterLink class="tab" :to="{ name: 'profile' }">
                <svg
                    width="24"
                    height="24"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    aria-hidden="true"
                >
                    <circle cx="12" cy="8" r="4" />
                    <path d="M4 21c1-4 4-6 8-6s7 2 8 6" />
                </svg>
                Profil
            </RouterLink>
        </div>
    </nav>
</template>

<style scoped>
.tab-bar {
    position: sticky;
    bottom: 0;
    z-index: 1;
    padding-bottom: max(10px, env(safe-area-inset-bottom));
    background: var(--surface);
    border-top: 1px solid var(--border);
}

.tab-bar-inner {
    display: flex;
    max-width: 600px;
    margin: 0 auto;
}

.tab {
    display: flex;
    flex: 1 1 0;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 2px;
    min-height: var(--touch-large);
    font-size: var(--font-size-xs);
    color: var(--muted);
    text-decoration: none;
}

.tab:hover {
    color: var(--text);
    background: var(--surface-hover);
}

@media (min-width: 768px) {
    .tab-bar-mobile-only {
        display: none;
    }
}

/* Aktiver Reiter in der Stadtfarbe (dunkel aufgehellt, siehe --line in tokens.css). */
.tab.router-link-active {
    color: var(--line);
    font-weight: var(--font-weight-bold);
}
</style>
