<script setup lang="ts">
import { onBeforeUnmount, onMounted } from 'vue';
import QrCode from './QrCode.vue';

/**
 * Aushang eines Einladungscodes für die Wache (A4, KONZEPT.md "Admin-Konsole – Entscheidungen", 30):
 * Name und Logo der Feuerwehr, großer QR-Code mit dem Link, Code in Klarschrift, Adresse zum
 * Abtippen, drei Schritte, „gültig bis“ und Spam-Hinweis. Auf dem Bildschirm unsichtbar; solange er
 * besteht, druckt die Seite nur ihn (Druck-CSS in base.css über die Klasse am <html>).
 */
defineProps<{
    tenantName: string;
    /** Logo 512 px oder das Standard-Symbol (relativ zu <base>). */
    logoUrl: string;
    code: string;
    link: string;
    address: string;
    expiresText: string;
}>();

const PRINT_CLASS = 'has-print-poster';

onMounted(() => {
    document.documentElement.classList.add(PRINT_CLASS);
});

onBeforeUnmount(() => {
    document.documentElement.classList.remove(PRINT_CLASS);
});
</script>

<template>
    <Teleport to="body">
        <article class="print-poster" data-testid="invitation-poster">
            <header class="poster-brand">
                <img class="poster-logo" :src="logoUrl" alt="" width="512" height="512" />
                <div>
                    <p class="poster-name" data-testid="poster-name">{{ tenantName }}</p>
                    <p class="poster-app">Geräteverwaltung</p>
                </div>
            </header>

            <h1 class="poster-title">Jetzt registrieren</h1>
            <p class="poster-lead">Mit dem Handy in drei Schritten zum Zugang.</p>

            <div class="poster-qr">
                <QrCode :value="link" label="QR-Code mit dem Link zur Registrierung" />
            </div>

            <ol class="poster-steps">
                <li><strong>QR-Code</strong> mit der Handy-Kamera scannen</li>
                <li><strong>Formular</strong> ausfüllen</li>
                <li><strong>E-Mail</strong> bestätigen</li>
            </ol>

            <div class="poster-code-box">
                <p class="poster-small">Einladungscode</p>
                <p class="poster-code" data-testid="poster-code">{{ code }}</p>
                <p class="poster-small">
                    Ohne Kamera: <strong data-testid="poster-address">{{ address }}</strong> öffnen
                    und den Code eingeben.
                </p>
            </div>

            <p class="poster-valid" data-testid="poster-valid">
                Gültig bis {{ expiresText }} Uhr. Danach schaltet ein Admin Ihr Konto frei.
            </p>
            <p class="poster-small">
                Keine E-Mail erhalten? Bitte auch im Spam-Ordner nachsehen und
                <strong>feuerwehr-app@prodad.de</strong> zu den Kontakten hinzufügen.
            </p>
        </article>
    </Teleport>
</template>

<style scoped>
/* display setzt base.css (nur beim Drucken sichtbar); Maße in mm für A4 (273 mm Höhe nutzbar). */
.print-poster {
    flex-direction: column;
    align-items: center;
    gap: 4mm;
    color: var(--ink);
    background: var(--paper);
    font-size: 13pt;
    text-align: center;
    break-inside: avoid;
}

.print-poster p {
    margin: 0;
}

.poster-brand {
    display: flex;
    align-items: center;
    gap: 6mm;
    align-self: stretch;
    text-align: left;
}

.poster-logo {
    width: 26mm;
    height: 26mm;
}

.poster-name {
    padding-left: 4mm;
    border-left: 2mm solid var(--accent);
    font-size: 22pt;
    font-weight: var(--font-weight-bold);
    line-height: 1.15;
}

.poster-app {
    padding-left: 6mm;
    color: var(--ink-muted);
    font-size: 13pt;
}

.poster-title {
    margin: 4mm 0 0;
    color: var(--accent);
    font-size: 34pt;
    line-height: 1.1;
}

.poster-lead {
    font-size: 15pt;
}

.poster-qr {
    width: 100mm;
}

.poster-steps {
    display: flex;
    gap: 5mm;
    margin: 0;
    padding: 0;
    list-style: none;
    counter-reset: step;
}

.poster-steps li {
    flex: 1 1 0;
    padding: 3mm;
    border: 0.4mm solid var(--ink-muted);
    border-radius: 3mm;
    font-size: 13pt;
    counter-increment: step;
}

.poster-steps li::before {
    content: counter(step);
    display: block;
    color: var(--accent);
    font-size: 20pt;
    font-weight: var(--font-weight-bold);
}

.poster-code-box {
    display: flex;
    flex-direction: column;
    gap: 1mm;
}

.poster-code {
    font-size: 30pt;
    font-weight: var(--font-weight-bold);
    letter-spacing: 0.08em;
    font-variant-numeric: tabular-nums;
}

.poster-small {
    color: var(--ink-muted);
    font-size: 11pt;
    overflow-wrap: anywhere;
}

.poster-valid {
    font-size: 13pt;
    font-weight: var(--font-weight-semibold);
}
</style>
