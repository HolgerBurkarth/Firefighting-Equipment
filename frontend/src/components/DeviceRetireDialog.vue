<script setup lang="ts">
import { ref, watch } from 'vue';
import type { ApiError } from '../api/client';
import { conflictDeviceDetail, type DeviceDetail } from '../api/devices';
import { retireDevice } from '../data';
import { MAX_COMMENT_LENGTH } from '../data/booking';
import { commentPart } from '../data/deviceForm';
import { useUnsavedInput } from '../runtime/unsavedInput';
import FormDialog from './FormDialog.vue';

/**
 * Aussondern (Admin, KONZEPT.md, "Listen": rot, abseits, Sicherheitsrückfrage mit Namen): Die
 * Rückfrage nennt das Gerät, der Knopf ebenso; ein Grund ist freiwillig (Kommentar im Verlauf).
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

const comment = ref('');
const busy = ref(false);
const error = ref<ApiError | null>(null);
const conflict = ref(false);

watch(open, (value) => {
    if (value) {
        comment.value = '';
        error.value = null;
        conflict.value = false;
    }
});

useUnsavedInput(() => open.value && comment.value.trim() !== '');

async function submit(): Promise<void> {
    if (busy.value) {
        return;
    }
    busy.value = true;
    error.value = null;
    const result = await retireDevice(props.device.id, {
        version: props.device.version,
        ...commentPart(comment.value),
    });
    busy.value = false;
    if (result.ok) {
        open.value = false;
        emit(
            'saved',
            result.data,
            `${result.data.identifier} ist ausgesondert. Kennung und Scan-Code sind wieder frei.`,
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
    } else {
        error.value = result.error;
    }
}
</script>

<template>
    <FormDialog
        v-model:open="open"
        :title="`${device.identifier} (${device.deviceType.name}) aussondern?`"
        description="Das Gerät verschwindet aus Suche, Prüffristen und Gerätezahlen und lässt sich nicht mehr umbuchen. Kennung und Scan-Code werden frei. Der Verlauf bleibt; über „Ausgesonderte anzeigen“ in der Geräteliste können Sie es wieder aufnehmen."
        :submit-label="`${device.identifier} aussondern`"
        variant="danger"
        :busy="busy"
        :error="error"
        :return-focus="returnFocus"
        @submit="submit"
    >
        <p v-if="conflict" class="notice notice-info" role="alert" data-testid="retire-conflict">
            Das Gerät wurde inzwischen geändert (siehe oben). Bitte prüfen Sie, ob Sie es noch
            aussondern möchten.
        </p>
        <label class="field">
            <span class="field-label">Grund (optional)</span>
            <input
                v-model="comment"
                class="input"
                type="text"
                autocomplete="off"
                placeholder="z. B. Sichtprüfung nicht bestanden"
                :maxlength="MAX_COMMENT_LENGTH"
            />
        </label>
    </FormDialog>
</template>
