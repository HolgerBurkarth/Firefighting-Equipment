<template>
    <h1>Startseite</h1>
    <p class="placeholder">Hier entsteht die Startseite.</p>
    <p><RouterLink :to="{ name: 'login' }">Zur Anmeldung</RouterLink></p>
</template>

<style scoped>
.placeholder {
    margin: 0;
    color: var(--muted);
}
</style>
