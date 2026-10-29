<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue';
import {
    applyThemeMode,
    browserStorage,
    loadThemeMode,
    saveThemeMode,
    type ThemeMode,
} from '../runtime/theme';

const OPTIONS: readonly { mode: ThemeMode; label: string; description: string }[] = [
    { mode: 'system', label: 'System', description: 'Wie am Gerät eingestellt (Standard)' },
    { mode: 'light', label: 'Hell', description: 'Immer helle Darstellung' },
    { mode: 'dark', label: 'Dunkel', description: 'Schont die Augen bei Nacht' },
];

const mode = ref<ThemeMode>(loadThemeMode(browserStorage()));

// Hinweis bei „System“: Wie ist das Gerät gerade eingestellt? Folgt dem Wechsel (z. B. abends).
const darkQuery =
    typeof window.matchMedia === 'function'
        ? window.matchMedia('(prefers-color-scheme: dark)')
        : null;
const systemDark = ref(darkQuery?.matches ?? false);
function onSystemChange(event: MediaQueryListEvent): void {
    systemDark.value = event.matches;
}
darkQuery?.addEventListener('change', onSystemChange);
onBeforeUnmount(() => darkQuery?.removeEventListener('change', onSystemChange));

/** Gilt sofort und nur auf diesem Gerät (localStorage), nicht je Konto. */
function choose(next: ThemeMode): void {
    mode.value = next;
    saveThemeMode(browserStorage(), next);
    applyThemeMode(document.documentElement, next);
}
</script>

<!-- Anzeigemodus wie im Mockup „Einstellungen“: System (Standard), Hell, Dunkel. -->
<template>
    <section class="card" aria-labelledby="theme-heading">
        <div class="heading">
            <h2 id="theme-heading">Anzeigemodus</h2>
            <p class="hint" data-testid="theme-hint">
                Wird auf diesem Gerät gespeichert.
                <template v-if="mode === 'system'">
                    Ihr Gerät ist gerade {{ systemDark ? 'dunkel' : 'hell' }} eingestellt.
                </template>
            </p>
        </div>
        <div class="modes" role="radiogroup" aria-labelledby="theme-heading">
            <label
                v-for="option in OPTIONS"
                :key="option.mode"
                class="mode"
                :class="{ 'mode-checked': mode === option.mode }"
            >
                <input
                    class="mode-input"
                    type="radio"
                    name="theme-mode"
                    :value="option.mode"
                    :checked="mode === option.mode"
                    @change="choose(option.mode)"
                />
                <span class="mode-text">
                    <span class="mode-label">{{ option.label }}</span>
                    <span class="mode-description">{{ option.description }}</span>
                </span>
            </label>
        </div>
    </section>
</template>

<style scoped>
.heading {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.hint {
    margin: 0;
    color: var(--muted);
    font-size: var(--font-size-md);
}

.modes {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 10px;
}

.mode {
    display: flex;
    align-items: center;
    gap: 12px;
    min-height: var(--touch-large);
    padding: 10px 14px;
    border: 1px solid var(--input-border);
    border-radius: var(--radius-md);
    background: var(--surface);
    cursor: pointer;
}

.mode:hover {
    background: var(--nav-hover);
}

.mode-checked {
    border: 2px solid var(--line);
    background: var(--selected-bg);
}

.mode:has(.mode-input:focus-visible) {
    outline: var(--focus-ring);
    outline-offset: 2px;
}

.mode-input {
    flex: 0 0 auto;
    width: 20px;
    height: 20px;
    margin: 0;
    accent-color: var(--line);
}

.mode-text {
    display: flex;
    flex-direction: column;
}

.mode-label {
    font-weight: var(--font-weight-bold);
}

.mode-description {
    color: var(--muted);
    font-size: var(--font-size-sm);
}
</style>
