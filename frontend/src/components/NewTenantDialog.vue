<script setup lang="ts">
import { computed, onBeforeUnmount, ref, shallowRef, watch } from 'vue';
import type { ApiError } from '../api/client';
import { checkSlug, type CreatedTenant } from '../api/tenants';
import { createTenant } from '../data/service';
import {
    SLUG_CHECK_DELAY_MS,
    emailProblem,
    slugProblem,
    suggestSlug,
    tenantAddress,
    tenantNameProblem,
} from '../data/tenants';
import { phpTrim } from '../data/masterData';
import { useUnsavedInput } from '../runtime/unsavedInput';
import FormDialog from './FormDialog.vue';

/**
 * „Neue Stadt anlegen“ (Mockup „SuperAdmin“, KONZEPT.md, "Super-Admin – Entscheidungen", 43): Name,
 * Kürzel (folgt dem Namen, bis es von Hand geändert wird) und E-Mail des ersten Admins. Das Kürzel
 * prüft die App beim Tippen wie der Server, danach (300 ms) fragt sie, ob es frei ist; verbindlich
 * bleibt POST tenants (409 slug_taken am Feld).
 */
const open = defineModel<boolean>('open', { required: true });
const props = defineProps<{ returnFocus?: HTMLElement | null }>();
const emit = defineEmits<{ created: [result: CreatedTenant] }>();

const name = ref('');
const slug = ref('');
const email = ref('');
/** Kürzel von Hand geändert: folgt dem Namen nicht mehr. */
const slugEdited = ref(false);
const touched = ref({ name: false, slug: false, email: false });
const busy = ref(false);
const dialogError = shallowRef<ApiError | null>(null);
const slugServerError = ref<string | null>(null);

/** Ergebnis der Vorabprüfung für genau dieses Kürzel; null = noch nicht bekannt. */
const availability = shallowRef<{ slug: string; available: boolean; message: string } | null>(null);
const checkError = shallowRef<ApiError | null>(null);
let checkTimer: ReturnType<typeof setTimeout> | null = null;
let checkGeneration = 0;

const unsaved = computed(
    () => open.value && (name.value !== '' || slug.value !== '' || email.value !== ''),
);
useUnsavedInput(() => unsaved.value);

const nameProblem = computed(() => tenantNameProblem(name.value));
const localSlugProblem = computed(() => slugProblem(slug.value));
const mailProblem = computed(() => emailProblem(email.value));

const shownNameError = computed(() => (touched.value.name ? nameProblem.value : null));
const shownEmailError = computed(() => (touched.value.email ? mailProblem.value : null));
const shownSlugError = computed(() => {
    if (slugServerError.value !== null) {
        return slugServerError.value;
    }
    if (!touched.value.slug && slug.value === '') {
        return null;
    }
    if (localSlugProblem.value !== null) {
        return localSlugProblem.value;
    }
    const known = availability.value;
    return known !== null && known.slug === slug.value && !known.available ? known.message : null;
});
/** „Frei. Adresse: …“, sobald der Server das aktuelle Kürzel als frei gemeldet hat. */
const slugFree = computed(() => {
    const known = availability.value;
    return shownSlugError.value === null && known?.slug === slug.value && known.available;
});
const slugChecking = computed(
    () =>
        localSlugProblem.value === null &&
        shownSlugError.value === null &&
        !slugFree.value &&
        checkError.value === null &&
        slug.value !== '',
);

function cancelCheck(): void {
    checkGeneration += 1;
    if (checkTimer !== null) {
        clearTimeout(checkTimer);
        checkTimer = null;
    }
}

function scheduleCheck(): void {
    cancelCheck();
    checkError.value = null;
    const value = slug.value;
    if (slugProblem(value) !== null) {
        return;
    }
    const generation = checkGeneration;
    checkTimer = setTimeout(() => {
        checkTimer = null;
        void checkSlug(value).then((result) => {
            if (generation !== checkGeneration) {
                return;
            }
            if (result.ok) {
                availability.value = {
                    slug: value,
                    available: result.data.valid && result.data.available,
                    message: result.data.available
                        ? result.data.message
                        : 'Das Kürzel ist bereits vergeben.',
                };
            } else {
                // Die Vorabprüfung ist nur ein Hinweis; ohne sie entscheidet der Server beim Anlegen.
                checkError.value = result.error;
            }
        });
    }, SLUG_CHECK_DELAY_MS);
}

watch(slug, () => {
    slugServerError.value = null;
    scheduleCheck();
});

function onNameInput(): void {
    touched.value.name = true;
    if (!slugEdited.value) {
        slug.value = suggestSlug(name.value);
    }
}

function onSlugInput(): void {
    touched.value.slug = true;
    slugEdited.value = slug.value !== suggestSlug(name.value);
}

