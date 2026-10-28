<script setup lang="ts">
import { Label } from 'reka-ui';
import { computed, useTemplateRef } from 'vue';
import {
    MAX_LENGTH,
    MIN_LENGTH,
    passwordChecks,
    type PasswordContext,
} from '../auth/passwordRules';
import PasswordField from './PasswordField.vue';

const model = defineModel<string>({ required: true });

const props = defineProps<{
    id: string;
    context: PasswordContext;
    /** Beschriftung (Standard „Neues Passwort“; bei der Registrierung „Passwort“). */
    label?: string;
    /** Meldung des Servers (422 weak_password, z. B. Sperrliste); der Aufrufer leert sie beim Tippen. */
    serverError?: string | null;
    /** Nach einem Absendeversuch werden offene Regeln rot statt neutral angezeigt. */
    submitted?: boolean;
}>();

const field = useTemplateRef<InstanceType<typeof PasswordField>>('field');
defineExpose({
    focus: () => {
        field.value?.focus();
    },
});

const checks = computed(() => passwordChecks(model.value, props.context));
const touched = computed(() => model.value !== '' || props.submitted);

type RuleState = 'neutral' | 'ok' | 'error';

interface Rule {
    readonly key: string;
    readonly text: string;
    readonly state: RuleState;
}

const rules = computed<Rule[]>(() => {
    const state = (ok: boolean): RuleState => (!touched.value ? 'neutral' : ok ? 'ok' : 'error');
    const list: Rule[] = [
        checks.value.length === 'too_long'
            ? { key: 'length', text: `Höchstens ${MAX_LENGTH} Zeichen`, state: 'error' }
            : {
                  key: 'length',
                  text: `Mindestens ${MIN_LENGTH} Zeichen`,
                  state: state(checks.value.length === 'ok'),
              },
        {
            key: 'personal',
            text: 'Keine Teile von Name, E-Mail-Adresse oder Feuerwehr',
            state: state(!checks.value.personal),
        },
    ];
    if (checks.value.control) {
        list.push({
            key: 'control',
            text: 'Keine Zeilenumbrüche oder Tabulatoren',
            state: 'error',
        });
    }
    return list;
});

const invalid = computed(
    () =>
        (props.serverError ?? null) !== null ||
        (touched.value && rules.value.some((rule) => rule.state === 'error')),
);
const allOk = computed(() => rules.value.every((rule) => rule.state === 'ok'));
const describedby = computed(
    () => `${props.id}-rules` + ((props.serverError ?? null) !== null ? ` ${props.id}-server` : ''),
);
</script>

<!-- Neues Passwort: ein Feld mit Auge-Knopf, darunter die Regeln mit Live-Prüfung. -->
<template>
    <div class="field">
        <Label :for="id" class="field-label">{{ label ?? 'Neues Passwort' }}</Label>
        <PasswordField
            :id="id"
            ref="field"
            v-model="model"
            autocomplete="new-password"
            :invalid="invalid"
            :describedby="describedby"
        />
        <ul :id="`${id}-rules`" class="rules" data-testid="password-rules">
            <li
                v-for="rule in rules"
                :key="rule.key"
                class="rule"
                :class="`rule-${rule.state}`"
                :data-state="rule.state"
            >
                <span class="rule-icon" aria-hidden="true">{{
                    rule.state === 'ok' ? '✓' : rule.state === 'error' ? '✕' : '•'
                }}</span>
                {{ rule.text }}
                <span v-if="rule.state !== 'neutral'" class="visually-hidden">
                    {{ rule.state === 'ok' ? '(erfüllt)' : '(nicht erfüllt)' }}
                </span>
            </li>
        </ul>
        <p
            v-if="(serverError ?? null) !== null"
            :id="`${id}-server`"
            class="field-error"
            data-testid="password-server-error"
        >
            {{ serverError }}
        </p>
        <!-- Kurze Ansage für Bildschirmleser, nur wenn alle Regeln erfüllt sind. -->
        <p class="visually-hidden" aria-live="polite">
            {{ allOk ? 'Das Passwort erfüllt alle Regeln.' : '' }}
        </p>
        <p class="hint">Sehr verbreitete Passwörter lehnt der Server zusätzlich ab.</p>
    </div>
</template>

<style scoped>
.rules {
    display: flex;
    flex-direction: column;
    gap: 2px;
    margin: 0;
    padding: 0;
    list-style: none;
    font-size: var(--font-size-sm);
}

.rule {
    display: flex;
    gap: 8px;
    align-items: baseline;
}

.rule-icon {
    width: 1em;
    font-weight: var(--font-weight-bold);
}

.rule-neutral {
    color: var(--muted);
}

.rule-ok {
    color: var(--success);
}

.rule-error {
    color: var(--danger);
}

.hint {
    margin: 0;
    color: var(--muted);
    font-size: var(--font-size-xs);
}
</style>
