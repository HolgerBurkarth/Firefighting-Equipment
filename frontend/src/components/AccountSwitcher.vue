<script setup lang="ts">
import { computed, onMounted, ref, useId, useTemplateRef } from 'vue';
import { accountList } from '../data';
import {
    accountHints,
    accountInitials,
    accountStateLabel,
    cityFromInput,
    cityUrl,
    otherAccountEntries,
    type AccountEntry,
} from '../data/accounts';
import { currentAppBase } from '../runtime/appBase';
import { hasUnsavedInput } from '../runtime/unsavedInput';
import ConfirmDialog from './ConfirmDialog.vue';

/**
 * „Konto wechseln“ (Konzept E14, Plan Phase 1, Schritt 1.9, Mockups Seite 8): die Konten dieses
 * Geräts in anderen Städten mit Kennzeichen, Wechsel per voller Navigation (mit ungespeicherten
 * Eingaben erst nach Rückfrage) und „+ Weitere Stadt hinzufügen …“. In der Admin-Konsole im Menü
 * (`variant` `menu`), am Handy im Profil (`profile`). Auf der Anmeldeseite (`login`, Runde 2,
 * Aufgabe 1) nur die anderen Städte, ohne „Weitere Stadt“; ohne andere Einträge entfällt alles.
 */
const props = defineProps<{ variant: 'menu' | 'profile' | 'login' }>();
/** `confirming`: die Rückfrage ist offen (das Menü bleibt dann offen, sonst verschwände sie). */
const emit = defineEmits<{ confirming: [open: boolean] }>();

const area = currentAppBase().area;
const entries = computed(() =>
    props.variant === 'login' ? otherAccountEntries(accountList.entries) : accountList.entries,
);
/** Am Handy und auf der Anmeldeseite heißt „angemeldet“ wie im Mockup „wechseln“. */
const compactLabels = props.variant !== 'menu';
const adding = ref(false);
const cityInput = ref('');
const cityProblem = ref<string | null>(null);
const inputId = useId();
const input = useTemplateRef<HTMLInputElement>('city-input');

const confirmOpen = ref(false);
const pendingUrl = ref<string | null>(null);

onMounted(() => {
    void accountList.refresh();
});

function leave(url: string): void {
    if (hasUnsavedInput.value) {
        pendingUrl.value = url;
        setConfirm(true);
        return;
    }
    window.location.assign(url);
}

function setConfirm(open: boolean): void {
    confirmOpen.value = open;
    emit('confirming', open);
}

function confirmLeave(): void {
    const url = pendingUrl.value;
    setConfirm(false);
    if (url !== null) {
        window.location.assign(url);
    }
}

function pick(entry: AccountEntry): void {
    if (entry.state === 'current') {
        return;
    }
    leave(cityUrl(entry.slug, entry.state === 'expired' ? 'anmelden' : ''));
}

async function startAdding(): Promise<void> {
    adding.value = true;
    cityProblem.value = null;
    await Promise.resolve();
    input.value?.focus();
}

function stopAdding(): void {
    adding.value = false;
    cityInput.value = '';
    cityProblem.value = null;
}

function addCity(): void {
    const result = cityFromInput(cityInput.value, area);
    if ('problem' in result) {
        cityProblem.value = result.problem;
        input.value?.focus();
        return;
    }
    cityProblem.value = null;
    leave(cityUrl(result.slug, 'anmelden'));
}

function chipClass(entry: AccountEntry): string {
    return `account-chip account-chip-${entry.state}`;
}
</script>

