<script setup lang="ts">
import AccountPanel from '../components/AccountPanel.vue';
</script>

<!-- Platzhalter: Dashboard des Admins (Stadt) bzw. des Super-Admins (service). -->
<template>
    <h1>Dashboard</h1>
    <p class="placeholder">Hier entsteht das Dashboard.</p>
    <AccountPanel />
</template>

<style scoped>
.placeholder {
    margin: 0;
    color: var(--muted);
}
</style>
