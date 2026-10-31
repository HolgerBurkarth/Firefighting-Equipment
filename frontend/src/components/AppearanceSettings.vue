<script setup lang="ts">
import { Label } from 'reka-ui';
import { computed, onBeforeUnmount, ref, shallowRef, useTemplateRef, watch } from 'vue';
import type { ApiError } from '../api/client';
import {
    ACCENT_SWATCHES,
    accentRequest,
    accentSelection,
    checkAccent,
    contrastText,
    LOGO_ACCEPT,
    LOGO_UNREADABLE,
    logoSizeProblem,
    logoSourceProblem,
} from '../data/appearance';
import { toBase64 } from '../data/deviceImport';
import { DEFAULT_ACCENT } from '../runtime/accent';
import { renderLogo } from '../runtime/logoImage';
import { removeLogo, saveAccentColor, saveLogo, tenantState } from '../runtime/tenantState';
import { useUnsavedInput } from '../runtime/unsavedInput';
import ConfirmDialog from './ConfirmDialog.vue';
import ErrorNotice from './ErrorNotice.vue';

/**
 * Erscheinungsbild der Feuerwehr (Mockup „Einstellungen“, KONZEPT.md Entscheidung 37): Akzentfarbe
 * mit Kontrastprüfung beim Tippen (wie AccentColor in PHP), Logo (die App bringt jedes Bild auf
 * 512 × 512 PNG) und eine Vorschau mit Kopfleiste und Knopf. Gilt nach dem Speichern sofort für alle
 * Benutzer der Stadt; Farbe und Logo werden getrennt gespeichert.
 */
const tenant = computed(() => tenantState.value.tenant);

/** Gespeicherte Farbe als Auswahl: `null` = Standardfarbe. */
const saved = computed(() =>
    tenant.value === null ? null : accentSelection(tenant.value.accentColor),
);

function display(selection: string | null): string {
    return (selection ?? DEFAULT_ACCENT).toUpperCase();
}

/** Einzige Quelle der Auswahl: das Feld mit dem Hex-Wert (die Farbkreise schreiben hinein). */
const input = ref(display(saved.value));
const check = computed(() => checkAccent(input.value));
/** Gewählte Farbe als Auswahl (`null` = Standard), `undefined` = Eingabe unlesbar. */
const candidate = computed(() =>
    check.value.color === null ? undefined : accentSelection(check.value.color),
);
const accentDirty = computed(() => candidate.value !== saved.value);
const previewAccent = computed(
    () => check.value.color ?? tenant.value?.accentColor ?? DEFAULT_ACCENT,
);

// Neuer Stand der Stadt (geladen oder gespeichert): Feld folgt, solange nichts geändert ist.
watch(saved, (next, previous) => {
    if (candidate.value === previous) {
        input.value = display(next);
    }
});

const savingAccent = ref(false);
const accentError = shallowRef<ApiError | null>(null);
const success = ref<string | null>(null);

function chooseSwatch(color: string | null): void {
    input.value = display(color);
    accentError.value = null;
}

async function onSaveAccent(): Promise<void> {
    if (!check.value.ok || !accentDirty.value || savingAccent.value) {
        return;
    }
    savingAccent.value = true;
    accentError.value = null;
    success.value = null;
    const result = await saveAccentColor(accentRequest(candidate.value ?? null));
    savingAccent.value = false;
    if (result.ok) {
        input.value = display(accentSelection(result.data.accentColor));
        success.value = 'Akzentfarbe gespeichert. Sie gilt ab jetzt für alle Benutzer der Stadt.';
    } else {
        accentError.value = result.error;
    }
}

function discardAccent(): void {
    input.value = display(saved.value);
    accentError.value = null;
}

/* Logo */
const fileInput = useTemplateRef<HTMLInputElement>('logo-input');
const pending = shallowRef<{ readonly blob: Blob; readonly url: string } | null>(null);
const reading = ref(false);
const savingLogo = ref(false);
const logoProblem = ref<string | null>(null);
const logoError = shallowRef<ApiError | null>(null);
const confirmRemove = ref(false);

