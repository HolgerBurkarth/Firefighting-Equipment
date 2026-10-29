<script setup lang="ts">
import { computed, onMounted, ref, useTemplateRef, watch } from 'vue';
import type { ApiError } from '../api/client';
import type { Location, LocationKind } from '../api/locations';
import {
    deviceCountText,
    groupOf,
    locationGroups,
    searchLocations,
    type PickerEntry,
} from '../data/locationSearch';
import { searchTokens } from '../data/deviceSearch';
import ErrorNotice from './ErrorNotice.vue';

const props = defineProps<{
    /** Standortbaum (null, solange er noch nie geladen wurde). */
    locations: readonly Location[] | null;
    loading: boolean;
    error: ApiError | null;
    /** Aktueller Standort des Geräts (Markierung „aktuell“). */
    currentId: number;
    /** Gewählter Standort (Häkchen). */
    selectedId: number;
    /** Zusatzzeile der Kopfzeile, z. B. „M1 · Atemschutzmaske“. */
    deviceLabel: string;
}>();

const emit = defineEmits<{ pick: [id: number]; close: []; retry: [] }>();

const heading = useTemplateRef<HTMLHeadingElement>('heading');
const query = ref('');
const searching = computed(() => searchTokens(query.value).length > 0);
const groups = computed(() => locationGroups(props.locations ?? []));
const hits = computed(() => searchLocations(props.locations ?? [], query.value));

/** Aufgeklappt: zu Beginn die Gruppe des gewählten Standorts (Mockup UserStandortwahl). */
const expanded = ref(new Set<number>());

function expandSelected(): void {
    const group = groupOf(props.locations ?? [], props.selectedId);
    if (group !== null) {
        expanded.value = new Set([group]);
    }
}

onMounted(() => {
    expandSelected();
    // Bildschirmleser: Die Ansicht hat gewechselt (die Tastatur am Handy bleibt zu).
    heading.value?.focus();
});

function toggle(id: number): void {
    const next = new Set(expanded.value);
    if (!next.delete(id)) {
        next.add(id);
    }
    expanded.value = next;
}

// Standorte kommen erst nach dem Öffnen: dann die Gruppe des gewählten aufklappen.
watch(
    () => props.locations,
    (locations, previous) => {
        if (locations !== null && previous === null && expanded.value.size === 0) {
            expandSelected();
        }
    },
);

const KIND_ICONS: Readonly<Record<LocationKind, readonly string[]>> = {
    vehicle: [
        'M2 16V7h12v9',
        'M14 10h4l3 3v3h-7',
        'M4.5 17a2 2 0 1 0 4 0a2 2 0 1 0 -4 0',
        'M15.5 17a2 2 0 1 0 4 0a2 2 0 1 0 -4 0',
    ],
    room: ['M3 21h18', 'M5 21V8l7-4 7 4v13', 'M10 21v-5h4v5'],
    external: ['M14 4h6v6', 'M20 4l-9 9', 'M18 14v6H4V6h6'],
};
/** Fach (Unterstandort). */
const SUB_ICON = [
    'M4 7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z',
    'M4 11h16',
];

function icon(entry: PickerEntry): readonly string[] {
    return entry.level === 2 ? SUB_ICON : KIND_ICONS[entry.kind];
}

function countText(entry: PickerEntry): string {
    return entry.deviceCount === null ? '' : deviceCountText(entry.deviceCount);
}

const listHint = computed(() => {
    if (!searching.value) {
        return 'Fahrzeug antippen klappt die Fächer auf. Fach antippen wählt es.';
    }
    return hits.value.length === 1 ? '1 Treffer' : `${String(hits.value.length)} Treffer`;
});
</script>

