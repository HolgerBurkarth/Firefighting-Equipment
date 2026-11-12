import { mkdirSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import type { Locator, Page } from '@playwright/test';

/**
 * Bausteine der Anleitungen (Runde 14, Punkt 4): ein Schritt = Bildschirmfoto der App mit Markierungen
 * (Rahmen mit Nummer um die genannten Elemente) und ein bis zwei Sätzen. `renderGuide` setzt die
 * Schritte zu einem A4-PDF zusammen (nur Chromium).
 */

const OUT_DIR = fileURLToPath(new URL('../../../docs/anleitungen/', import.meta.url));
const SHOT_DIR = fileURLToPath(new URL('../../test-results/anleitungen/', import.meta.url));

export interface Mark {
    readonly target: Locator;
    /** Kurzer Name des Elements in der Legende unter dem Bild. */
    readonly label: string;
}

interface Marker {
    readonly n: number;
    readonly label: string;
    /** Anteile 0–1 des Bildes. */
    readonly x: number;
    readonly y: number;
    readonly w: number;
    readonly h: number;
}

export interface Shot {
    readonly png: string;
    /** Hochformat (Handy, Aushang): Bild neben dem Text statt darüber. */
    readonly mobile: boolean;
    /** Handy-Breite; sonst (Aushang) etwas breiter. */
    readonly phone: boolean;
    readonly markers: readonly Marker[];
}

export interface Step {
    readonly title: string;
    readonly text: string;
    readonly shot: Shot | null;
    /** Zweites Bild neben dem ersten (nur Handy, z. B. Formular und Meldung danach). */
    readonly shot2?: Shot | null;
}

export const PHONE = { width: 390, height: 780 } as const;
export const DESKTOP = { width: 1180, height: 720 } as const;

/** Foto des sichtbaren Teils der Seite; `marks` werden vorher in den sichtbaren Bereich geholt. */
export async function shoot(page: Page, marks: readonly Mark[] = []): Promise<Shot> {
    const viewport = page.viewportSize();
    if (viewport === null) {
        throw new Error('Kein Viewport');
    }
    // Animationen ruhen lassen.
    await page.waitForTimeout(300);
    // Erst alle Elemente in den sichtbaren Bereich holen (das Scrollen verschiebt die anderen), dann messen.
    for (const mark of marks) {
        await mark.target.first().scrollIntoViewIfNeeded();
    }
    const markers: Marker[] = [];
    for (const mark of marks) {
        const box = await mark.target.first().boundingBox();
        if (box === null || box.y + box.height <= 0 || box.y >= viewport.height) {
            continue; // liegt nach dem Scrollen außerhalb des Bildes: keine Markierung
        }
        const x = Math.max(0, box.x);
        const y = Math.max(0, box.y);
        markers.push({
            n: markers.length + 1,
            label: mark.label,
            x: x / viewport.width,
            y: y / viewport.height,
            w: Math.min(box.width, viewport.width - x) / viewport.width,
            h: Math.min(box.height, viewport.height - y) / viewport.height,
        });
    }
    const png = (await page.screenshot({ animations: 'disabled', caret: 'hide' })).toString(
        'base64',
    );
    return {
        png,
        mobile: viewport.height > viewport.width,
        phone: viewport.width < 600,
        markers,
    };
}

function escapeHtml(text: string): string {
    return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function pct(value: number): string {
    return `${(value * 100).toFixed(2)}%`;
}

function figure(shot: Shot): string {
    const marks = shot.markers
        .map(
            (m) =>
                `<span class="frame" style="left:${pct(m.x)};top:${pct(m.y)};width:${pct(m.w)};height:${pct(m.h)}"></span>` +
                `<span class="num" style="left:${pct(m.x)};top:${pct(m.y)}">${String(m.n)}</span>`,
        )
        .join('');
    const legend = shot.markers
        .map(
            (m) => `<li><span class="num-inline">${String(m.n)}</span> ${escapeHtml(m.label)}</li>`,
        )
        .join('');
    return `<figure class="shot ${shot.mobile ? (shot.phone ? 'shot-mobile' : 'shot-poster') : 'shot-desktop'}">
        <div class="pic"><img src="data:image/png;base64,${shot.png}" alt="">${marks}</div>
        ${legend === '' ? '' : `<ul class="legend">${legend}</ul>`}
    </figure>`;
}

const STYLE = `
@page { size: A4; margin: 16mm 15mm 16mm 15mm; }
* { box-sizing: border-box; }
body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; color: #1b2430; font-size: 11.5pt; line-height: 1.4; }
.cover { height: 255mm; display: flex; flex-direction: column; justify-content: center; page-break-after: always; }
.cover .band { background: #b71c1c; color: #fff; padding: 14mm 12mm; border-radius: 4mm; }
.cover h1 { margin: 0 0 3mm; font-size: 30pt; line-height: 1.1; }
.cover p { margin: 0; font-size: 14pt; }
.cover .can { margin-top: 12mm; }
.cover .can h2 { font-size: 15pt; margin: 0 0 3mm; }
.cover .can ul { margin: 0; padding-left: 6mm; font-size: 13pt; }
.cover .can li { margin: 1.5mm 0; }
.cover .note { margin-top: 10mm; color: #5b6675; font-size: 10pt; }
.sheet { height: 263mm; display: flex; flex-direction: column; justify-content: flex-start; gap: 6mm; page-break-after: always; overflow: hidden; }
.sheet:last-child { page-break-after: auto; }
.sheet .step { flex: 1 1 0; min-height: 0; margin: 0; padding-bottom: 5mm; border-bottom: 0.3mm solid #d5dbe3; }
.sheet .step:last-child { border-bottom: 0; }
.figs { display: flex; gap: 4mm; flex: none; }
.step { break-inside: avoid; display: flex; gap: 7mm; margin: 0 0 9mm; align-items: flex-start; }
.step.stack { flex-direction: column; gap: 3mm; }
.step .head { display: flex; gap: 3mm; align-items: center; }
.step .no { flex: none; width: 8mm; height: 8mm; border-radius: 50%; background: #b71c1c; color: #fff; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; font-size: 12pt; }
.step h2 { margin: 0; font-size: 15pt; }
.step p { margin: 1.5mm 0 0; }
.text { flex: 1 1 0; min-width: 0; }
.shot { margin: 0; }
.shot-mobile { flex: none; width: 46mm; }
.figs .shot-mobile { width: 42mm; }
.shot-poster { flex: none; width: 70mm; }
.shot-desktop { width: 130mm; align-self: center; }
.pic { position: relative; border: 0.3mm solid #8796a8; border-radius: 2mm; overflow: hidden; line-height: 0; }
.pic img { width: 100%; height: auto; display: block; }
.frame { position: absolute; border: 0.7mm solid #d81b60; border-radius: 1.5mm; box-shadow: 0 0 0 0.4mm #fff; }
.num { position: absolute; transform: translate(-35%, -45%); width: 5.5mm; height: 5.5mm; border-radius: 50%; background: #d81b60; color: #fff; font-weight: 700; font-size: 9pt; line-height: 5.5mm; text-align: center; }
.legend { list-style: none; margin: 2mm 0 0; padding: 0; font-size: 9.5pt; line-height: 1.35; color: #3a4655; }
.legend li { margin: 0.6mm 0; }
.num-inline { display: inline-block; width: 4.6mm; height: 4.6mm; border-radius: 50%; background: #d81b60; color: #fff; font-weight: 700; font-size: 8pt; line-height: 4.6mm; text-align: center; }
`;

export interface Guide {
    readonly file: string;
    readonly role: string;
    readonly intro: string;
    readonly can: readonly string[];
    readonly steps: readonly Step[];
}

/** Setzt die Schritte zu einem PDF zusammen und liefert dessen Pfad. */
export async function renderGuide(page: Page, guide: Guide): Promise<string> {
    const rendered = guide.steps.map((step, index) => {
        const shot = step.shot;
        const stack = !shot?.mobile;
        const figs =
            shot?.mobile === true
                ? step.shot2
                    ? `<div class="figs">${figure(shot)}${figure(step.shot2)}</div>`
                    : figure(shot)
                : '';
        return `<section class="step ${stack ? 'stack' : ''}">
                ${figs}
                <div class="text">
                    <div class="head"><span class="no">${String(index + 1)}</span><h2>${escapeHtml(step.title)}</h2></div>
                    <p>${escapeHtml(step.text)}</p>
                </div>
                ${shot !== null && !shot.mobile ? figure(shot) : ''}
            </section>`;
    });
    const sheets: string[] = [];
    for (let i = 0; i < rendered.length; i += 2) {
        sheets.push(`<div class="sheet">${rendered.slice(i, i + 2).join('')}</div>`);
    }
    const steps = sheets.join('');
    const can = guide.can.map((c) => `<li>${escapeHtml(c)}</li>`).join('');
    const html = `<!doctype html><html lang="de"><head><meta charset="utf-8"><title>Anleitung ${escapeHtml(guide.role)}</title><style>${STYLE}</style></head><body>
        <section class="cover">
            <div class="band"><h1>Anleitung für ${escapeHtml(guide.role)}</h1><p>${escapeHtml(guide.intro)}</p></div>
            <div class="can"><h2>Das können Sie</h2><ul>${can}</ul></div>
            <p class="note">Ausrüstungsverwaltung der Feuerwehr · Stand November 2026. Alle Bilder zeigen erfundene Beispieldaten.</p>
        </section>
        ${steps}
    </body></html>`;
    mkdirSync(OUT_DIR, { recursive: true });
    mkdirSync(SHOT_DIR, { recursive: true });
    const out = `${OUT_DIR}${guide.file}`;
    const printPage = await page.context().newPage();
    await printPage.setContent(html, { waitUntil: 'load' });
    writeFileSync(`${SHOT_DIR}${guide.file}.html`, html);
    await printPage.pdf({
        path: out,
        format: 'A4',
        printBackground: true,
        preferCSSPageSize: true,
    });
    await printPage.close();
    return out;
}
