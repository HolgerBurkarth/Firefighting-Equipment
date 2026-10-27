<script setup lang="ts">
import { Label } from 'reka-ui';
</script>

<template>
    <h1>Anmelden</h1>
    <form class="card login-form" novalidate @submit.prevent>
        <p class="placeholder">Platzhalter: Die Anmeldung folgt in einem späteren Schritt.</p>
        <div class="field">
            <Label for="login-email" class="field-label">E-Mail-Adresse</Label>
            <input id="login-email" class="input" type="email" autocomplete="username" />
        </div>
        <div class="field">
            <Label for="login-password" class="field-label">Passwort</Label>
            <input
                id="login-password"
                class="input"
                type="password"
                autocomplete="current-password"
            />
        </div>
        <button class="btn btn-primary" type="submit" disabled>Anmelden</button>
    </form>
</template>

<style scoped>
.login-form {
    max-width: 420px;
}

.placeholder {
    margin: 0;
    color: var(--muted);
}
</style>