<!-- Standortwahl beim Umbuchen (Mockup UserStandortwahl), bildschirmfüllend. -->
<template>
    <header class="picker-header">
        <button
            class="picker-close"
            type="button"
            aria-label="Abbrechen, zurück zum Umbuchen"
            @click="emit('close')"
        >
            <svg
                width="24"
                height="24"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                aria-hidden="true"
            >
                <path d="M6 6l12 12" />
                <path d="M18 6L6 18" />
            </svg>
        </button>
        <div class="picker-title">
            <h1 ref="heading" tabindex="-1" class="picker-heading">Standort wählen</h1>
            <span class="picker-subtitle">für {{ deviceLabel }}</span>
        </div>
    </header>

    <div class="picker-search">
        <label class="picker-field">
            <svg
                width="20"
                height="20"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                aria-hidden="true"
            >
                <circle cx="11" cy="11" r="7" />
                <path d="M20 20l-4-4" />
            </svg>
            <span class="visually-hidden">Standort suchen</span>
            <input
                v-model="query"
                class="picker-input"
                type="search"
                placeholder="Suchen, z. B. HLF oder G1"
                autocomplete="off"
                autocapitalize="off"
                spellcheck="false"
                enterkeyhint="search"
            />
        </label>
        <span v-if="locations !== null" class="hint" role="status" data-testid="picker-hint">{{
            listHint
        }}</span>
    </div>

    <div class="picker-list">
        <template v-if="locations === null">
            <p v-if="error === null" class="hint" role="status">Standorte werden geladen …</p>
            <ErrorNotice v-else :error="error">
                <button class="btn" type="button" :disabled="loading" @click="emit('retry')">
                    Erneut versuchen
                </button>
            </ErrorNotice>
        </template>

        <!-- Mit Suchbegriff: flache Trefferliste, Fächer mit ihrem Fahrzeug darunter. -->
        <template v-else-if="searching">
            <p v-if="hits.length === 0" class="picker-empty">
                Kein Standort passt zu „{{ query.trim() }}“.
            </p>
            <div
                v-for="entry in hits"
                :key="entry.id"
                class="group"
                :class="{ 'group-selected': entry.id === selectedId }"
            >
                <button
                    class="entry entry-head"
                    type="button"
                    data-testid="picker-entry"
                    @click="emit('pick', entry.id)"
                >
                    <svg
                        class="entry-icon"
                        width="22"
                        height="22"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        aria-hidden="true"
                    >
                        <path v-for="d in icon(entry)" :key="d" :d="d" />
                    </svg>
                    <span class="entry-text">
                        <span class="entry-label">{{ entry.label }}</span>
                        <span v-if="entry.parentName !== null" class="entry-parent">{{
                            entry.parentName
                        }}</span>
                    </span>
                    <span v-if="entry.id === currentId" class="pill">aktuell</span>
                    <svg
                        v-if="entry.id === selectedId"
                        class="entry-check"
                        width="20"
                        height="20"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="3"
                        role="img"
                        aria-label="gewählt"
                    >
                        <path d="M5 12l5 5 9-10" />
                    </svg>
                    <span class="entry-count">{{ countText(entry) }}</span>
                </button>
            </div>
        </template>

        <!-- Ohne Suchbegriff: alle Standorte; mit Fächern klappt die ganze Zeile auf. -->
        <template v-else>
            <p v-if="groups.length === 0" class="picker-empty">Es gibt keine Standorte.</p>
            <div
                v-for="group in groups"
                :key="group.entry.id"
                class="group"
                :class="{
                    'group-expanded': expanded.has(group.entry.id),
                    'group-selected': group.children.length === 0 && group.entry.id === selectedId,
                }"
            >
                <button
                    class="entry entry-head"
                    type="button"
                    data-testid="picker-group"
                    :aria-expanded="
                        group.children.length === 0 ? undefined : expanded.has(group.entry.id)
                    "
                    @click="
                        group.children.length === 0
                            ? emit('pick', group.entry.id)
                            : toggle(group.entry.id)
                    "
                >
                    <svg
                        class="entry-icon"
                        width="22"
                        height="22"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        aria-hidden="true"
                    >
                        <path v-for="d in icon(group.entry)" :key="d" :d="d" />
                    </svg>
                    <span class="entry-text">
                        <span class="entry-label">{{ group.entry.label }}</span>
                    </span>
                    <template v-if="group.children.length === 0">
                        <span v-if="group.entry.id === currentId" class="pill">aktuell</span>
                        <svg
                            v-if="group.entry.id === selectedId"
                            class="entry-check"
                            width="20"
                            height="20"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="3"
                            role="img"
                            aria-label="gewählt"
                        >
                            <path d="M5 12l5 5 9-10" />
                        </svg>
                    </template>
                    <span class="entry-count">{{ countText(group.entry) }}</span>
                    <svg
                        v-if="group.children.length > 0"
                        class="entry-chevron"
                        width="20"
                        height="20"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2.5"
                        aria-hidden="true"
                    >
                        <path d="M9 6l6 6-6 6" />
                    </svg>
                </button>
                <div v-if="expanded.has(group.entry.id)" class="group-children">
                    <button
                        v-for="child in group.children"
                        :key="`${String(child.id)}-${String(child.whole)}`"
                        class="entry entry-sub"
                        :class="{ 'entry-selected': child.id === selectedId }"
                        type="button"
                        data-testid="picker-entry"
                        @click="emit('pick', child.id)"
                    >
                        <svg
                            class="entry-icon"
                            width="18"
                            height="18"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            aria-hidden="true"
                        >
                            <path v-for="d in icon(child)" :key="d" :d="d" />
                        </svg>
                        <span class="entry-text">
                            <span class="entry-label">{{ child.label }}</span>
                        </span>
                        <span v-if="child.id === currentId" class="pill">aktuell</span>
                        <svg
                            v-if="child.id === selectedId"
                            class="entry-check"
                            width="20"
                            height="20"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="3"
                            role="img"
                            aria-label="gewählt"
                        >
                            <path d="M5 12l5 5 9-10" />
                        </svg>
                        <span class="entry-count">{{ countText(child) }}</span>
                    </button>
                </div>
            </div>
        </template>
    </div>
