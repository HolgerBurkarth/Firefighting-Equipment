<script setup lang="ts">
/**
 * Bildliche Anleitung (Runde 14, Punkt 3): drei bis vier gezeichnete Schritte mit wenig Text, für die
 * Aushänge (Rückgabe ohne Anmeldung, Einladungscode) und kurz auf den Seiten dahinter. Flach gezeichnet
 * im Stil des Startbilds, nur `circle`/`rect`/`path`/`line` ohne `style`, Farben über Klassen aus
 * tokens.css (`--ink`, `--paper`, `--accent`, `--status-…` sind in beiden Anzeigemodi gleich dunkel auf
 * hell, damit das Bild auch gedruckt lesbar bleibt). Kein `style=""` (CSP).
 */
import type { HowToStep } from '../data/howTo';

defineProps<{
    steps: readonly HowToStep[];
    /** Auf dem Aushang größer und ohne Rahmen um die Liste. */
    variant?: 'screen' | 'poster';
}>();
</script>

<template>
    <ol
        class="how-to"
        :class="variant === 'poster' ? 'how-to-poster' : 'how-to-screen'"
        data-testid="how-to"
    >
        <li
            v-for="(step, index) in steps"
            :key="step.title"
            class="how-to-step"
            data-testid="how-to-step"
        >
            <svg
                class="how-to-art"
                viewBox="0 0 120 90"
                role="img"
                :aria-label="`Bild zu Schritt ${String(index + 1)}: ${step.title}`"
            >
                <rect class="art-tile" x="1" y="1" width="118" height="88" rx="10" />

                <!-- Handy vor dem Aushang mit QR-Code -->
                <template v-if="step.art === 'scan'">
                    <rect class="art-fill-soft" x="14" y="12" width="46" height="62" rx="4" />
                    <rect class="art-fill-ink" x="22" y="20" width="12" height="12" rx="1" />
                    <rect class="art-fill-ink" x="40" y="20" width="12" height="12" rx="1" />
                    <rect class="art-fill-ink" x="22" y="38" width="12" height="12" rx="1" />
                    <rect class="art-fill-ink" x="40" y="42" width="5" height="5" />
                    <rect class="art-fill-ink" x="47" y="49" width="5" height="5" />
                    <rect class="art-fill-ink" x="29" y="56" width="5" height="5" />
                    <rect class="art-phone" x="66" y="22" width="32" height="58" rx="6" />
                    <rect class="art-fill-accent" x="71" y="30" width="22" height="34" rx="2" />
                    <circle class="art-fill-paper" cx="82" cy="73" r="2.5" />
                    <path class="art-stroke-accent" d="M76 14 Q82 8 88 14" />
                    <path class="art-stroke-accent" d="M72 17 Q82 5 92 17" />
                </template>

                <!-- Etikett mit Nummer an einer Atemschutzmaske -->
                <template v-else-if="step.art === 'number'">
                    <path
                        class="art-shape"
                        d="M18 62 Q18 34 44 34 L62 34 Q76 34 76 48 L76 62 Q76 74 62 74 L32 74 Q18 74 18 62 Z"
                    />
                    <circle class="art-fill-paper art-outline" cx="47" cy="54" r="11" />
                    <rect class="art-fill-ink" x="76" y="46" width="10" height="16" rx="2" />
                    <rect class="art-label" x="64" y="12" width="44" height="26" rx="4" />
                    <rect class="art-fill-ink" x="70" y="18" width="5" height="14" />
                    <rect class="art-fill-ink" x="78" y="18" width="3" height="14" />
                    <rect class="art-fill-ink" x="84" y="18" width="6" height="14" />
                    <rect class="art-fill-ink" x="93" y="18" width="3" height="14" />
                    <rect class="art-fill-ink" x="98" y="18" width="5" height="14" />
                    <path class="art-stroke-accent" d="M86 42 L86 58" />
                    <path class="art-stroke-accent" d="M80 53 L86 59 L92 53" />
                </template>

                <!-- Auswahl des Zustands und Namensfeld -->
                <template v-else-if="step.art === 'condition'">
                    <rect class="art-fill-soft" x="14" y="14" width="92" height="16" rx="4" />
                    <rect class="art-fill-ink" x="20" y="21" width="30" height="3" rx="1.5" />
                    <rect class="art-ok" x="14" y="38" width="28" height="18" rx="9" />
                    <path class="art-check" d="M22 47 L27 52 L35 42" />
                    <rect class="art-used" x="46" y="38" width="28" height="18" rx="9" />
                    <rect class="art-defective" x="78" y="38" width="28" height="18" rx="9" />
                    <path class="art-cross" d="M87 43 L97 51 M97 43 L87 51" />
                    <rect class="art-fill-accent" x="14" y="64" width="92" height="14" rx="7" />
                    <rect class="art-fill-paper" x="30" y="69" width="60" height="3" rx="1.5" />
                </template>

                <!-- Regal mit Kiste -->
                <template v-else-if="step.art === 'shelf'">
                    <rect class="art-fill-ink" x="14" y="14" width="5" height="64" />
                    <rect class="art-fill-ink" x="101" y="14" width="5" height="64" />
                    <rect class="art-fill-ink" x="14" y="44" width="92" height="5" />
                    <rect class="art-fill-ink" x="14" y="73" width="92" height="5" />
                    <rect class="art-fill-soft" x="26" y="24" width="22" height="20" rx="2" />
                    <rect class="art-fill-soft" x="54" y="28" width="18" height="16" rx="2" />
                    <rect class="art-box" x="40" y="52" width="40" height="21" rx="3" />
                    <path class="art-check" d="M52 63 L58 68 L69 57" />
                    <path class="art-stroke-accent" d="M88 12 L88 34" />
                    <path class="art-stroke-accent" d="M82 28 L88 35 L94 28" />
                </template>

                <!-- Formular -->
                <template v-else-if="step.art === 'form'">
                    <rect class="art-phone" x="34" y="8" width="52" height="74" rx="7" />
                    <rect class="art-fill-soft" x="40" y="20" width="40" height="9" rx="3" />
                    <rect class="art-fill-soft" x="40" y="34" width="40" height="9" rx="3" />
                    <rect class="art-fill-soft" x="40" y="48" width="40" height="9" rx="3" />
                    <rect class="art-fill-accent" x="40" y="64" width="40" height="10" rx="5" />
                </template>

                <!-- E-Mail -->
                <template v-else-if="step.art === 'mail'">
                    <rect class="art-label" x="16" y="24" width="88" height="52" rx="6" />
                    <path class="art-stroke-ink" d="M18 28 L60 56 L102 28" />
                    <circle class="art-fill-accent" cx="96" cy="26" r="11" />
                    <path class="art-check-light" d="M90 26 L94 30 L102 21" />
                </template>

                <!-- Konto freigeschaltet -->
                <template v-else>
                    <circle class="art-fill-soft" cx="60" cy="45" r="34" />
                    <circle class="art-fill-ink" cx="60" cy="35" r="9" />
                    <path class="art-fill-ink" d="M42 66 Q42 50 60 50 Q78 50 78 66 Z" />
                    <circle class="art-ok" cx="86" cy="62" r="12" />
                    <path class="art-check" d="M80 62 L85 67 L93 57" />
                </template>
            </svg>
            <span class="how-to-number" aria-hidden="true">{{ index + 1 }}</span>
            <strong class="how-to-title">{{ step.title }}</strong>
            <span class="how-to-text">{{ step.text }}</span>
        </li>
    </ol>
