<script setup lang="ts">
import {
    computed,
    nextTick,
    onMounted,
    reactive,
    ref,
    shallowRef,
    useId,
    useTemplateRef,
} from 'vue';
import { useRoute, useRouter } from 'vue-router';
import type { ApiError } from '../api/client';
import { BOOKABLE_STATUSES, STATUS_LABELS, type DeviceDetail } from '../api/devices';
import { errorMessage } from '../auth/errorMessages';
import AppSelect, { type SelectOption } from '../components/AppSelect.vue';
import CodeScanner from '../components/CodeScanner.vue';
import ErrorNotice from '../components/ErrorNotice.vue';
import { adminLocationStore, createDevice, deviceTypeStore } from '../data';
import { MAX_COMMENT_LENGTH, setBookingNotice } from '../data/booking';
import {
    DATE_MAX,
    DATE_MIN,
    emptyNewDeviceForm,
    newDeviceProblems,
    newDeviceRequest,
    nextNewDeviceForm,
    sameNewDeviceForm,
    type NewDeviceField,
    type NewDeviceForm,
} from '../data/deviceForm';
import { trimCode } from '../data/deviceSearch';
import { scanCodeDisplay } from '../data/eventDisplay';
import { compareNames } from '../data/masterData';
import { closeQueryView, goBack } from '../router/back';
import { useUnsavedInput } from '../runtime/unsavedInput';

/**
 * Neues Gerät (Admin, `geraete/neu`; KONZEPT.md, "Admin-Konsole – Entscheidungen", 32): Kennung,
 * Gerätetyp, Standort, Status, Scan-Code (Handscanner, Kamera oder eintippen), nächste Prüfung und
 * Stammdaten, mit Prüfung beim Tippen wie beim Bearbeiten. „Speichern“ öffnet das neue Gerät;
 * „Speichern und nächstes“ bleibt hier: Gerätetyp, Standort und Prüfdatum bleiben stehen, die
 * Kennung zählt weiter (M24 → M25). Der Kamera-Scanner ist eine Ansicht der Seite (`?scannen=1`).
 */
const route = useRoute();
const router = useRouter();
const id = useId();

const SCANNER_QUERY = 'scannen';
const scannerOpen = computed(() => route.query[SCANNER_QUERY] === '1');

const form = ref<NewDeviceForm>(emptyNewDeviceForm());
/** Stand nach dem Öffnen bzw. nach „Speichern und nächstes“: Grundlage für „ungespeichert“. */
const baseline = shallowRef<NewDeviceForm>(emptyNewDeviceForm());
const touched = reactive(new Set<NewDeviceField>());
const submitted = ref(false);
const busy = ref(false);
/** Meldungen des Servers am Feld (409 identifier_taken bzw. scan_code_taken). */
const serverProblems = reactive(new Map<NewDeviceField, string>());
const error = ref<ApiError | null>(null);
/** Zuletzt mit „Speichern und nächstes“ angelegt. */
const created = shallowRef<DeviceDetail | null>(null);
/** Nach „Speichern“ wechselt die Seite; die Eingaben gelten dann als gespeichert. */
let leaving = false;

const identifierInput = useTemplateRef<HTMLInputElement>('identifier-input');
const scanCodeInput = useTemplateRef<HTMLInputElement>('scan-code-input');
const formElement = useTemplateRef<HTMLFormElement>('form');

useUnsavedInput(() => !leaving && !sameNewDeviceForm(form.value, baseline.value));

/** Aktive Gerätetypen (deaktivierte sind kein Ziel für neue Geräte). */
const typeOptions = computed((): SelectOption[] =>
    (deviceTypeStore.state.types ?? [])
        .filter((type) => type.active)
        .sort((a, b) => compareNames(a.name, b.name))
        .map((type) => ({ value: String(type.id), label: type.name })),
);

/** Aktive Standorte; ein Fach nur unter einem aktiven Standort. */
const locationOptions = computed((): SelectOption[] => {
    const options: SelectOption[] = [];
    for (const location of adminLocationStore.state.locations ?? []) {
        if (!location.active) {
            continue;
        }
        options.push({ value: String(location.id), label: location.name });
        for (const child of location.children) {
            if (child.active) {
                options.push({
                    value: String(child.id),
                    label: `${location.name} / ${child.name}`,
                    level: 2,
                });
            }
        }
    }
    return options;
});

