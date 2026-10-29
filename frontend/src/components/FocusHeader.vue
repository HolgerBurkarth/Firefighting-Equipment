<script setup lang="ts">
import { onMounted, useTemplateRef } from 'vue';
import { useRouter, type RouteLocationRaw } from 'vue-router';
import { goBack } from '../router/back';

const props = withDefaults(
    defineProps<{
        title: string;
        subtitle?: string | null;
        /** Ziel, wenn es keine vorige Seite der App gibt (direkt geöffneter Link). */
        fallback?: RouteLocationRaw;
        backLabel?: string;
        /** Statt goBack, z. B. eine Ansicht per Parameter der Adresse schließen. */
        backAction?: (() => Promise<void>) | null;
        /** Fokus beim Anzeigen auf den Titel (Ansicht gewechselt, z. B. für Bildschirmleser). */
        focusTitle?: boolean;
    }>(),
    {
        subtitle: null,
        fallback: () => ({ name: 'search' }),
        backLabel: 'Zurück',
        backAction: null,
        focusTitle: false,
    },
);

const titleElement = useTemplateRef<HTMLHeadingElement>('titleElement');

onMounted(() => {
    if (props.focusTitle) {
        titleElement.value?.focus();
    }
});

const router = useRouter();

function onBack(): void {
    void (props.backAction ?? (() => goBack(router, props.fallback)))();
}
</script>

<!-- Kopfzeile der bildschirmfüllenden Seiten (Layout "focus"): Zurück, Titel, Zusatzzeile. -->
<template>
    <header class="focus-header">
        <button class="focus-back" type="button" :aria-label="backLabel" @click="onBack">
            <svg
                width="24"
                height="24"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                aria-hidden="true"
            >
                <path d="M15 5l-7 7 7 7" />
            </svg>
        </button>
        <div class="focus-text">
            <h1 ref="titleElement" class="focus-title" :tabindex="focusTitle ? -1 : undefined">
                {{ title }}
            </h1>
            <span v-if="subtitle !== null" class="focus-subtitle">{{ subtitle }}</span>
        </div>
    </header>
</template>

<style scoped>
.focus-header {
    position: sticky;
    top: 0;
    z-index: 1;
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 12px 8px;
    background: var(--surface);
    border-bottom: 1px solid var(--border);
}

.focus-back {
    display: flex;
    flex: none;
    align-items: center;
    justify-content: center;
    width: var(--touch-min);
    height: var(--touch-min);
    border: 0;
    border-radius: var(--radius-sm);
    background: none;
    color: var(--text);
    cursor: pointer;
}

.focus-back:hover {
    background: var(--nav-hover);
}

.focus-text {
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.focus-title {
    font-size: 20px;
    overflow-wrap: anywhere;
}

.focus-subtitle {
    font-size: var(--font-size-sm);
    color: var(--muted);
}
</style>
