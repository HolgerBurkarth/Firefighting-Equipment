<script setup lang="ts">
import { computed, useTemplateRef } from 'vue';
import { allows, can } from '../api/permissions';
import { currentPermissions } from '../auth/session';
import AppearanceSettings from '../components/AppearanceSettings.vue';
import NotificationSetting from '../components/NotificationSetting.vue';
import PasswordChangeForm from '../components/PasswordChangeForm.vue';
import SessionList from '../components/SessionList.vue';
import ThemeModeSetting from '../components/ThemeModeSetting.vue';

/**
 * Farbe und Logo nur mit „Einstellungen: Ändern“, die Benachrichtigung „Log“ nur mit der Aktion
 * „Log-Mails empfangen“ (Plan Phase 1, Schritt 1.7b); Anzeigemodus, Passwort und Geräte immer.
 */
const canAppearance = computed(() => allows(currentPermissions.value, 'settings', 'write'));
const seesAppearance = computed(() => allows(currentPermissions.value, 'settings'));
const canMailLog = computed(() => can(currentPermissions.value, 'mail.log'));

const sessionList = useTemplateRef<InstanceType<typeof SessionList>>('sessionList');

/** Nach dem Ändern des Passworts sind alle anderen Geräte abgemeldet, dieses hat eine neue Sitzung. */
function onPasswordChanged(): void {
    void sessionList.value?.reload();
}
</script>

<!--
    Einstellungen der Admin-Konsole (Mockup „Einstellungen“, KONZEPT.md "Bereiche des Admins"):
    Anzeigemodus (dieses Gerät), Erscheinungsbild der Feuerwehr (alle Benutzer der Stadt),
    Benachrichtigung „Log“, Passwort und angemeldete Geräte (das eigene Konto; in der Konsole gibt es
    kein eigenes Profil, Entscheidung 34).
-->
<template>
    <div class="page-head">
        <h1>Einstellungen</h1>
    </div>
    <ThemeModeSetting />
    <AppearanceSettings v-if="canAppearance" />
    <p v-else-if="seesAppearance" class="hint" data-testid="appearance-read-only">
        Farbe und Logo der Feuerwehr dürfen Sie nicht ändern.
    </p>
    <div class="settings-row">
        <NotificationSetting v-if="canMailLog" />
        <PasswordChangeForm @changed="onPasswordChanged" />
    </div>
    <SessionList ref="sessionList" />
</template>

<style scoped>
.settings-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 24px;
    align-items: start;
}

@media (min-width: 1100px) {
    .settings-row {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
</style>
