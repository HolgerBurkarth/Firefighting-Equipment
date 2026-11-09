<script setup lang="ts">
import { onBeforeUnmount, onMounted } from 'vue';
import QrCode from './QrCode.vue';

/**
 * Aushang der Rückgabe (Phase 2, P2.6; A4): Name und Logo der Feuerwehr, großer QR-Code mit dem Link zur
 * Rückgabe ohne Anmeldung, drei Schritte und die Adresse zum Abtippen. Auf dem Bildschirm unsichtbar;
 * solange er besteht, druckt die Seite nur ihn (Druck-CSS in base.css über die Klasse am <html>).
 */
defineProps<{
    tenantName: string;
    /** Logo 512 px oder das Standard-Symbol (relativ zu <base>). */
    logoUrl: string;
    /** Name der Rückgabestelle, z. B. „Rückgabe“. */
    placeName: string;
    link: string;
    address: string;
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
        <article class="print-poster" data-testid="return-poster">
            <header class="poster-brand">
                <img class="poster-logo" :src="logoUrl" alt="" width="512" height="512" />
                <div>
                    <p class="poster-name" data-testid="poster-name">{{ tenantName }}</p>
                    <p class="poster-app">Ausrüstungsverwaltung</p>
                </div>
            </header>

            <h1 class="poster-title">Ausrüstung zurückgeben</h1>
            <p class="poster-lead">{{ placeName }} – ohne Anmeldung, mit dem Handy.</p>

            <div class="poster-qr">
                <QrCode :value="link" label="QR-Code mit dem Link zur Rückgabe" />
            </div>

            <ol class="poster-steps">
                <li><strong>QR-Code</strong> mit der Handy-Kamera scannen</li>
                <li><strong>Nummer</strong> der Ausrüstung eingeben</li>
                <li><strong>Namen</strong> und Zustand angeben</li>
            </ol>

            <p class="poster-small">
                Ohne Kamera: <strong data-testid="poster-address">{{ address }}</strong> öffnen. Die
                Rückgabe gilt nur mit diesem Aushang; bitte nicht fotografieren und weitergeben.
            </p>
        </article>
    </Teleport>
</template>

<style scoped>
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
    font-size: 32pt;
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

.poster-small {
    color: var(--ink-muted);
    font-size: 11pt;
    overflow-wrap: anywhere;
}
</style>
