<script setup lang="ts">
import { useTemplateRef } from 'vue';
import AppearanceSettings from '../components/AppearanceSettings.vue';
import NotificationSetting from '../components/NotificationSetting.vue';
import PasswordChangeForm from '../components/PasswordChangeForm.vue';
import SessionList from '../components/SessionList.vue';
import ThemeModeSetting from '../components/ThemeModeSetting.vue';

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
    <AppearanceSettings />
    <div class="settings-row">
        <NotificationSetting />
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
