<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import { userLabel } from '../api/auth';
import type { ApiError } from '../api/client';
import { logout, session } from '../auth/session';
import { adminOverview } from '../data';
import { ADMIN_MENU, type AdminMenuBadge } from '../router/adminMenu';
import { tenantState } from '../runtime/tenantState';
import AdminMenuIcon from './AdminMenuIcon.vue';
import ErrorNotice from './ErrorNotice.vue';

/**
 * Inhalt des Menüs der Admin-Konsole: links am PC (`sidebar`, am Tablet eingeklappt auf Symbole)
 * bzw. in der Schublade am Handy (`drawer`). Unten der Name des Kontos als Text und „Abmelden“.
 */
defineProps<{ variant: 'sidebar' | 'drawer' }>();
const emit = defineEmits<{ navigate: [] }>();

const router = useRouter();
const busy = ref(false);
const error = ref<ApiError | null>(null);

const accountName = computed(() => (session.value === null ? '' : userLabel(session.value.user)));
const logo = computed(() => tenantState.value.tenant?.logoUrls?.['192'] ?? null);

function badgeCount(badge: AdminMenuBadge | undefined): number {
    const overview = adminOverview.state.overview;
    if (badge === undefined || overview === null) {
        return 0;
    }
    return badge === 'pendingUsers' ? overview.pendingUsers : (overview.summary.openNotices ?? 0);
}

const BADGE_LABELS: Readonly<Record<AdminMenuBadge, (n: number) => string>> = {
    pendingUsers: (n) => `${String(n)} warten auf Freischaltung`,
    openNotices: (n) => (n === 1 ? '1 offener Hinweis' : `${String(n)} offene Hinweise`),
};

async function onLogout(): Promise<void> {
    busy.value = true;
    error.value = await logout();
    busy.value = false;
    if (error.value === null) {
        emit('navigate');
        await router.push({ name: 'login' });
    }
}
</script>

<template>
    <nav class="menu" :class="`menu-${variant}`" aria-label="Hauptmenü">
        <div class="menu-brand">
            <img v-if="logo !== null" class="menu-logo" :src="logo" alt="" width="44" height="44" />
            <div v-else class="menu-logo menu-logo-default">
                <svg
                    width="24"
                    height="24"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linejoin="round"
                    aria-hidden="true"
                >
                    <path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6z" />
                </svg>
            </div>
            <div class="menu-brand-text">
                <span class="menu-brand-name" data-testid="menu-tenant">
                    {{ tenantState.tenant?.name ?? 'Feuerwehr' }}
                </span>
                <span class="menu-brand-sub">Geräteverwaltung</span>
            </div>
        </div>

        <RouterLink
            v-for="entry in ADMIN_MENU"
            :key="entry.route"
            class="menu-link"
            active-class="menu-link-active"
            :to="{ name: entry.route }"
            :title="entry.label"
            @click="emit('navigate')"
        >
            <AdminMenuIcon :name="entry.icon" />
            <span class="menu-label">{{ entry.label }}</span>
            <span
                v-if="entry.badge !== undefined && badgeCount(entry.badge) > 0"
                class="menu-count"
                :aria-label="BADGE_LABELS[entry.badge](badgeCount(entry.badge))"
                data-testid="menu-count"
            >
                {{ badgeCount(entry.badge) }}
            </span>
        </RouterLink>

        <div class="menu-spacer" />

        <div class="menu-footer">
            <p class="menu-account" :title="accountName">
                <AdminMenuIcon name="account" />
                <span class="menu-label" data-testid="account-name">{{ accountName }}</span>
            </p>
            <button
                class="menu-link menu-logout"
                type="button"
                title="Abmelden"
                :disabled="busy"
                @click="onLogout"
            >
                <AdminMenuIcon name="logout" />
                <span class="menu-label">Abmelden</span>
            </button>
            <ErrorNotice v-if="error !== null" :error="error" />
        </div>
    </nav>
</template>

<style scoped>
.menu {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-height: 100%;
    padding: 16px;
}

.menu-brand {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 6px 6px 18px;
}

.menu-logo {
    display: flex;
    flex: none;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    border-radius: var(--radius-md);
}

.menu-logo-default {
    background: var(--accent);
    color: var(--on-accent);
}

.menu-brand-text {
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.menu-brand-name {
    font-weight: var(--font-weight-bold);
}

.menu-brand-sub {
    font-size: var(--font-size-sm);
    color: var(--muted);
}

.menu-link,
.menu-account {
    display: flex;
    align-items: center;
    gap: 12px;
    min-height: var(--touch-min);
    margin: 0;
    padding: 10px 14px;
    border: 0;
    border-radius: var(--radius-sm);
    background: none;
    color: var(--text-secondary);
    font: inherit;
    font-size: var(--font-size-base);
    text-align: left;
    text-decoration: none;
}

.menu-link {
    cursor: pointer;
}

.menu-link svg,
.menu-account svg {
    flex: none;
}

/* Hover am PC deutlich: kräftig hinterlegt mit Randbalken links (KONZEPT.md, Grundsätze). */
.menu-link:hover:not(:disabled) {
    background: var(--nav-hover);
    box-shadow: inset 4px 0 0 var(--line);
    color: var(--text);
}

.menu-link-active {
    background: var(--nav-active);
    color: var(--text);
    font-weight: var(--font-weight-semibold);
}

.menu-count {
    margin-left: auto;
    padding: 1px 8px;
    border-radius: var(--radius-pill);
    background: var(--status-defective-fg);
    color: var(--surface);
    font-size: var(--font-size-xs);
    font-weight: var(--font-weight-bold);
}

.menu-spacer {
    flex-grow: 1;
    min-height: 24px;
}

.menu-footer {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding-top: 8px;
    border-top: 1px solid var(--border);
}

.menu-account {
    color: var(--muted);
}

/* Tablet: eingeklappt auf Symbole; Texte bleiben für Bildschirmleser, Tooltip über title. */
@media (min-width: 768px) and (max-width: 1023.98px) {
    .menu-sidebar {
        align-items: center;
        padding: 16px 10px;
    }

    .menu-sidebar .menu-brand {
        padding: 6px 0 18px;
    }

    .menu-sidebar .menu-brand-text,
    .menu-sidebar .menu-label {
        position: absolute;
        width: 1px;
        height: 1px;
        overflow: hidden;
        clip-path: inset(50%);
        white-space: nowrap;
    }

    .menu-sidebar .menu-link,
    .menu-sidebar .menu-account {
        position: relative;
        justify-content: center;
        width: var(--touch-min);
        padding: 10px 0;
    }

    .menu-sidebar .menu-footer {
        align-items: center;
        align-self: stretch;
    }

    .menu-sidebar .menu-count {
        position: absolute;
        top: 2px;
        right: -4px;
        margin: 0;
        padding: 0 6px;
    }
}
</style>
