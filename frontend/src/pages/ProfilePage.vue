<script setup lang="ts">
import { useTemplateRef } from 'vue';
import AccountPanel from '../components/AccountPanel.vue';
import PasswordChangeForm from '../components/PasswordChangeForm.vue';
import SessionList from '../components/SessionList.vue';
import ThemeModeSetting from '../components/ThemeModeSetting.vue';

const sessionList = useTemplateRef<InstanceType<typeof SessionList>>('sessionList');

/** Nach dem Ändern des Passworts sind alle anderen Geräte abgemeldet, dieses hat eine neue Sitzung. */
function onPasswordChanged(): void {
    void sessionList.value?.reload();
}
</script>

<!-- Profil am Handy: Konto mit Abmelden, Anzeigemodus, Passwort, angemeldete Geräte. -->
<template>
    <h1 class="page-heading">Profil</h1>
    <AccountPanel />
    <ThemeModeSetting />
    <PasswordChangeForm @changed="onPasswordChanged" />
    <SessionList ref="sessionList" />
</template>

<style scoped>
.page-heading {
    font-size: var(--font-size-xl);
    font-weight: var(--font-weight-bold);
}
</style>
