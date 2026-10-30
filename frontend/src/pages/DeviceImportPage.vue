<script setup lang="ts">
import { computed, nextTick, ref, shallowRef, useTemplateRef } from 'vue';
import { useRouter } from 'vue-router';
import type { ApiError, ApiResult, DownloadedFile } from '../api/client';
import {
    downloadImportTemplate,
    downloadImportValues,
    type ImportPreview,
    type ImportSummary,
} from '../api/deviceImport';
import ErrorNotice from '../components/ErrorNotice.vue';
import { applyDeviceImport, previewDeviceImport } from '../data';
import { MAX_COMMENT_LENGTH, normalizeComment } from '../data/booking';
import {
    changeLines,
    defaultFilter,
    encodingLabel,
    fileSizeProblem,
    filterCount,
    IMPORT_FILTERS,
    IMPORT_ROWS_STEP,
    importCountsText,
    matchesFilter,
    toBase64,
    type ImportRowFilter,
} from '../data/deviceImport';
import { goBack } from '../router/back';
import { saveDownload } from '../runtime/download';
import { useUnsavedInput } from '../runtime/unsavedInput';

/**
 * CSV-Import der Geräte (Admin, `geraete/import`; KONZEPT.md, "Admin-Konsole – Entscheidungen",
 * 32): Vorlage und „Gültige Werte“ herunterladen, Datei wählen, Vorschau des Servers mit Ergebnis
 * je Zeile (Fehler, Warnungen, „Meinten Sie …?“), dann alles oder nichts übernehmen. Die Datei
 * bleibt nur im Speicher der Seite; nach einer Änderung in Excel wählt man sie erneut.
 */
const router = useRouter();

/** Gewählte Datei: Name und Inhalt als Base64 (für Vorschau und Übernehmen gleich). */
const file = shallowRef<{ readonly name: string; readonly base64: string } | null>(null);
const preview = shallowRef<ImportPreview | null>(null);
const filter = ref<ImportRowFilter>('all');
const shown = ref(IMPORT_ROWS_STEP);
const comment = ref('');
const checking = ref(false);
const applying = ref(false);
const fileProblem = ref<string | null>(null);
const error = ref<ApiError | null>(null);
/** Hinweis nach 409 import_outdated (die Vorschau ist neu erstellt). */
const outdated = ref(false);
/** Ergebnis des letzten Imports (grüne Meldung). */
const imported = shallowRef<ImportSummary | null>(null);
const downloadError = ref<ApiError | null>(null);
const downloading = ref<'template' | 'values' | null>(null);

const fileInput = useTemplateRef<HTMLInputElement>('file-input');
const previewHeading = useTemplateRef<HTMLHeadingElement>('preview-heading');

// Eine geprüfte, noch nicht übernommene Datei gilt als ungespeicherte Eingabe.
useUnsavedInput(() => preview.value !== null || comment.value.trim() !== '');

const summary = computed(() => preview.value?.summary ?? null);
const filters = computed(() => {
    const current = summary.value;
    return current === null
        ? []
        : IMPORT_FILTERS.map((entry) => ({
              ...entry,
              count: filterCount(current, entry.filter),
          })).filter((entry) => entry.filter === 'all' || entry.count > 0);
});
const filteredRows = computed(() =>
    (preview.value?.rows ?? []).filter((row) => matchesFilter(row, filter.value)),
);
const visibleRows = computed(() => filteredRows.value.slice(0, shown.value));
const canApply = computed(
    () =>
        (preview.value?.token ?? null) !== null &&
        summary.value !== null &&
        summary.value.new + summary.value.changed > 0,
);
const unknownEntries = computed(() => {
    const current = preview.value;
    if (current === null) {
        return [];
    }
    const suggestionsFor = (list: readonly { value: string; suggestions: readonly string[] }[]) =>
        new Map(list.map((entry) => [entry.value, entry.suggestions]));
    const types = suggestionsFor(current.suggestions.deviceTypes);
    const locations = suggestionsFor(current.suggestions.locations);
    return [
        ...current.unknown.deviceTypes.map((value) => ({
            kind: 'Gerätetyp',
            value,
            suggestions: types.get(value) ?? [],
        })),
        ...current.unknown.locations.map((value) => ({
            kind: 'Standort',
            value,
            suggestions: locations.get(value) ?? [],
        })),
    ];
});

function resetPreview(): void {
    preview.value = null;
    outdated.value = false;
    error.value = null;
}

async function download(
    kind: 'template' | 'values',
    load: () => Promise<ApiResult<DownloadedFile>>,
): Promise<void> {
    downloading.value = kind;
    downloadError.value = null;
    const result = await load();
    downloading.value = null;
    if (result.ok) {
        saveDownload(result.data);
    } else {
        downloadError.value = result.error;
    }
}