const currentLogo = computed(() => tenant.value?.logoUrls?.['512'] ?? null);
const shownLogo = computed(() => pending.value?.url ?? currentLogo.value);
const logoBusy = computed(() => reading.value || savingLogo.value);

function dropPending(): void {
    if (pending.value !== null) {
        URL.revokeObjectURL(pending.value.url);
        pending.value = null;
    }
}

onBeforeUnmount(dropPending);

useUnsavedInput(() => accentDirty.value || pending.value !== null);

function chooseFile(): void {
    fileInput.value?.click();
}

async function onFileChosen(event: Event): Promise<void> {
    const element = event.target as HTMLInputElement;
    const chosen = element.files?.[0];
    // Leeren, damit dieselbe Datei erneut gewählt werden kann.
    element.value = '';
    if (chosen === undefined) {
        return;
    }
    dropPending();
    logoError.value = null;
    success.value = null;
    logoProblem.value = logoSourceProblem(chosen.size);
    if (logoProblem.value !== null) {
        return;
    }
    reading.value = true;
    const png = await renderLogo(chosen);
    reading.value = false;
    if (png === null) {
        logoProblem.value = LOGO_UNREADABLE;
        return;
    }
    logoProblem.value = logoSizeProblem(png.size);
    if (logoProblem.value === null) {
        pending.value = { blob: png, url: URL.createObjectURL(png) };
    }
}

async function onSaveLogo(): Promise<void> {
    const current = pending.value;
    if (current === null || savingLogo.value) {
        return;
    }
    savingLogo.value = true;
    logoError.value = null;
    success.value = null;
    const base64 = toBase64(new Uint8Array(await current.blob.arrayBuffer()));
    const result = await saveLogo(base64);
    savingLogo.value = false;
    if (result.ok) {
        dropPending();
        success.value =
            'Logo gespeichert. Es erscheint ab jetzt im Menü, auf der Anmeldeseite und als App-Symbol.';
    } else {
        logoError.value = result.error;
    }
}

async function onRemoveLogo(): Promise<void> {
    savingLogo.value = true;
    logoError.value = null;
    success.value = null;
    const result = await removeLogo();
    savingLogo.value = false;
    if (result.ok) {
        success.value = 'Logo entfernt. Die App zeigt wieder das Standard-Symbol.';
    } else {
        logoError.value = result.error;
    }
}
</script>

