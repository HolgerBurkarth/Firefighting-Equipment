<script setup lang="ts">
import { useTemplateRef } from 'vue';
import AccountPanel from '../components/AccountPanel.vue';
import AccountSwitcher from '../components/AccountSwitcher.vue';
import PasswordChangeForm from '../components/PasswordChangeForm.vue';
import SessionList from '../components/SessionList.vue';
import ThemeModeSetting from '../components/ThemeModeSetting.vue';

const sessionList = useTemplateRef<InstanceType<typeof SessionList>>('sessionList');

/** Nach dem Ändern des Passworts sind alle anderen Geräte abgemeldet, dieses hat eine neue Sitzung. */
function onPasswordChanged(): void {
    void sessionList.value?.reload();
}
</script>

<!-- Profil am Handy: Konto mit Abmelden, Konto wechseln (Schritt 1.9), Anzeigemodus, Passwort,
     angemeldete Geräte. -->
<template>
    <h1 class="page-heading">Profil</h1>
    <AccountPanel />
    <section class="card" aria-labelledby="account-switch-heading" data-testid="account-switch">
        <h2 id="account-switch-heading" class="switch-heading">Konto wechseln</h2>
        <AccountSwitcher variant="profile" />
    </section>
    <ThemeModeSetting />
    <PasswordChangeForm @changed="onPasswordChanged" />
    <SessionList ref="sessionList" />
</template>

<style scoped>
.switch-heading {
    margin: 0 0 10px;
    font-size: var(--font-size-lg);
}

.page-heading {
    font-size: var(--font-size-xl);
    font-weight: var(--font-weight-bold);
}
</style>
