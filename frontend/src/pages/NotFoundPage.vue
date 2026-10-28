<script setup lang="ts">
import { inject, onMounted, ref } from 'vue';
import { UPDATER_KEY } from '../runtime/appUpdate';

const updater = inject(UPDATER_KEY, null);
// Erst prüfen, ob eine neue Version die Seite kennt (z. B. Mail-Link in einer veralteten App).
const checking = ref(updater !== null);

onMounted(async () => {
    if (updater === null) {
        return;
    }
    const reloading = await updater.checkBeforeNotFound(window.location.href);
    if (!reloading) {
        checking.value = false;
    }
});
</script>

<template>
    <p v-if="checking" class="hint" role="status" data-testid="not-found-checking">
        Seite wird geladen …
    </p>
    <template v-else>
        <h1>Seite nicht gefunden</h1>
        <p class="hint">Diese Adresse gibt es nicht.</p>
        <p><RouterLink :to="{ name: 'home' }">Zur Startseite</RouterLink></p>
    </template>
</template>

<style scoped>
.hint {
    margin: 0;
    color: var(--muted);
}
</style>
