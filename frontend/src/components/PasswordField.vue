<script setup lang="ts">
import { ref, useTemplateRef } from 'vue';

const model = defineModel<string>({ required: true });

defineProps<{
    id: string;
    /** current-password (Anmelden) oder new-password (setzen), für Passwort-Manager. */
    autocomplete: 'current-password' | 'new-password';
    invalid?: boolean;
    describedby?: string | undefined;
}>();

const visible = ref(false);
const input = useTemplateRef<HTMLInputElement>('input');

defineExpose({
    focus: () => {
        input.value?.focus();
    },
});
</script>

<!-- Passwortfeld mit Auge-Knopf (Umschalter mit fester Beschriftung und aria-pressed). -->
<template>
    <div class="password">
        <input
            :id="id"
            ref="input"
            v-model="model"
            class="input password-input"
            :type="visible ? 'text' : 'password'"
            :autocomplete="autocomplete"
            autocapitalize="none"
            spellcheck="false"
            :aria-invalid="invalid ? 'true' : undefined"
            :aria-describedby="describedby"
        />
        <button
            class="password-toggle"
            type="button"
            aria-label="Passwort anzeigen"
            :aria-pressed="visible"
            :aria-controls="id"
            data-testid="password-toggle"
            @click="visible = !visible"
        >
            <svg
                width="24"
                height="24"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
            >
                <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z" />
                <circle cx="12" cy="12" r="3" />
                <path v-if="visible" d="M4 4l16 16" />
            </svg>
        </button>
    </div>
</template>

<style scoped>
.password {
    position: relative;
    display: flex;
}

.password-input {
    flex: 1;
    min-width: 0;
    padding-right: calc(var(--touch-min) + 16px);
}

/* Eigener Knopf statt des eingebauten von Edge */
.password-input::-ms-reveal {
    display: none;
}

.password-toggle {
    position: absolute;
    top: 0;
    right: 6px;
    bottom: 0;
    margin: auto 0;
    display: flex;
    align-items: center;
    justify-content: center;
    /* etwas über der Mindestgröße (WebKit rundet an Bruchteil-Positionen ab) */
    width: calc(var(--touch-min) + 4px);
    height: calc(var(--touch-min) + 4px);
    border: 0;
    border-radius: var(--radius-sm);
    background: transparent;
    color: var(--muted);
    cursor: pointer;
}

.password-toggle:hover,
.password-toggle[aria-pressed='true'] {
    color: var(--text);
    background: var(--nav-hover);
}
</style>