</template>

<style scoped>
.picker-header {
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

.picker-close {
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

.picker-close:hover {
    background: var(--nav-hover);
}

.picker-title {
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.picker-heading {
    font-size: 20px;
}

.picker-heading:focus {
    outline: none;
}

.picker-subtitle {
    font-size: var(--font-size-sm);
    color: var(--muted);
    overflow-wrap: anywhere;
}

.picker-search {
    display: flex;
    flex-direction: column;
    gap: 6px;
    padding: 12px 16px 8px;
}

.picker-field {
    display: flex;
    align-items: center;
    gap: 10px;
    min-height: 52px;
    padding: 0 12px;
    border: 2px solid var(--line);
    border-radius: var(--radius-lg);
    background: var(--surface);
    color: var(--muted);
}

.picker-input {
    flex: 1 1 auto;
    min-width: 0;
    min-height: 48px;
    border: 0;
    outline: none;
    background: transparent;
    color: var(--text);
    font: inherit;
    font-size: var(--font-size-lg);
}

.picker-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
    padding: 4px 16px 24px;
}

.picker-empty {
    margin: 0;
    padding: 24px 16px;
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    background: var(--surface);
    color: var(--muted);
    text-align: center;
}

.group {
    flex-shrink: 0;
    overflow: hidden;
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    background: var(--surface);
}

/* Aufgeklappte Gruppe hebt sich ab: Abstand, Rahmen in der Stadtfarbe, Schatten, getönter Kopf. */
.group-expanded {
    margin: 10px 0 12px;
    border: 2px solid var(--line);
    box-shadow: var(--shadow-group);
}

.group-selected {
    border: 2px solid var(--line);
}

.entry {
    display: flex;
    align-items: center;
    gap: 12px;
    width: 100%;
    border: 0;
    background: var(--surface);
    color: var(--text);
    font: inherit;
    text-align: left;
    cursor: pointer;
}

.entry:hover {
    background: var(--surface-hover);
}

.entry-head {
    min-height: 58px;
    padding: 8px 14px;
    font-weight: var(--font-weight-semibold);
}

.group-expanded > .entry-head {
    background: var(--nav-active);
    box-shadow: inset 5px 0 0 var(--line);
    font-weight: var(--font-weight-bold);
}

.group-selected > .entry-head {
    background: var(--selected-bg);
    font-weight: var(--font-weight-bold);
}

.group-children {
    border-top: 1px solid var(--border);
    background: var(--surface-hover);
}

.entry-sub {
    min-height: 52px;
    padding: 6px 14px 6px 48px;
    background: transparent;
    font-weight: var(--font-weight-normal);
}

.entry-sub + .entry-sub {
    border-top: 1px solid var(--border-subtle);
}

.entry-selected {
    background: var(--nav-hover);
    box-shadow: inset 4px 0 0 var(--line);
    font-weight: var(--font-weight-bold);
}

.entry-icon {
    flex: none;
    color: var(--text-secondary);
}

.entry-text {
    display: flex;
    flex: 1 1 auto;
    flex-direction: column;
    min-width: 0;
}

.entry-label {
    font-size: var(--font-size-lg);
    overflow-wrap: anywhere;
}

.entry-sub .entry-label {
    font-size: var(--font-size-base);
}

.entry-parent {
    font-size: var(--font-size-xs);
    font-weight: var(--font-weight-normal);
    color: var(--muted);
}

.pill {
    flex: none;
    padding: 2px 8px;
    border-radius: var(--radius-pill);
    background: var(--nav-active);
    color: var(--text-secondary);
    font-size: 12px;
    font-weight: var(--font-weight-bold);
}

.group-expanded > .entry-head .pill,
.entry-selected .pill {
    background: var(--surface);
}

.entry-check {
    flex: none;
    color: var(--line);
}

.entry-count {
    flex: none;
    font-size: var(--font-size-xs);
    font-weight: var(--font-weight-normal);
    color: var(--muted);
    white-space: nowrap;
}

.entry-chevron {
    flex: none;
    color: var(--text-secondary);
    transition: transform 0.15s;
}

.group-expanded .entry-chevron {
    transform: rotate(90deg);
}

.hint {
    margin: 0;
    font-size: var(--font-size-sm);
    color: var(--muted);
}
</style>