<template>
    <div
        v-if="props.variant !== 'login' || entries.length > 0"
        class="account-switcher"
        :class="`account-switcher-${props.variant}`"
        :data-testid="props.variant === 'login' ? 'login-accounts' : undefined"
    >
        <p v-if="entries.length > 0" class="account-intro">
            {{
                props.variant === 'login'
                    ? 'Auf diesem Gerät auch angemeldet:'
                    : 'Auf diesem Gerät angemeldet:'
            }}
        </p>
        <ul class="account-list" data-testid="account-list">
            <li v-for="entry in entries" :key="entry.slug" class="account-item">
                <button
                    class="account-entry"
                    :class="{ 'account-entry-current': entry.state === 'current' }"
                    type="button"
                    :aria-current="entry.state === 'current' ? 'true' : undefined"
                    :data-testid="`account-${entry.slug}`"
                    @click="pick(entry)"
                >
                    <span
                        class="account-logo"
                        :style="
                            entry.accentColor === null
                                ? undefined
                                : { '--logo-color': entry.accentColor }
                        "
                        aria-hidden="true"
                        >{{ accountInitials(entry.tenantName) }}</span
                    >
                    <span class="account-text">
                        <span class="account-city">{{ entry.tenantName }}</span>
                        <span class="account-person">
                            {{ entry.displayName
                            }}{{ entry.roleName === null ? '' : ` · ${entry.roleName}` }}
                        </span>
                    </span>
                    <span :class="chipClass(entry)">{{
                        accountStateLabel(entry.state, compactLabels)
                    }}</span>
                </button>
                <p v-for="hint in accountHints(entry)" :key="hint" class="account-hint">
                    {{ hint }}
                </p>
            </li>
        </ul>

        <template v-if="props.variant !== 'login'">
            <form v-if="adding" class="account-add" novalidate @submit.prevent="addCity">
                <label class="field-label" :for="inputId">Adresse oder Kürzel der Stadt</label>
                <input
                    :id="inputId"
                    ref="city-input"
                    v-model="cityInput"
                    class="input"
                    type="text"
                    autocomplete="off"
                    autocapitalize="none"
                    spellcheck="false"
                    :aria-invalid="cityProblem === null ? undefined : 'true'"
                    :aria-describedby="`${inputId}-hint`"
                />
                <p v-if="cityProblem !== null" class="field-error" role="alert">
                    {{ cityProblem }}
                </p>
                <p :id="`${inputId}-hint`" class="hint">
                    Danach öffnet sich die Anmeldung dieser Stadt. Jede Stadt hat ihr eigenes Konto
                    und Passwort.
                </p>
                <div class="account-add-actions">
                    <button class="btn btn-primary" type="submit">Zur Anmeldung</button>
                    <button class="btn" type="button" @click="stopAdding">Abbrechen</button>
                </div>
            </form>
            <button v-else class="btn account-add-start" type="button" @click="startAdding">
                + Weitere Stadt hinzufügen …
            </button>

            <p class="hint account-footnote">
                Die Liste steht nur auf diesem Gerät. Abmelden in einer Stadt entfernt sie aus der
                Liste.
            </p>
        </template>

        <ConfirmDialog
            :open="confirmOpen"
            title="Konto wechseln?"
            description="Ihre Eingaben auf dieser Seite gehen verloren. Trotzdem wechseln?"
            confirm-label="Wechseln"
            @update:open="setConfirm"
            @confirm="confirmLeave"
        />
    </div>
</template>

<style scoped>
.account-switcher {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.hint,
.account-intro,
.account-footnote {
    margin: 0;
    color: var(--muted);
    font-size: var(--font-size-sm);
}

.account-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin: 0;
    padding: 0;
    list-style: none;
}

.account-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.account-entry {
    display: flex;
    align-items: center;
    gap: 12px;
    width: 100%;
    min-height: 56px;
    padding: 10px 12px;
    border: 2px solid var(--border);
    border-radius: var(--radius-md);
    background: var(--surface);
    color: var(--text);
    font: inherit;
    text-align: left;
    cursor: pointer;
}

.account-entry:hover {
    border-color: var(--input-border);
    background: var(--surface-hover);
}

.account-entry-current {
    border-color: var(--accent);
    cursor: default;
}

.account-logo {
    --logo-color: var(--accent);

    display: flex;
    flex: none;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: var(--radius-sm);
    background: var(--logo-color);
    color: var(--on-accent);
    font-size: var(--font-size-sm);
    font-weight: var(--font-weight-bold);
}

.account-text {
    display: flex;
    flex-direction: column;
    flex-grow: 1;
    min-width: 0;
    line-height: 1.3;
}

.account-city {
    font-weight: var(--font-weight-bold);
}

.account-person {
    color: var(--text-secondary);
    font-size: var(--font-size-sm);
}

.account-chip {
    flex: none;
    padding: 2px 10px;
    border-radius: var(--radius-pill);
    font-size: var(--font-size-xs);
    font-weight: var(--font-weight-semibold);
    white-space: nowrap;
}

.account-chip-current {
    background: var(--nav-active);
    color: var(--text);
}

.account-chip-signed-in {
    background: var(--status-ready-bg);
    color: var(--status-ready-fg);
}

.account-chip-expired {
    background: var(--status-maintenance-bg);
    color: var(--status-maintenance-fg);
}

.account-hint {
    margin: 0;
    padding-left: 60px;
    color: var(--status-maintenance-fg);
    font-size: var(--font-size-sm);
}

.account-add {
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding-top: 12px;
    border-top: 1px solid var(--border);
}

.account-add .hint {
    margin: 0;
}

.account-add-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.account-add-start {
    align-self: flex-start;
}
</style>
