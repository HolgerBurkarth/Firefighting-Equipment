<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue';
import type { ApiError } from '../api/client';
import {
    BOOKABLE_STATUSES,
    conflictDeviceDetail,
    STATUS_LABELS,
    type DeviceDetail,
} from '../api/devices';
import { adminLocationStore, deviceTypeStore, reinstateDevice } from '../data';
import { MAX_COMMENT_LENGTH } from '../data/booking';
import {
    identifierProblem,
    REINSTATE_SCAN_CODE_TAKEN,
    reinstateFormFromDevice,
    reinstateIdentifierTaken,
    reinstateRequest,
    type ReinstateForm,
} from '../data/deviceForm';
import { scanCodeDisplay } from '../data/eventDisplay';
import { compareNames } from '../data/masterData';
import { useUnsavedInput } from '../runtime/unsavedInput';
import AppSelect, { type SelectOption } from './AppSelect.vue';
import FormDialog from './FormDialog.vue';

/**
 * Ein ausgesondertes Gerät wieder aufnehmen (Admin, POST devices/{id}/reinstate): Status Pflicht,
 * Standort, Kennung und Gerätetyp vorbelegt mit den bisherigen. Ein inzwischen gesperrter Standort
 * bzw. deaktivierter Typ fällt schon beim Öffnen auf; ist die Kennung oder der Scan-Code
 * inzwischen vergeben, meldet es der Server (409), die Seite zeigt eine eigene Meldung am Feld.
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
const form = ref<ReinstateForm>(reinstateFormFromDevice(props.device));
const comment = ref('');
const busy = ref(false);
const identifierError = ref<string | null>(null);
const scanCodeError = ref<string | null>(null);
const error = ref<ApiError | null>(null);
const conflict = ref(false);

watch(open, (value) => {
    if (value) {
        form.value = reinstateFormFromDevice(props.device);
        comment.value = '';
        identifierError.value = null;
        scanCodeError.value = null;
        error.value = null;
        conflict.value = false;
    }
});

useUnsavedInput(() => open.value);

/** Standorte mit Pfad und ob sie Ziel sein dürfen (Fach nur unter aktivem Standort). */
const locations = computed(() => {
    const known = new Map<number, { path: string; active: boolean; level: 1 | 2 }>();
    for (const location of adminLocationStore.state.locations ?? []) {
        known.set(location.id, { path: location.name, active: location.active, level: 1 });
        for (const child of location.children) {
            known.set(child.id, {
                path: `${location.name} / ${child.name}`,
                active: location.active && child.active,
                level: 2,
            });
        }
    }
    return known;
});

const locationOptions = computed((): SelectOption[] => {
    const current = props.device.location;
    const options: SelectOption[] = [];
    for (const [locationId, entry] of locations.value) {
        if (entry.active || locationId === current.id) {
            options.push({
                value: String(locationId),
                label: entry.active ? entry.path : `${entry.path} (gesperrt)`,
                level: entry.level,
            });
        }
    }
    if (!locations.value.has(current.id)) {
        options.unshift({ value: String(current.id), label: current.path });
    }
    return options;
});