function reset(): void {
    cancelCheck();
    name.value = '';
    slug.value = '';
    email.value = '';
    slugEdited.value = false;
    touched.value = { name: false, slug: false, email: false };
    dialogError.value = null;
    slugServerError.value = null;
    availability.value = null;
    checkError.value = null;
}

watch(open, (isOpen) => {
    if (isOpen) {
        reset();
    } else {
        cancelCheck();
    }
});

onBeforeUnmount(cancelCheck);

const canSubmit = computed(
    () =>
        !(touched.value.name && nameProblem.value !== null) &&
        !(touched.value.email && mailProblem.value !== null) &&
        shownSlugError.value === null,
);

async function submit(): Promise<void> {
    touched.value = { name: true, slug: true, email: true };
    dialogError.value = null;
    if (
        busy.value ||
        nameProblem.value !== null ||
        localSlugProblem.value !== null ||
        mailProblem.value !== null ||
        shownSlugError.value !== null
    ) {
        return;
    }
    busy.value = true;
    const result = await createTenant({
        slug: slug.value,
        name: phpTrim(name.value),
        adminEmail: phpTrim(email.value),
    });
    busy.value = false;
    if (!result.ok) {
        if (result.error.code === 'slug_taken') {
            slugServerError.value = result.error.message ?? 'Das Kürzel ist bereits vergeben.';
        } else {
            dialogError.value = result.error;
        }
        return;
    }
    cancelCheck();
    emit('created', result.data);
    // Erst nach dem Melden leeren: Das Fenster schließt ohne Rückfrage (nichts mehr ungespeichert).
    name.value = '';
    slug.value = '';
    email.value = '';
    open.value = false;
}
</script>

<template>
    <FormDialog
        v-model:open="open"
        :unsaved="unsaved"
        title="Neue Stadt anlegen"
        description="Der erste Admin bekommt einen Einladungslink, der 24 Stunden gültig ist."
        submit-label="Stadt anlegen und einladen"
        :busy="busy"
        :can-submit="canSubmit"
        :error="dialogError"
        :return-focus="props.returnFocus ?? null"
        @submit="submit"
    >
        <label class="field">
            <span class="field-label">Name der Stadt</span>
            <input
                v-model="name"
                class="input"
                type="text"
                autocomplete="off"
                placeholder="z. B. Feuerwehr Bad Dürrheim"
                :aria-invalid="shownNameError !== null"
                :aria-describedby="shownNameError !== null ? 'tenant-name-error' : undefined"
                data-testid="tenant-name"
                @input="onNameInput"
            />
            <p v-if="shownNameError !== null" id="tenant-name-error" class="field-error">
                {{ shownNameError }}
            </p>
        </label>
        <label class="field">
            <span class="field-label">Kürzel für die Adresse</span>
            <input
                v-model="slug"
                class="input"
                type="text"
                autocomplete="off"
                autocapitalize="none"
                spellcheck="false"
                :aria-invalid="shownSlugError !== null"
                aria-describedby="tenant-slug-status"
                data-testid="tenant-slug"
                @input="onSlugInput"
            />
            <p
                id="tenant-slug-status"
                :class="shownSlugError !== null ? 'field-error' : slugFree ? 'slug-free' : 'hint'"
                aria-live="polite"
                data-testid="tenant-slug-status"
            >
                <template v-if="shownSlugError !== null">{{ shownSlugError }}</template>
                <template v-else-if="slugFree">
                    ✓ Frei. Adresse: {{ tenantAddress(slug) }}
                </template>
                <template v-else-if="slugChecking">Wird geprüft …</template>
                <template v-else-if="checkError !== null">
                    Ob das Kürzel frei ist, prüft der Server beim Anlegen.
                </template>
                <template v-else>
                    Kleinbuchstaben, Ziffern und Bindestrich; später nicht mehr änderbar.
                </template>
            </p>
        </label>
        <label class="field">
            <span class="field-label">E-Mail des ersten Admins</span>
            <input
                v-model="email"
                class="input"
                type="email"
                autocomplete="off"
                :aria-invalid="shownEmailError !== null"
                :aria-describedby="shownEmailError !== null ? 'tenant-email-error' : undefined"
                data-testid="tenant-email"
                @input="touched.email = true"
            />
            <p v-if="shownEmailError !== null" id="tenant-email-error" class="field-error">
                {{ shownEmailError }}
            </p>
        </label>
    </FormDialog>
</template>

<style scoped>
.hint {
    margin: 0;
    color: var(--muted);
    font-size: var(--font-size-sm);
}

.slug-free {
    margin: 0;
    color: var(--status-ready-fg);
    font-size: var(--font-size-sm);
    font-weight: var(--font-weight-semibold);
    overflow-wrap: anywhere;
}
</style>
