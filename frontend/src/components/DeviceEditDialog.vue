<script setup lang="ts">
import { computed, reactive, ref, shallowRef, useId, watch } from 'vue';
import type { ApiError } from '../api/client';
import { conflictDeviceDetail, type DeviceDetail } from '../api/devices';
import { deviceTypeStore, saveDeviceFields } from '../data';
import { MAX_COMMENT_LENGTH } from '../data/booking';
import {
    commentPart,
    DATE_MAX,
    DATE_MIN,
    editFormChanges,
    editFormFromDevice,
    editFormProblems,
    rebaseEditForm,
    type DeviceEditForm,
    type EditField,
} from '../data/deviceForm';
import { compareNames } from '../data/masterData';
import { useUnsavedInput } from '../runtime/unsavedInput';
import AppSelect, { type SelectOption } from './AppSelect.vue';
import FormDialog from './FormDialog.vue';

/**
 * „Bearbeiten“ der Gerätedetails (Admin): Stammdaten ohne Scan-Code (dafür „Code zuordnen“), mit
 * Prüfung beim Tippen. Gesendet werden nur geänderte Felder (POST devices/{id}/fields); je Feld
 * entsteht ein Eintrag im Verlauf mit dem gemeinsamen Kommentar.
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

const id = useId();
const form = ref<DeviceEditForm>(editFormFromDevice(props.device));
/** Stand beim Öffnen bzw. nach einem Konflikt: Grundlage für „geändert“. */
const base = shallowRef<DeviceDetail>(props.device);
const comment = ref('');
const touched = reactive(new Set<EditField>());
const submitted = ref(false);
const busy = ref(false);
const identifierTaken = ref<string | null>(null);
const error = ref<ApiError | null>(null);
const conflict = ref(false);

watch(open, (value) => {
    if (value) {
        form.value = editFormFromDevice(props.device);
        base.value = props.device;
        comment.value = '';
        touched.clear();
        submitted.value = false;
        identifierTaken.value = null;
        error.value = null;
        conflict.value = false;
    }
});

const problems = computed(() => editFormProblems(form.value));
const changes = computed(() => editFormChanges(base.value, form.value));

useUnsavedInput(
    () => open.value && (Object.keys(changes.value).length > 0 || comment.value.trim() !== ''),
);

function shownProblem(field: EditField): string | null {
    if (field === 'identifier' && identifierTaken.value !== null) {
        return identifierTaken.value;
    }
    return touched.has(field) || submitted.value ? (problems.value[field] ?? null) : null;
}

function onInput(field: EditField): void {
    touched.add(field);
    if (field === 'identifier') {
        identifierTaken.value = null;
    }
}

/** Aktive Gerätetypen und der bisherige (auch deaktiviert: Er darf bleiben). */
const typeOptions = computed((): SelectOption[] => {
    const current = base.value.deviceType;
    const types = (deviceTypeStore.state.types ?? []).filter(
        (type) => type.active || type.id === current.id,
    );
    if (!types.some((type) => type.id === current.id)) {
        types.push({ id: current.id, name: current.name, active: true, deviceCount: 0 });
    }
    return types
        .sort((a, b) => compareNames(a.name, b.name))
        .map((type) => ({
            value: String(type.id),
            label: type.active ? type.name : `${type.name} (deaktiviert)`,
        }));
});

const typeValue = computed({
    get: () => String(form.value.deviceTypeId),
    set: (value: string) => {
        form.value.deviceTypeId = Number(value);
    },
});

const TEXT_FIELDS: readonly { key: 'serialNumber' | 'manufacturer' | 'model'; label: string }[] = [
    { key: 'serialNumber', label: 'Seriennummer' },
    { key: 'manufacturer', label: 'Hersteller' },
    { key: 'model', label: 'Modell' },
];

async function submit(): Promise<void> {
    submitted.value = true;
    error.value = null;
    if (Object.keys(problems.value).length > 0 || busy.value) {
        return;
    }
    if (Object.keys(changes.value).length === 0) {
        open.value = false;
        return;
    }
    busy.value = true;
    const before = base.value;
    const result = await saveDeviceFields(before.id, {
        version: before.version,
        changes: changes.value,
        ...commentPart(comment.value),
    });
    busy.value = false;
    if (result.ok) {
        open.value = false;
        emit('saved', result.data, `Stammdaten von ${result.data.identifier} gespeichert.`);
        return;
    }
    const latest =
        result.error.code === 'version_conflict'
            ? conflictDeviceDetail(result.error.details)
            : null;
    if (latest !== null) {
        form.value = rebaseEditForm(form.value, before, latest);
        base.value = latest;
        conflict.value = true;
        emit('conflict', latest);
    } else if (result.error.code === 'identifier_taken') {
        identifierTaken.value = result.error.message;
    } else {
        error.value = result.error;
    }
}
</script>

<template>
    <FormDialog
        v-model:open="open"
        :title="`${base.identifier} bearbeiten`"
        submit-label="Speichern"
        :busy="busy"
        :can-submit="Object.keys(problems).length === 0"
        :error="error"
        :return-focus="returnFocus"
        @submit="submit"
    >
        <p v-if="conflict" class="notice notice-info" role="alert" data-testid="edit-conflict">
            Das Gerät wurde inzwischen geändert. Felder, die Sie nicht geändert haben, zeigen jetzt
            den aktuellen Stand. Bitte prüfen und erneut speichern.
        </p>
        <label class="field">
            <span class="field-label">Kennung</span>
            <input
                v-model="form.identifier"
                class="input"
                type="text"
                autocomplete="off"
                :aria-invalid="shownProblem('identifier') !== null"
                :aria-describedby="
                    shownProblem('identifier') !== null ? `${id}-identifier` : undefined
                "
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
        <AppSelect v-model="typeValue" label="Gerätetyp" :options="typeOptions" />
        <label v-for="field in TEXT_FIELDS" :key="field.key" class="field">
            <span class="field-label">{{ field.label }} (optional)</span>
            <input
                v-model="form[field.key]"
                class="input"
                type="text"
                autocomplete="off"
                :aria-invalid="shownProblem(field.key) !== null"
                :aria-describedby="
                    shownProblem(field.key) !== null ? `${id}-${field.key}` : undefined
                "
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
                :aria-describedby="
                    shownProblem('procuredOn') !== null ? `${id}-procuredOn` : undefined
                "
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
        <label class="field">
            <span class="field-label">Notiz (optional)</span>
            <textarea
                v-model="form.note"
                class="input textarea"
                rows="3"
                :aria-invalid="shownProblem('note') !== null"
                :aria-describedby="shownProblem('note') !== null ? `${id}-note` : undefined"
                @input="onInput('note')"
            />
            <p v-if="shownProblem('note') !== null" :id="`${id}-note`" class="field-error">
                {{ shownProblem('note') }}
            </p>
        </label>
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
    </FormDialog>
</template>

<style scoped>
.textarea {
    min-height: 96px;
    padding: 10px 14px;
    resize: vertical;
}
</style>