<template>
    <section class="card" aria-labelledby="appearance-heading">
        <div class="heading">
            <h2 id="appearance-heading">Erscheinungsbild der Feuerwehr</h2>
            <p class="hint">
                Gilt für alle Benutzer dieser Stadt. Die Statusfarben (einsatzbereit, in Wartung,
                defekt) bleiben immer gleich.
            </p>
        </div>

        <p
            v-if="success !== null"
            class="notice notice-success"
            role="status"
            data-testid="success-notice"
        >
            {{ success }}
        </p>

        <div class="appearance">
            <div class="controls">
                <div class="group">
                    <h3 id="accent-heading" class="group-heading">Akzentfarbe</h3>
                    <div class="swatches" role="radiogroup" aria-labelledby="accent-heading">
                        <label
                            v-for="swatch in ACCENT_SWATCHES"
                            :key="swatch.label"
                            class="swatch"
                            :class="{ 'swatch-checked': candidate === swatch.color }"
                            :style="{ '--swatch': swatch.color ?? DEFAULT_ACCENT }"
                            :title="swatch.label"
                        >
                            <input
                                class="visually-hidden"
                                type="radio"
                                name="accent-swatch"
                                :checked="candidate === swatch.color"
                                @change="chooseSwatch(swatch.color)"
                            />
                            <span class="visually-hidden">{{ swatch.label }}</span>
                        </label>
                    </div>
                    <div class="field">
                        <Label for="accent-input" class="field-label">
                            Farbe als Hex-Wert (z. B. #1D3557)
                        </Label>
                        <input
                            id="accent-input"
                            v-model="input"
                            class="input accent-input"
                            type="text"
                            autocomplete="off"
                            spellcheck="false"
                            maxlength="20"
                            :aria-invalid="!check.ok"
                            aria-describedby="accent-check"
                            @input="accentError = null"
                            @keydown.enter.prevent="onSaveAccent"
                        />
                        <p
                            id="accent-check"
                            class="accent-check"
                            :class="check.ok ? 'accent-ok' : 'field-error'"
                            data-testid="accent-check"
                        >
                            <template v-if="check.ok">
                                ✓ Weiße Schrift auf dieser Farbe ist gut lesbar (Kontrast
                                {{ contrastText(check.contrast) }}:1).
                            </template>
                            <template v-else>✕ {{ check.message }}</template>
                        </p>
                    </div>
                    <ErrorNotice v-if="accentError !== null" :error="accentError" />
                    <div v-if="accentDirty" class="actions">
                        <button
                            class="btn btn-accent"
                            type="button"
                            :disabled="!check.ok || savingAccent"
                            @click="onSaveAccent"
                        >
                            {{ savingAccent ? 'Wird gespeichert …' : 'Farbe speichern' }}
                        </button>
                        <button
                            class="btn"
                            type="button"
                            :disabled="savingAccent"
                            @click="discardAccent"
                        >
                            Verwerfen
                        </button>
                    </div>
                </div>

                <div class="group">
                    <h3 class="group-heading">Logo</h3>
                    <input
                        ref="logo-input"
                        class="visually-hidden"
                        type="file"
                        :accept="LOGO_ACCEPT"
                        tabindex="-1"
                        aria-hidden="true"
                        data-testid="logo-input"
                        @change="onFileChosen"
                    />
                    <div class="logo-row">
                        <img
                            v-if="shownLogo !== null"
                            class="logo"
                            :src="shownLogo"
                            :alt="
                                pending !== null
                                    ? 'Neues Logo (noch nicht gespeichert)'
                                    : 'Logo der Feuerwehr'
                            "
                            width="72"
                            height="72"
                            data-testid="logo-image"
                        />
                        <div v-else class="logo logo-default" data-testid="logo-default">
                            <svg
                                width="30"
                                height="30"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linejoin="round"
                                aria-hidden="true"
                            >
                                <path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6z" />
                            </svg>
                            <span class="visually-hidden">Standard-Symbol</span>
                        </div>
                        <div class="logo-actions">
                            <template v-if="pending === null">
                                <button
                                    class="btn"
                                    type="button"
                                    :disabled="logoBusy"
                                    @click="chooseFile"
                                >
                                    {{
                                        reading
                                            ? 'Bild wird gelesen …'
                                            : currentLogo === null
                                              ? 'Logo hochladen …'
                                              : 'Anderes Logo hochladen …'
                                    }}
                                </button>
                                <button
                                    v-if="currentLogo !== null"
                                    class="btn btn-danger"
                                    type="button"
                                    :disabled="logoBusy"
                                    @click="confirmRemove = true"
                                >
                                    Logo entfernen …
                                </button>
                            </template>
                            <template v-else>
                                <button
                                    class="btn btn-accent"
                                    type="button"
                                    :disabled="savingLogo"
                                    @click="onSaveLogo"
                                >
                                    {{ savingLogo ? 'Wird gespeichert …' : 'Logo speichern' }}
                                </button>
                                <button
                                    class="btn"
                                    type="button"
                                    :disabled="savingLogo"
                                    @click="dropPending"
                                >
                                    Verwerfen
                                </button>
                            </template>
                        </div>
                    </div>
                    <p v-if="pending !== null" class="hint" data-testid="logo-pending">
                        So wird das Logo gespeichert (512 × 512 Pixel). Noch nicht gespeichert.
                    </p>
                    <p
                        v-if="logoProblem !== null"
                        class="field-error"
                        role="alert"
                        data-testid="logo-problem"
                    >
                        {{ logoProblem }}
                    </p>
                    <ErrorNotice v-if="logoError !== null" :error="logoError" />
                    <p class="hint">
                        PNG, JPG oder SVG. Die App bringt das Bild auf 512 × 512 Pixel, nicht
                        quadratische Bilder erhalten einen durchsichtigen Rand. Das Logo erscheint
                        im Menü, auf der Anmeldeseite und als App-Symbol.
                    </p>
                </div>
            </div>

            <div class="group preview-column">
                <h3 class="group-heading">Vorschau</h3>
                <div
                    class="preview"
                    :style="{ '--preview-accent': previewAccent }"
                    data-testid="appearance-preview"
                    aria-hidden="true"
                >
                    <div class="preview-bar">
                        <img
                            v-if="shownLogo !== null"
                            class="preview-logo"
                            :src="shownLogo"
                            alt=""
                            width="36"
                            height="36"
                        />
                        <svg
                            v-else
                            width="22"
                            height="22"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linejoin="round"
                        >
                            <path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6z" />
                        </svg>
                        <span class="preview-name">{{ tenant?.name ?? 'Feuerwehr' }}</span>
                    </div>
                    <div class="preview-body">
                        <span class="preview-device">
                            <strong>M1 · Atemschutzmaske</strong>
                            <span class="badge badge-ready"><span>✓</span> einsatzbereit</span>
                        </span>
                        <span class="preview-button">Speichern</span>
                    </div>
                </div>
            </div>
        </div>

        <ConfirmDialog
            v-model:open="confirmRemove"
            title="Logo entfernen?"
            description="Die App zeigt danach wieder das Standard-Symbol, auch auf der Anmeldeseite und als App-Symbol."
            confirm-label="Logo entfernen"
            @confirm="onRemoveLogo"
        />
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
    font-size: var(--font-size-sm);
}