async function onFileChosen(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const chosen = input.files?.[0];
    // Leeren, damit dieselbe Datei nach einer Änderung erneut gewählt werden kann.
    input.value = '';
    if (chosen === undefined) {
        return;
    }
    resetPreview();
    imported.value = null;
    file.value = null;
    fileProblem.value = fileSizeProblem(chosen.size);
    if (fileProblem.value !== null) {
        return;
    }
    let bytes: Uint8Array;
    try {
        bytes = new Uint8Array(await chosen.arrayBuffer());
    } catch {
        fileProblem.value = 'Die Datei konnte nicht gelesen werden. Bitte erneut wählen.';
        return;
    }
    file.value = { name: chosen.name, base64: toBase64(bytes) };
    await check();
}

/** Vorschau des Servers für die gewählte Datei. */
async function check(): Promise<void> {
    const current = file.value;
    if (current === null) {
        return;
    }
    checking.value = true;
    error.value = null;
    const result = await previewDeviceImport(current.base64);
    checking.value = false;
    if (file.value !== current) {
        return; // inzwischen eine andere Datei gewählt
    }
    if (!result.ok) {
        preview.value = null;
        error.value = result.error;
        return;
    }
    preview.value = result.data;
    filter.value = defaultFilter(result.data.summary);
    shown.value = IMPORT_ROWS_STEP;
    await nextTick();
    previewHeading.value?.focus();
}

async function apply(): Promise<void> {
    const current = file.value;
    const token = preview.value?.token ?? null;
    if (current === null || token === null || applying.value) {
        return;
    }
    applying.value = true;
    error.value = null;
    outdated.value = false;
    const text = normalizeComment(comment.value).trim();
    const result = await applyDeviceImport({
        file: current.base64,
        token,
        ...(text !== '' && { comment: text }),
    });
    applying.value = false;
    if (result.ok) {
        imported.value = result.data;
        preview.value = null;
        file.value = null;
        comment.value = '';
        return;
    }
    if (result.error.code === 'import_outdated') {
        // Seit der Vorschau geändert: neu prüfen, damit der Admin den aktuellen Stand sieht.
        await check();
        outdated.value = true;
        return;
    }
    error.value = result.error;
}

function chooseFile(): void {
    fileInput.value?.click();
}

function back(): Promise<void> {
    return goBack(router, { name: 'devices' });
}
</script>

