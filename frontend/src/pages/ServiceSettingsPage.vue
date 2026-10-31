<script setup lang="ts">
import { useTemplateRef } from 'vue';
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
    Einstellungen des Super-Admins (KONZEPT.md, "Super-Admin – Entscheidungen", 46): wie in der
    Admin-Konsole, aber ohne Erscheinungsbild (unter service gibt es keine Stadt).
-->
<template>
    <div class="page-head">
        <h1>Einstellungen</h1>
    </div>
    <ThemeModeSetting />
    <div class="settings-row">
        <NotificationSetting all-tenants />
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
