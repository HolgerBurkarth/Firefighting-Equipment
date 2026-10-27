<script setup lang="ts">
import AccountPanel from '../components/AccountPanel.vue';
</script>

<!-- Platzhalter: Startseite des Users am Handy (Suchen, Code scannen, zuletzt benutzt). -->
<template>
    <h1>Suchen</h1>
    <p class="placeholder">Hier entsteht die Suche nach Geräten.</p>
    <AccountPanel />
</template>

<style scoped>
.placeholder {
    margin: 0;
    color: var(--muted);
}
</style>