function selectModel(field: 'deviceTypeId' | 'locationId') {
    return computed({
        get: () => {
            const value = form.value[field];
            return value === null ? '' : String(value);
        },
        set: (value: string) => {
            form.value[field] = value === '' ? null : Number(value);
            touched.add(field);
            error.value = null;
        },
    });
}

const typeValue = selectModel('deviceTypeId');
const locationValue = selectModel('locationId');

/**
 * Probleme der Eingaben; dazu ein stehen gebliebener Gerätetyp bzw. Standort, der inzwischen
 * deaktiviert bzw. gesperrt ist (sobald die Listen geladen sind).
 */
const problems = computed(() => {
    const found = newDeviceProblems(form.value);
    const typeId = form.value.deviceTypeId;
    if (
        typeId !== null &&
        deviceTypeStore.state.types !== null &&
        !typeOptions.value.some((option) => option.value === String(typeId))
    ) {
        found.deviceTypeId = 'Dieser Gerätetyp ist deaktiviert. Bitte einen anderen wählen.';
    }
    const locationId = form.value.locationId;
    if (
        locationId !== null &&
        adminLocationStore.state.locations !== null &&
        !locationOptions.value.some((option) => option.value === String(locationId))
    ) {
        found.locationId = 'Dieser Standort ist gesperrt. Bitte einen anderen wählen.';
    }
    return found;
});

function shownProblem(field: NewDeviceField): string | null {
    const fromServer = serverProblems.get(field);
    if (fromServer !== undefined) {
        return fromServer;
    }
    return touched.has(field) || submitted.value ? (problems.value[field] ?? null) : null;
}

function describedBy(field: NewDeviceField): string | undefined {
    return shownProblem(field) === null ? undefined : `${id}-${field}`;
}

function onInput(field: NewDeviceField): void {
    touched.add(field);
    serverProblems.delete(field);
    error.value = null;
}

/** GS1-Trennzeichen sind im Feld unsichtbar; darunter steht der Code mit [GS]. */
const scanCodePreview = computed(() => {
    const trimmed = trimCode(form.value.scanCode);
    return /[\u0004\u001d\u001e]/.test(trimmed) ? scanCodeDisplay(trimmed) : null;
});

/**
 * Enter im Feld Scan-Code speichert nicht (ein USB-Handscanner schickt nach jedem Code ein Enter),
 * sondern führt zum nächsten Feld.
 */
function onScanCodeEnter(event: KeyboardEvent): void {
    event.preventDefault();
    const inputs = [...(formElement.value?.querySelectorAll<HTMLElement>('input, textarea') ?? [])];
    const index = inputs.findIndex((input) => input === event.target);
    inputs[index + 1]?.focus();
}

function openScanner(): Promise<void> {
    return router.push({ query: { ...route.query, [SCANNER_QUERY]: '1' } }).then(() => undefined);
}

async function onDetected(raw: string): Promise<void> {
    form.value.scanCode = trimCode(raw);
    onInput('scanCode');
    await closeQueryView(router, SCANNER_QUERY);
    await nextTick();
    scanCodeInput.value?.focus();
}

async function closeScanner(): Promise<void> {
    await closeQueryView(router, SCANNER_QUERY);
    await nextTick();
    scanCodeInput.value?.focus();
}

async function focusFirstProblem(): Promise<void> {
    await nextTick();
    formElement.value?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus();
}

async function save(next: boolean): Promise<void> {
    submitted.value = true;
    error.value = null;
    if (busy.value) {
        return;
    }
    if (Object.keys(problems.value).length > 0 || serverProblems.size > 0) {
        await focusFirstProblem();
        return;
    }
    busy.value = true;
    const result = await createDevice(newDeviceRequest(form.value));
    busy.value = false;
    if (result.ok) {
        const message = `${result.data.identifier} angelegt.`;
        if (next) {
            created.value = result.data;
            form.value = nextNewDeviceForm(form.value);
            baseline.value = { ...form.value };
            touched.clear();
            submitted.value = false;
            await nextTick();
            identifierInput.value?.focus();
            identifierInput.value?.select();
            return;
        }
        leaving = true;
        // Die Bestätigung zeigt das Gerätedetail (übersteht ein Neuladen wie beim Umbuchen).
        setBookingNotice({ kind: 'success', text: message });
        await router.replace({ name: 'device-details', params: { id: result.data.id } });
        return;
    }
    const code = result.error.code;
    if (code === 'identifier_taken') {
        serverProblems.set('identifier', errorMessage(result.error));
    } else if (code === 'scan_code_taken') {
        serverProblems.set('scanCode', errorMessage(result.error));
    } else {
        error.value = result.error;
        if (code === 'rule_violation' || code === 'invalid_request') {
            // Typ deaktiviert oder Standort gesperrt bzw. unbekannt: Listen neu, das Feld sagt es.
            void deviceTypeStore.refresh('changed');
            void adminLocationStore.refresh('changed');
        }
    }
    await focusFirstProblem();
}