const typeOptions = computed((): SelectOption[] => {
    const current = props.device.deviceType;
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

const locationValue = computed({
    get: () => String(form.value.locationId),
    set: (value: string) => {
        form.value.locationId = Number(value);
    },
});

const typeValue = computed({
    get: () => String(form.value.deviceTypeId),
    set: (value: string) => {
        form.value.deviceTypeId = Number(value);
    },
});

const locationProblem = computed(() =>
    locations.value.get(form.value.locationId)?.active === false
        ? 'Dieser Standort ist gesperrt. Bitte wählen Sie einen anderen.'
        : null,
);

const typeProblem = computed(() =>
    deviceTypeStore.state.types?.find((type) => type.id === form.value.deviceTypeId)?.active ===
    false
        ? 'Dieser Gerätetyp ist deaktiviert. Bitte wählen Sie einen anderen.'
        : null,
);

const shownIdentifierProblem = computed(
    () => identifierError.value ?? identifierProblem(form.value.identifier),
);

const canSubmit = computed(
    () =>
        locationProblem.value === null &&
        typeProblem.value === null &&
        identifierProblem(form.value.identifier) === null,
);

async function submit(): Promise<void> {
    if (!canSubmit.value || busy.value) {
        return;
    }
    busy.value = true;
    error.value = null;
    identifierError.value = null;
    scanCodeError.value = null;
    const result = await reinstateDevice(
        props.device.id,
        reinstateRequest(props.device, form.value, comment.value),
    );
    busy.value = false;
    if (result.ok) {
        open.value = false;
        const device = result.data;
        emit(
            'saved',
            device,
            `${device.identifier} ist wieder aufgenommen: ${device.location.path} · ${device.statusLabel}.`,
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
    } else if (result.error.code === 'identifier_taken') {
        identifierError.value = reinstateIdentifierTaken(form.value.identifier);
    } else if (result.error.code === 'scan_code_taken') {
        scanCodeError.value = REINSTATE_SCAN_CODE_TAKEN;
    } else {
        error.value = result.error;
    }
}
</script>

<template>
    <FormDialog
        v-model:open="open"
        :title="`${device.identifier} wieder aufnehmen`"
        description="Das Gerät erscheint danach wieder in Suche, Listen und Prüffristen."
        submit-label="Wieder aufnehmen"
        :busy="busy"
        :can-submit="canSubmit"
        :error="error"
        :return-focus="returnFocus"
        @submit="submit"
    >
        <p v-if="conflict" class="notice notice-info" role="alert" data-testid="reinstate-conflict">
            Das Gerät wurde inzwischen geändert (siehe oben). Bitte prüfen und erneut speichern.
        </p>
        <fieldset class="statuses">
            <legend class="field-label">Status</legend>
            <label v-for="status in BOOKABLE_STATUSES" :key="status" class="check">
                <input v-model="form.status" type="radio" :name="`${id}-status`" :value="status" />
                {{ STATUS_LABELS[status] }}
            </label>
        </fieldset>
        <div class="field">
            <AppSelect v-model="locationValue" label="Standort" :options="locationOptions" />
            <p v-if="locationProblem !== null" class="field-error" data-testid="location-problem">
                {{ locationProblem }}
            </p>
        </div>
        <label class="field">
            <span class="field-label">Kennung</span>
            <input
                v-model="form.identifier"
                class="input"
                type="text"
                autocomplete="off"
                :aria-invalid="shownIdentifierProblem !== null"
                :aria-describedby="shownIdentifierProblem !== null ? `${id}-identifier` : undefined"
                @input="identifierError = null"
            />
            <p v-if="shownIdentifierProblem !== null" :id="`${id}-identifier`" class="field-error">
                {{ shownIdentifierProblem }}
            </p>
        </label>
        <div class="field">
            <AppSelect v-model="typeValue" label="Gerätetyp" :options="typeOptions" />
            <p v-if="typeProblem !== null" class="field-error">{{ typeProblem }}</p>
        </div>
        <div v-if="device.scanCode !== null" class="field">
            <label class="check">
                <input v-model="form.keepScanCode" type="checkbox" @change="scanCodeError = null" />
                Scan-Code {{ scanCodeDisplay(device.scanCode) }} behalten
            </label>
            <p v-if="scanCodeError !== null" class="field-error" data-testid="scan-code-error">
                {{ scanCodeError }}
            </p>
        </div>
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
.statuses {
    display: flex;
    flex-wrap: wrap;
    gap: 4px 20px;
    margin: 0;
    padding: 0;
    border: 0;
}

.statuses legend {
    margin-bottom: 4px;
    padding: 0;
}
</style>
