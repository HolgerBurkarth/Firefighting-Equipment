<script setup lang="ts">
import {
    SelectContent,
    SelectIcon,
    SelectItem,
    SelectItemIndicator,
    SelectItemText,
    SelectPortal,
    SelectRoot,
    SelectTrigger,
    SelectValue,
} from 'reka-ui';
import { useId } from 'vue';

/** Eintrag der Auswahl; `level` 2 = eingerückt (z. B. Fach unter seinem Fahrzeug). */
export interface SelectOption {
    readonly value: string;
    readonly label: string;
    readonly level?: 1 | 2;
}

/**
 * Auswahlliste mit Reka UI `Select` statt nativem `<select>` (CLAUDE.md, CSP): Knopf mit dem
 * gewählten Eintrag, Liste mit Tastatur (Pfeile, Anfangsbuchstaben, Escape). Bewusst ohne `name`:
 * Mit `name` in einem Formular legte Reka UI ein verstecktes natives `<select>` an. Bewusst ohne
 * `SelectViewport`: Er fügt ein `<style>`-Element ein (Bildlaufleiste ausblenden), das die CSP
 * blockiert; er ist nur für `position="item-aligned"` und die Scroll-Knöpfe nötig. Die Liste scrollt
 * hier in `SelectContent` selbst.
 */
defineProps<{
    label: string;
    options: readonly SelectOption[];
    /** Beschriftung nur für Bildschirmleser (z. B. „Sortieren“ neben einem sichtbaren Hinweis). */
    hideLabel?: boolean;
    /** Text, solange nichts gewählt ist (Wert ''). */
    placeholder?: string;
}>();
const value = defineModel<string>({ required: true });
const labelId = useId();
</script>

<template>
    <div class="app-select">
        <span :id="labelId" class="field-label" :class="{ 'visually-hidden': hideLabel }">{{
            label
        }}</span>
        <SelectRoot v-model="value">
            <SelectTrigger class="select-trigger" :aria-labelledby="labelId">
                <SelectValue class="select-value" :placeholder="placeholder ?? ''" />
                <SelectIcon class="select-icon" aria-hidden="true">▾</SelectIcon>
            </SelectTrigger>
            <SelectPortal>
                <SelectContent class="select-content" position="popper" :side-offset="4">
                    <SelectItem
                        v-for="option in options"
                        :key="option.value"
                        class="select-item"
                        :class="{ 'select-item-sub': option.level === 2 }"
                        :value="option.value"
                        :text-value="option.label"
                    >
                        <SelectItemText>{{ option.label }}</SelectItemText>
                        <SelectItemIndicator class="select-check" aria-hidden="true">
                            ✓
                        </SelectItemIndicator>
                    </SelectItem>
                </SelectContent>
            </SelectPortal>
        </SelectRoot>
    </div>
</template>

<style scoped>
.app-select {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 0;
}

.select-trigger {
    display: inline-flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    width: 100%;
    min-height: var(--touch-min);
    padding: 0 12px;
    border: 1px solid var(--input-border);
    border-radius: var(--radius-sm);
    background: var(--surface);
    color: var(--text);
    font: inherit;
    text-align: left;
    cursor: pointer;
}

.select-trigger:hover {
    border-color: var(--line);
}

.select-value {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.select-icon {
    color: var(--muted);
}
</style>

<!-- Die Liste liegt im Portal (außerhalb der Komponente), daher ohne scoped. -->
<style>
.select-content {
    z-index: 50;
    min-width: var(--reka-select-trigger-width);
    max-height: min(420px, var(--reka-select-content-available-height));
    padding: 4px;
    overflow-y: auto;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface);
    color: var(--text);
    box-shadow: var(--shadow-raised);
}

.select-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    min-height: var(--touch-min);
    padding: 0 12px;
    border-radius: var(--radius-sm);
    outline: none;
    cursor: pointer;
    user-select: none;
}

.select-item-sub {
    padding-left: 32px;
}

.select-item[data-highlighted] {
    background: var(--nav-hover);
}

.select-item[data-state='checked'] {
    font-weight: var(--font-weight-semibold);
}

.select-check {
    color: var(--line);
}
</style>