.appearance {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 24px;
}

.controls {
    display: flex;
    flex-direction: column;
    gap: 24px;
}

.group {
    display: flex;
    flex-direction: column;
    gap: 12px;
    min-width: 0;
}

.group-heading {
    margin: 0;
    font-size: var(--font-size-base);
    font-weight: var(--font-weight-semibold);
}

.swatches {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
}

.swatch {
    width: var(--touch-min);
    height: var(--touch-min);
    border: 1px solid var(--border);
    border-radius: 50%;
    background: var(--swatch);
    cursor: pointer;
}

.swatch-checked {
    border: 3px solid var(--surface);
    box-shadow: 0 0 0 3px var(--line);
}

.swatch:has(input:focus-visible) {
    outline: var(--focus-ring);
    outline-offset: 4px;
}

.accent-input {
    max-width: 220px;
    font-family: ui-monospace, monospace;
    text-transform: uppercase;
}

.accent-check {
    margin: 0;
    font-size: var(--font-size-sm);
    font-weight: var(--font-weight-semibold);
}

.accent-ok {
    color: var(--status-ready-fg);
}

.actions,
.logo-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
}

.logo-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 16px;
}

.logo {
    display: flex;
    flex: none;
    align-items: center;
    justify-content: center;
    width: 72px;
    height: 72px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--bg);
    object-fit: contain;
}

.logo-default {
    border: 0;
    background: var(--accent);
    color: var(--on-accent);
}

/* Vorschau in der gewählten, noch nicht gespeicherten Farbe (--preview-accent per CSSOM). */
.preview {
    overflow: hidden;
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    background: var(--bg);
}

.preview-bar {
    display: flex;
    align-items: center;
    gap: 10px;
    min-height: 56px;
    padding: 10px 16px;
    background: var(--preview-accent);
    color: var(--on-accent);
}

.preview-logo {
    border-radius: var(--radius-sm);
    background: var(--paper);
    object-fit: contain;
}

.preview-name {
    font-weight: var(--font-weight-bold);
}

.preview-body {
    display: flex;
    flex-direction: column;
    gap: 14px;
    padding: 16px;
}

.preview-device {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 14px;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface);
}

.preview-button {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: var(--touch-large);
    border-radius: var(--radius-lg);
    background: var(--preview-accent);
    color: var(--on-accent);
    font-size: 18px;
    font-weight: var(--font-weight-bold);
}

@media (min-width: 1100px) {
    .appearance {
        grid-template-columns: minmax(0, 1fr) minmax(280px, 380px);
    }
}
</style>
