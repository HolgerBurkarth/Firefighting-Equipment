<template>
    <h1>Seite nicht gefunden</h1>
    <p class="hint">Diese Adresse gibt es nicht.</p>
    <p><RouterLink :to="{ name: 'home' }">Zur Startseite</RouterLink></p>
</template>

<style scoped>
.hint {
    margin: 0;
    color: var(--muted);
}
</style>
