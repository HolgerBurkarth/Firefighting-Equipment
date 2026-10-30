<script setup lang="ts">
import type { AdminMenuIconName } from '../router/adminMenu';

/** Symbole des Menüs der Admin-Konsole (Mockup „Main“). */
defineProps<{ name: AdminMenuIconName }>();
</script>

<template>
    <svg
        width="20"
        height="20"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        aria-hidden="true"
    >
        <template v-if="name === 'dashboard'">
            <rect x="3" y="3" width="7" height="7" rx="1" />
            <rect x="14" y="3" width="7" height="7" rx="1" />
            <rect x="3" y="14" width="7" height="7" rx="1" />
            <rect x="14" y="14" width="7" height="7" rx="1" />
        </template>
        <template v-else-if="name === 'search'">
            <circle cx="11" cy="11" r="7" />
            <path d="M20 20l-4-4" />
        </template>
        <template v-else-if="name === 'devices'">
            <path d="M21 8l-9-5-9 5 9 5 9-5z" />
            <path d="M3 8v8l9 5 9-5V8" />
        </template>
        <template v-else-if="name === 'location'">
            <path d="M12 21s-7-6.5-7-12a7 7 0 0 1 14 0c0 5.5-7 12-7 12z" />
            <circle cx="12" cy="9" r="2.5" />
        </template>
        <template v-else-if="name === 'tag'">
            <path d="M20 12l-8 8-9-9V3h8z" />
            <circle cx="7.5" cy="7.5" r="1.5" />
        </template>
        <template v-else-if="name === 'account'">
            <circle cx="12" cy="8" r="4" />
            <path d="M4 21c1-4 4-6 8-6s7 2 8 6" />
        </template>
        <template v-else>
            <path d="M15 4h4v16h-4" />
            <path d="M10 8l-4 4 4 4" />
            <path d="M6 12h10" />
        </template>
    </svg>
</template>
