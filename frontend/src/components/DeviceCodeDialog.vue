<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import type { ApiError } from '../api/client';
import { conflictDeviceDetail, type DeviceDetail } from '../api/devices';
import { saveDeviceFields } from '../data';
import { scanCodeProblem } from '../data/deviceForm';
import { trimCode } from '../data/deviceSearch';
import { scanCodeDisplay } from '../data/eventDisplay';
import { closeQueryView } from '../router/back';
import { useUnsavedInput } from '../runtime/unsavedInput';
import CodeScanner from './CodeScanner.vue';
import FormDialog from './FormDialog.vue';

/**
 * „Code zuordnen“ (Admin, KONZEPT.md, "Bereiche des Admins"): Ein USB-Handscanner tippt den Code ins
 * Feld (Enter speichert), am Handy liest ihn die Kamera. Gespeichert wird wie eingegeben (POST
 * devices/{id}/fields mit `scan_code`), Trennzeichen eines GS1-Codes bleiben und werden als [GS]
 * angezeigt. Der Kamera-Scanner ist eine Ansicht der Seite (`?scannen=1` wie auf „Suchen“): Das
 * Fenster schließt sich solange und öffnet sich danach mit dem gelesenen Code wieder.
 */
const props = defineProps<{
    device: DeviceDetail;
    returnFocus: HTMLElement | null;
}>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{
    saved: [device: DeviceDetail, message: string];
    conflict: [device: DeviceDetail];
}>();

const SCANNER_QUERY = 'scannen';
const route = useRoute();
const router = useRouter();
const scannerOpen = computed(() => route.query[SCANNER_QUERY] === '1');

const id = useId();
const code = ref('');
const touched = ref(false);
const busy = ref(false);
const codeError = ref<string | null>(null);
const error = ref<ApiError | null>(null);
const conflict = ref(false);
/** Nach dem Kamera-Scanner öffnet sich das Fenster mit dem gelesenen Code (nicht leer). */
let resume = false;

watch(open, (value) => {
    if (value && !resume) {
        code.value = '';
        touched.value = false;
        codeError.value = null;
        error.value = null;
        conflict.value = false;
    }
    resume = false;
});

watch(scannerOpen, (now, before) => {
    if (before && !now) {
        resume = true;
        open.value = true;
    }
});

const problem = computed(() => scanCodeProblem(code.value));
const shownProblem = computed(() => codeError.value ?? (touched.value ? problem.value : null));
/** GS1-Trennzeichen sind im Feld unsichtbar; darunter steht der Code mit [GS]. */
const preview = computed(() => {
    const trimmed = trimCode(code.value);
    return /[\u0004\u001d\u001e]/.test(trimmed) ? scanCodeDisplay(trimmed) : null;
});

useUnsavedInput(() => (open.value || scannerOpen.value) && trimCode(code.value) !== '');

const description = computed(() =>
    props.device.scanCode === null
        ? 'Code mit dem Handscanner einlesen, mit der Kamera scannen oder eintippen.'
        : `Bisher: ${scanCodeDisplay(props.device.scanCode)}. Der neue Code ersetzt ihn.`,
);

function onInput(): void {
    touched.value = true;
    codeError.value = null;
}

async function openScanner(): Promise<void> {
    open.value = false;
    await router.push({ query: { ...route.query, [SCANNER_QUERY]: '1' } });
}

async function onDetected(raw: string): Promise<void> {
    code.value = trimCode(raw);
    touched.value = true;
    codeError.value = null;
    await closeQueryView(router, SCANNER_QUERY);
}

function closeScanner(): Promise<void> {
    return closeQueryView(router, SCANNER_QUERY);
}

async function save(scanCode: string | null): Promise<void> {
    busy.value = true;
    error.value = null;
    const result = await saveDeviceFields(props.device.id, {
        version: props.device.version,
        changes: { scanCode },
    });
    busy.value = false;
    if (result.ok) {
        open.value = false;
        const identifier = result.data.identifier;
        emit(
            'saved',
            result.data,
            scanCode === null
                ? `Scan-Code von ${identifier} entfernt.`
                : `Code ${scanCodeDisplay(scanCode)} ist jetzt ${identifier} zugeordnet.`,
        );
        return;
    }
    const latest =
        result.error.code === 'version_conflict'
            ? conflictDeviceDetail(result.error.details)
            : null;
    if (latest !== null) {
        conflict.value = true;
        emit('conflict', latest);
    } else if (
        scanCode !== null &&
        (result.error.code === 'scan_code_taken' || result.error.code === 'invalid_request')
    ) {
        codeError.value = result.error.message;
    } else {
        error.value = result.error;
    }
}

async function submit(): Promise<void> {
    touched.value = true;
    if (problem.value !== null || busy.value) {
        return;
    }
    const trimmed = trimCode(code.value);
    if (trimmed === props.device.scanCode) {
        open.value = false;
        return;
    }
    await save(trimmed);
}
</script>

<template>
    <FormDialog
        v-model:open="open"
        :title="`Code zuordnen: ${device.identifier}`"
        :description="description"
        submit-label="Code speichern"
        :busy="busy"
        :can-submit="!touched || shownProblem === null"
        :error="error"
        :return-focus="returnFocus"
        @submit="submit"
    >
        <p v-if="conflict" class="notice notice-info" role="alert" data-testid="code-conflict">
            Das Gerät wurde inzwischen geändert (siehe oben). Bitte prüfen und erneut speichern.
        </p>
        <label class="field">
            <span class="field-label">Scan-Code</span>
            <input
                v-model="code"
                class="input"
                type="text"
                autocomplete="off"
                autocapitalize="off"
                spellcheck="false"
                enterkeyhint="done"
                :aria-invalid="shownProblem !== null"
                :aria-describedby="shownProblem !== null ? `${id}-error` : undefined"
                @input="onInput"
            />
            <span v-if="preview !== null" class="code-preview" data-testid="code-preview">
                Erkannt: {{ preview }}
            </span>
            <p v-if="shownProblem !== null" :id="`${id}-error`" class="field-error">
                {{ shownProblem }}
            </p>
        </label>
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
        <template v-if="device.scanCode !== null" #extra>
            <button class="btn" type="button" :disabled="busy" @click="save(null)">
                Code entfernen
            </button>
        </template>
    </FormDialog>

    <CodeScanner
        v-if="scannerOpen"
        detected-text="Code erkannt."
        @detected="onDetected"
        @close="closeScanner"
    />
</template>

<style scoped>
.code-preview {
    font-size: var(--font-size-sm);
    color: var(--muted);
    overflow-wrap: anywhere;
}

.camera-btn {
    align-self: flex-start;
}
</style>