</template>

<style scoped>
.how-to {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 10px;
    margin: 0;
    padding: 0;
    list-style: none;
}

.how-to-step {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 0;
}

.how-to-art {
    display: block;
    width: 100%;
    height: auto;
}

.how-to-number {
    position: absolute;
    top: 6px;
    left: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: var(--accent);
    color: var(--on-accent);
    font-size: 14px;
    font-weight: var(--font-weight-bold);
    line-height: 1;
}

.how-to-title {
    font-size: var(--font-size-md);
}

.how-to-text {
    color: var(--muted);
    font-size: var(--font-size-sm);
    overflow-wrap: anywhere;
}

/* Am Handy zwei Spalten. */
@media (max-width: 767.98px) {
    .how-to-screen {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

/* Aushang (A4, Maße in mm); Farben in beiden Anzeigemodi dunkel auf hell. */
.how-to-poster {
    align-self: stretch;
    gap: 3mm;
    text-align: center;
}

.how-to-poster .how-to-step {
    gap: 1mm;
}

.how-to-poster .how-to-number {
    top: 1.5mm;
    left: 1.5mm;
    width: 6mm;
    height: 6mm;
    font-size: 11pt;
    background: var(--accent);
    color: var(--on-accent);
}

.how-to-poster .how-to-title {
    color: var(--ink);
    font-size: 11pt;
}

.how-to-poster .how-to-text {
    color: var(--ink-muted);
    font-size: 9.5pt;
}

/* Zeichnung */
.art-tile {
    fill: var(--paper);
    stroke: var(--ink-muted);
    stroke-width: 1;
}

.art-fill-ink {
    fill: var(--ink);
}

.art-fill-paper {
    fill: var(--paper);
}

.art-fill-soft {
    fill: var(--border);
}

.art-fill-accent {
    fill: var(--accent);
}

.art-outline {
    stroke: var(--ink);
    stroke-width: 2;
}

.art-phone {
    fill: var(--paper);
    stroke: var(--ink);
    stroke-width: 3;
}

.art-shape {
    fill: var(--status-used-bg);
    stroke: var(--ink);
    stroke-width: 2.5;
}

.art-label {
    fill: var(--paper);
    stroke: var(--ink);
    stroke-width: 2;
}

.art-box {
    fill: var(--status-ready-bg);
    stroke: var(--ink);
    stroke-width: 2;
}

.art-ok {
    fill: var(--status-ready-bg);
    stroke: var(--status-ready-fg);
    stroke-width: 2.5;
}

.art-used {
    fill: var(--status-used-bg);
    stroke: var(--status-used-fg);
    stroke-width: 2.5;
}

.art-defective {
    fill: var(--status-defective-bg);
    stroke: var(--status-defective-fg);
    stroke-width: 2.5;
}

.art-stroke-accent,
.art-stroke-ink,
.art-check,
.art-check-light,
.art-cross {
    fill: none;
    stroke-linecap: round;
    stroke-linejoin: round;
}

.art-stroke-accent {
    stroke: var(--accent);
    stroke-width: 3;
}

.art-stroke-ink {
    stroke: var(--ink);
    stroke-width: 2.5;
}

.art-check {
    stroke: var(--status-ready-fg);
    stroke-width: 4;
}

.art-check-light {
    stroke: var(--on-accent);
    stroke-width: 3;
}

.art-cross {
    stroke: var(--status-defective-fg);
    stroke-width: 3.5;
}
</style>