<template>
    <div class="detail-nav">
        <button class="back-link" type="button" @click="back">
            <svg
                width="18"
                height="18"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                aria-hidden="true"
            >
                <path d="M15 6l-6 6 6 6" />
            </svg>
            Zurück
        </button>
    </div>

    <h1>Geräte importieren (CSV)</h1>

    <p v-if="imported !== null" class="notice notice-success" role="status" data-testid="imported">
        <span
            ><span aria-hidden="true">✓ </span>Import übernommen: {{ importCountsText(imported) }}.
            <RouterLink :to="{ name: 'devices' }">Zur Geräteliste</RouterLink></span
        >
    </p>

    <section class="card import-step" aria-labelledby="template-heading">
        <h2 id="template-heading">1. Vorlage ausfüllen</h2>
        <p class="hint">
            Die Vorlage enthält alle Spalten. Die beiden Beispielzeilen (BEISPIEL-1 und BEISPIEL-2)
            zeigen das Format; bitte vor dem Import löschen. „Gültige Werte“ listet die Gerätetypen,
            Standorte und Status, die der Import kennt. Neue Gerätetypen und Standorte legen Sie
            vorher unter „Gerätetypen“ bzw. „Standorte“ an.
        </p>
        <div class="actions">
            <button
                class="btn"
                type="button"
                :disabled="downloading !== null"
                @click="download('template', downloadImportTemplate)"
            >
                {{ downloading === 'template' ? 'Wird geladen …' : 'Vorlage herunterladen' }}
            </button>
            <button
                class="btn"
                type="button"
                :disabled="downloading !== null"
                @click="download('values', downloadImportValues)"
            >
                {{ downloading === 'values' ? 'Wird geladen …' : 'Gültige Werte herunterladen' }}
            </button>
        </div>
        <ErrorNotice v-if="downloadError !== null" :error="downloadError" />
        <p class="hint">
            Vorhandene Geräte ändern: in der Geräteliste „Export (CSV)“, die Datei bearbeiten und
            hier importieren. Die Kennung ist der Schlüssel. Fehlt eine Spalte, bleibt das Feld
            unverändert; eine leere Zelle leert es. In Excel die Spalten Kennung und Scan-Code als
            Text formatieren, sonst gehen führende Nullen verloren.
        </p>
    </section>

    <section class="card import-step" aria-labelledby="file-heading">
        <h2 id="file-heading">2. Datei prüfen</h2>
        <input
            ref="file-input"
            class="visually-hidden"
            type="file"
            accept=".csv,.txt,text/csv,text/plain"
            tabindex="-1"
            aria-hidden="true"
            data-testid="file-input"
            @change="onFileChosen"
        />
        <div class="actions">
            <button class="btn btn-primary" type="button" :disabled="checking" @click="chooseFile">
                {{ file === null ? 'CSV-Datei wählen …' : 'Andere Datei wählen …' }}
            </button>
            <button
                v-if="file !== null"
                class="btn"
                type="button"
                :disabled="checking"
                @click="check"
            >
                Erneut prüfen
            </button>
        </div>
        <p v-if="file !== null" class="file-name" data-testid="file-name">
            {{ file.name }}<span v-if="checking"> – wird geprüft …</span>
        </p>
        <p class="hint">
            CSV mit Semikolon, Komma oder Tabulator, höchstens 2 MiB und 5000 Zeilen. Gespeichert
            wird erst mit „Import übernehmen“.
        </p>
        <p v-if="fileProblem !== null" class="notice notice-error" role="alert">
            {{ fileProblem }}
        </p>
        <ErrorNotice v-if="error !== null" :error="error" />
    </section>

    <section
        v-if="preview !== null && summary !== null"
        class="card import-step"
        aria-labelledby="preview-heading"
        data-testid="preview"
    >
        <h2 id="preview-heading" ref="preview-heading" tabindex="-1">3. Vorschau</h2>
        <p v-if="outdated" class="notice notice-info" role="status">
            Seit der Prüfung hat sich an den Geräten etwas geändert. Die Vorschau ist neu erstellt;
            bitte noch einmal ansehen.
        </p>
        <p class="hint">
            Erkannt: {{ encodingLabel(preview.encoding) }}, {{ preview.delimiterLabel }}, Spalten:
            {{ preview.columns.join(', ') }}.
        </p>
        <ul class="summary" data-testid="summary">
            <li>{{ summary.rows }} Zeilen</li>
            <li class="summary-new">{{ summary.new }} neu</li>
            <li class="summary-changed">{{ summary.changed }} geändert</li>
            <li>{{ summary.unchanged }} unverändert</li>
            <li v-if="summary.skipped > 0">{{ summary.skipped }} übersprungen</li>
            <li v-if="summary.errors > 0" class="summary-error">{{ summary.errors }} Fehler</li>
            <li v-if="summary.warnings > 0" class="summary-warning">
                {{ summary.warnings }} mit Warnung
            </li>
        </ul>

        <div v-if="summary.errors > 0" class="notice notice-error" role="alert">
            <p>
                {{
                    summary.errors === 1
                        ? 'Eine Zeile enthält Fehler.'
                        : `${String(summary.errors)} Zeilen enthalten Fehler.`
                }}
                Bitte in der Datei korrigieren und erneut prüfen; importiert wird nur eine Datei
                ohne Fehler.
            </p>
        </div>

        <div v-if="unknownEntries.length > 0" class="unknown" data-testid="unknown">
            <h3>Unbekannte Werte</h3>
            <ul>
                <li v-for="entry in unknownEntries" :key="`${entry.kind}:${entry.value}`">
                    {{ entry.kind }} „{{ entry.value }}“<template
                        v-if="entry.suggestions.length > 0"
                        >: Meinten Sie
                        <template v-for="(suggestion, index) in entry.suggestions" :key="suggestion"
                            ><template v-if="index > 0">{{
                                index === entry.suggestions.length - 1 ? ' oder ' : ', '
                            }}</template
                            ><strong>„{{ suggestion }}“</strong></template
                        >?</template
                    >
                </li>
            </ul>
            <p class="hint">
                Der Import legt keine Gerätetypen oder Standorte an:
                <RouterLink :to="{ name: 'device-types' }">Gerätetypen</RouterLink>,
                <RouterLink :to="{ name: 'locations' }">Standorte</RouterLink>.
            </p>
        </div>

        <div class="filters" role="group" aria-label="Zeilen anzeigen">
            <button
                v-for="entry in filters"
                :key="entry.filter"
                class="btn filter-chip"
                type="button"
                :aria-pressed="filter === entry.filter"
                @click="
                    filter = entry.filter;
                    shown = IMPORT_ROWS_STEP;
                "
            >
                {{ entry.label }} ({{ entry.count }})
            </button>
        </div>

        <ol class="rows" data-testid="import-rows">
            <li
                v-for="row in visibleRows"
                :key="row.line"
                class="row"
                :class="`row-${row.result}`"
                :data-line="row.line"
            >
                <div class="row-head">
                    <span class="line">Zeile {{ row.line }}</span>
                    <strong class="identifier">{{ row.identifier || '(ohne Kennung)' }}</strong>
                    <span class="result" :class="`result-${row.result}`">{{
                        row.resultLabel
                    }}</span>
                </div>
                <ul v-if="changeLines(row).length > 0" class="changes">
                    <li v-for="line in changeLines(row)" :key="line">{{ line }}</li>
                </ul>
                <ul v-if="row.messages.length > 0" class="messages">
                    <li v-for="message in row.messages" :key="message">{{ message }}</li>
                </ul>
                <ul v-if="row.warnings.length > 0" class="warnings">
                    <li v-for="warning in row.warnings" :key="warning">
                        <span aria-hidden="true">⚠ </span>{{ warning }}
                    </li>
                </ul>
            </li>
        </ol>
        <button
            v-if="filteredRows.length > shown"
            class="btn more"
            type="button"
            @click="shown += IMPORT_ROWS_STEP"
        >
            Weitere anzeigen ({{ shown }} von {{ filteredRows.length }})
        </button>

        <template v-if="preview.token !== null">
            <p v-if="!canApply" class="hint" data-testid="nothing-to-do">
                Die Datei ändert nichts; es gibt nichts zu übernehmen.
            </p>
            <template v-else>
                <label class="field">
                    <span class="field-label">Kommentar für den Verlauf (optional)</span>
                    <input
                        v-model="comment"
                        class="input"
                        type="text"
                        autocomplete="off"
                        :maxlength="MAX_COMMENT_LENGTH"
                    />
                </label>
                <p v-if="summary.warnings > 0" class="hint">
                    Bitte die Zeilen mit Warnung prüfen; sie werden trotzdem übernommen.
                </p>
                <div class="actions">
                    <button
                        class="btn btn-accent"
                        type="button"
                        :disabled="applying || checking"
                        @click="apply"
                    >
                        {{
                            applying
                                ? 'Wird übernommen …'
                                : `Import übernehmen (${importCountsText(summary)})`
                        }}
                    </button>
                    <button class="btn" type="button" :disabled="applying" @click="back">
                        Abbrechen
                    </button>
                </div>
            </template>
        </template>
    </section>