function back(): Promise<void> {
    return goBack(router, { name: 'devices' });
}

const TEXT_FIELDS: readonly { key: 'serialNumber' | 'manufacturer' | 'model'; label: string }[] = [
    { key: 'serialNumber', label: 'Seriennummer' },
    { key: 'manufacturer', label: 'Hersteller' },
    { key: 'model', label: 'Modell' },
];

onMounted(() => {
    void deviceTypeStore.refresh('open');
    void adminLocationStore.refresh('open');
    identifierInput.value?.focus();
});
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

    <h1>Neues Gerät</h1>

    <p v-if="created !== null" class="notice notice-success" role="status" data-testid="created">
        <span
            ><span aria-hidden="true">✓ </span>{{ created.identifier }} angelegt.
            <RouterLink :to="{ name: 'device-details', params: { id: created.id } }"
                >Gerät öffnen</RouterLink
            ></span
        >
    </p>

    <form ref="form" class="card new-device" novalidate @submit.prevent="save(true)">
        <div class="grid">
            <label class="field">
                <span class="field-label">Kennung</span>
                <input
                    ref="identifier-input"
                    v-model="form.identifier"
                    class="input"
                    type="text"
                    autocomplete="off"
                    :aria-invalid="shownProblem('identifier') !== null"
                    :aria-describedby="describedBy('identifier')"
                    @input="onInput('identifier')"
                />
                <p
                    v-if="shownProblem('identifier') !== null"
                    :id="`${id}-identifier`"
                    class="field-error"
                >
                    {{ shownProblem('identifier') }}
                </p>
            </label>
            <div class="field">
                <AppSelect
                    v-model="typeValue"
                    label="Gerätetyp"
                    placeholder="Bitte wählen"
                    :options="typeOptions"
                />
                <p
                    v-if="shownProblem('deviceTypeId') !== null"
                    class="field-error"
                    data-testid="type-problem"
                >
                    {{ shownProblem('deviceTypeId') }}
                </p>
            </div>
            <div class="field">
                <AppSelect
                    v-model="locationValue"
                    label="Standort"
                    placeholder="Bitte wählen"
                    :options="locationOptions"
                />
                <p
                    v-if="shownProblem('locationId') !== null"
                    class="field-error"
                    data-testid="location-problem"
                >
                    {{ shownProblem('locationId') }}
                </p>
            </div>
            <fieldset class="statuses">
                <legend class="field-label">Status</legend>
                <label v-for="status in BOOKABLE_STATUSES" :key="status" class="check">
                    <input
                        v-model="form.status"
                        type="radio"
                        :name="`${id}-status`"
                        :value="status"
                    />
                    {{ STATUS_LABELS[status] }}
                </label>
            </fieldset>
            <div class="field">
                <label class="field">
                    <span class="field-label">Scan-Code (optional)</span>
                    <input
                        ref="scan-code-input"
                        v-model="form.scanCode"
                        class="input"
                        type="text"
                        autocomplete="off"
                        autocapitalize="off"
                        spellcheck="false"
                        enterkeyhint="next"
                        :aria-invalid="shownProblem('scanCode') !== null"
                        :aria-describedby="describedBy('scanCode')"
                        @input="onInput('scanCode')"
                        @keydown.enter="onScanCodeEnter"
                    />
                </label>
                <span
                    v-if="scanCodePreview !== null"
                    class="code-preview"
                    data-testid="code-preview"
                >
                    Erkannt: {{ scanCodePreview }}
                </span>
                <p
                    v-if="shownProblem('scanCode') !== null"
                    :id="`${id}-scanCode`"
                    class="field-error"
                >
                    {{ shownProblem('scanCode') }}
                </p>
                <button class="btn camera-btn" type="button" :disabled="busy" @click="openScanner">
                    <svg
                        width="20"
                        height="20"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        aria-hidden="true"
                    >
                        <path d="M4 8V5h3M17 5h3v3M20 16v3h-3M7 19H4v-3" />
                        <path d="M8 12h8" />
                    </svg>
                    Mit der Kamera scannen
                </button>
            </div>
            <label class="field">
                <span class="field-label">Nächste Prüfung (optional)</span>
                <input
                    v-model="form.nextInspectionDue"
                    class="input"
                    type="date"
                    :min="DATE_MIN"
                    :max="DATE_MAX"
                    :aria-invalid="shownProblem('nextInspectionDue') !== null"
                    :aria-describedby="describedBy('nextInspectionDue')"
                    @input="onInput('nextInspectionDue')"
                />
                <p
                    v-if="shownProblem('nextInspectionDue') !== null"
                    :id="`${id}-nextInspectionDue`"
                    class="field-error"
                >
                    {{ shownProblem('nextInspectionDue') }}
                </p>
            </label>
            <label v-for="field in TEXT_FIELDS" :key="field.key" class="field">
                <span class="field-label">{{ field.label }} (optional)</span>
                <input
                    v-model="form[field.key]"
                    class="input"
                    type="text"
                    autocomplete="off"
                    :aria-invalid="shownProblem(field.key) !== null"
                    :aria-describedby="describedBy(field.key)"
                    @input="onInput(field.key)"
                />
                <p
                    v-if="shownProblem(field.key) !== null"
                    :id="`${id}-${field.key}`"
                    class="field-error"
                >
                    {{ shownProblem(field.key) }}
                </p>
            </label>
            <label class="field">
                <span class="field-label">Beschaffungsdatum (optional)</span>
                <input
                    v-model="form.procuredOn"
                    class="input"
                    type="date"
                    :min="DATE_MIN"
                    :max="DATE_MAX"
                    :aria-invalid="shownProblem('procuredOn') !== null"
                    :aria-describedby="describedBy('procuredOn')"
                    @input="onInput('procuredOn')"
                />
                <p
                    v-if="shownProblem('procuredOn') !== null"
                    :id="`${id}-procuredOn`"
                    class="field-error"
                >
                    {{ shownProblem('procuredOn') }}
                </p>
            </label>
            <label class="field wide">
                <span class="field-label">Notiz (optional)</span>
                <textarea
                    v-model="form.note"
                    class="input textarea"
                    rows="3"
                    :aria-invalid="shownProblem('note') !== null"
                    :aria-describedby="describedBy('note')"
                    @input="onInput('note')"
                />
                <p v-if="shownProblem('note') !== null" :id="`${id}-note`" class="field-error">
                    {{ shownProblem('note') }}
                </p>
            </label>
            <label class="field wide">
                <span class="field-label">Kommentar für den Verlauf (optional)</span>
                <input
                    v-model="form.comment"
                    class="input"
                    type="text"
                    autocomplete="off"
                    :maxlength="MAX_COMMENT_LENGTH"
                    @input="onInput('comment')"
                />
            </label>
        </div>

        <ErrorNotice v-if="error !== null" :error="error" />

        <p class="hint">
            „Speichern und nächstes“ behält Gerätetyp, Standort und nächste Prüfung und zählt die
            Kennung weiter.
        </p>
        <div class="actions">
            <!-- Erster Knopf: Enter in einem Feld speichert und bleibt auf der Seite. -->
            <button class="btn btn-accent" type="submit" :disabled="busy">
                {{ busy ? 'Wird gespeichert …' : 'Speichern und nächstes' }}
            </button>
            <button class="btn" type="button" :disabled="busy" @click="save(false)">
                Speichern
            </button>
            <button class="btn" type="button" :disabled="busy" @click="back">Abbrechen</button>
        </div>
    </form>

    <CodeScanner
        v-if="scannerOpen"
        detected-text="Code erkannt."
        @detected="onDetected"
        @close="closeScanner"
    />
</template>

<style scoped>
.new-device {
    max-width: 880px;
}

.grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    align-items: start;
    gap: 16px 20px;
}

.wide {
    grid-column: 1 / -1;
}

.statuses {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 4px 20px;
    margin: 0;
    padding: 0;
    border: 0;
}

.statuses legend {
    width: 100%;
}

.code-preview {
    font-size: var(--font-size-sm);
    color: var(--muted);
    overflow-wrap: anywhere;
}

.camera-btn {
    align-self: flex-start;
}

.textarea {
    min-height: 96px;
    padding: 10px 14px;
    resize: vertical;
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

@media (max-width: 767.98px) {
    .grid {
        grid-template-columns: minmax(0, 1fr);
    }

    .actions .btn {
        flex: 1 1 100%;
    }
}
</style>