</template>

<style scoped>
.import-step {
    max-width: 960px;
}

.import-step h2 {
    margin: 0;
}

.import-step h2:focus {
    outline: none;
}

.hint {
    margin: 0;
    font-size: var(--font-size-sm);
    color: var(--muted);
}

.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
}

.file-name {
    margin: 0;
    font-weight: var(--font-weight-semibold);
    overflow-wrap: anywhere;
}

.summary {
    display: flex;
    flex-wrap: wrap;
    gap: 6px 16px;
    margin: 0;
    padding: 0;
    list-style: none;
    font-weight: var(--font-weight-semibold);
}

.summary-new,
.summary-changed {
    color: var(--status-ready-fg);
}

.summary-error {
    color: var(--status-defective-fg);
}

.summary-warning {
    color: var(--status-maintenance-fg);
}

.unknown {
    display: flex;
    flex-direction: column;
    gap: 6px;
    padding: 12px 14px;
    border-radius: var(--radius-md);
    background: var(--status-maintenance-bg);
    color: var(--status-maintenance-fg);
}

.unknown h3 {
    margin: 0;
    font-size: var(--font-size-md);
}

.unknown ul {
    margin: 0;
    padding-left: 20px;
}

.unknown .hint {
    color: inherit;
}

.filters {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.filter-chip[aria-pressed='true'] {
    border-color: var(--line);
    background: var(--selected-bg);
}

.rows {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin: 0;
    padding: 0;
    list-style: none;
}

.row {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-left-width: 4px;
    border-radius: var(--radius-md);
}

.row-new,
.row-changed {
    border-left-color: var(--status-ready-fg);
}

.row-error {
    border-left-color: var(--status-defective-fg);
}

.row-head {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 4px 12px;
}

.line {
    font-size: var(--font-size-sm);
    color: var(--muted);
}

.identifier {
    overflow-wrap: anywhere;
}

.result {
    font-size: var(--font-size-sm);
    font-weight: var(--font-weight-semibold);
}

.result-new,
.result-changed {
    color: var(--status-ready-fg);
}

.result-error {
    color: var(--status-defective-fg);
}

.result-unchanged,
.result-skipped {
    color: var(--muted);
}

.changes,
.messages,
.warnings {
    margin: 0;
    padding-left: 20px;
    overflow-wrap: anywhere;
}

.messages {
    color: var(--status-defective-fg);
    font-weight: var(--font-weight-semibold);
}

.warnings {
    padding-left: 0;
    list-style: none;
    color: var(--status-maintenance-fg);
    font-weight: var(--font-weight-semibold);
}

.more {
    align-self: flex-start;
}

@media (max-width: 767.98px) {
    .actions .btn {
        flex: 1 1 100%;
    }
}
</style>
