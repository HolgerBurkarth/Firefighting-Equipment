/* eslint-disable no-console -- Kommandozeilen-Werkzeug des Benutzertests */
// Browser-Treiber für den Benutzertest mit KI-Personas (nur Test-Werkzeug, ändert keinen App-Code).
//
// Start (ein Prozess je Persona, läuft bis `quit`):
//   node driver.mjs --persona carrier-jung --role carrier --device phone --zoom 1 --port 5101 --out <ordner>
// Steuerung: `node ut.mjs <port> <befehl> …` (siehe ut.mjs). Der Treiber meldet den Benutzer über die API der
// lokalen Testumgebung an (Rolle carrier/keeper/admin) oder öffnet die Rückgabeseite (Rolle courier). Die Persona
// sieht nur Bildschirmfotos und die Liste der sichtbaren Elemente, nie Code oder API.
import { appendFileSync, mkdirSync, readFileSync } from 'node:fs';
import { createServer } from 'node:http';
import { fileURLToPath } from 'node:url';
import { chromium, devices } from '@playwright/test';

const args = Object.fromEntries(
    process.argv
        .slice(2)
        .reduce(
            (acc, v, i, a) => (v.startsWith('--') ? [...acc, [v.slice(2), a[i + 1]]] : acc),
            [],
        ),
);
const PERSONA = args.persona;
const ROLE = args.role;
const PORT = Number(args.port);
const OUT = args.out;
const ZOOM = Number(args.zoom ?? '1');
const APP_PORT = process.env.FF_UT_PORT ?? '4300';
const BASE = `http://localhost:${APP_PORT}/firefighting/test/`;
const PASSWORD = 'Muster-Passwort-2026';
const ACCOUNTS = {
    carrier: 'lena.probe@example.org',
    keeper: 'max.muster@example.org',
    admin: 'anna.beispiel@example.org',
};
const HERE = fileURLToPath(new URL('.', import.meta.url));

/** Geräte: Handy = iPhone-13-Maße (Chromium; WebKit unter Windows schneidet rechts 20 px ab) bzw. Pixel 7 (Chromium), Tablet 768 × 1024, PC 1366 × 768 bzw. 1920 × 1080. */
const DEVICES = {
    iphone: { engine: chromium, options: devices['iPhone 13'] },
    pixel: { engine: chromium, options: devices['Pixel 7'] },
    tablet: {
        engine: chromium,
        options: {
            viewport: { width: 768, height: 1024 },
            deviceScaleFactor: 2,
            hasTouch: true,
            isMobile: false,
        },
    },
    laptop: {
        engine: chromium,
        options: { viewport: { width: 1366, height: 768 }, deviceScaleFactor: 1 },
    },
    desktop: {
        engine: chromium,
        options: { viewport: { width: 1920, height: 1080 }, deviceScaleFactor: 1 },
    },
};

mkdirSync(`${OUT}/shots`, { recursive: true });
const log = (entry) =>
    appendFileSync(
        `${OUT}/${PERSONA}.jsonl`,
        JSON.stringify({ t: new Date().toISOString(), ...entry }) + '\n',
    );

let browser = null;
let context = null;
let page = null;
let deviceName = args.device;
let actions = 0;
let task = '(Start)';
let shotNo = 0;
const consoleProblems = [];

async function start(name) {
    if (browser) await browser.close();
    const spec = DEVICES[name];
    if (!spec) throw new Error(`Unbekanntes Gerät: ${name} (${Object.keys(DEVICES).join(', ')})`);
    deviceName = name;
    const o = { ...spec.options };
    if (ZOOM !== 1 && o.viewport) {
        // „125 % Zoom“: gleiche Bildschirmgröße, aber kleinere CSS-Breite und größere Pixeldichte.
        o.viewport = {
            width: Math.round(o.viewport.width / ZOOM),
            height: Math.round(o.viewport.height / ZOOM),
        };
        o.deviceScaleFactor = (o.deviceScaleFactor ?? 1) * ZOOM;
    }
    browser = await spec.engine.launch({ headless: true });
    context = await browser.newContext({
        ...o,
        locale: 'de-DE',
        timezoneId: 'Europe/Berlin',
        serviceWorkers: 'block',
    });
    context.on('page', (p) =>
        p.on('console', (m) => m.type() === 'error' && consoleProblems.push(m.text())),
    );
    if (ROLE !== 'courier') {
        const area = ROLE === 'admin' ? 'test' : 'test';
        const res = await context.request.post(`${BASE}api/v1/auth/login`, {
            data: { email: ACCOUNTS[ROLE], password: PASSWORD },
            headers: { 'X-Client-Api': '3' },
        });
        if (!res.ok()) throw new Error(`Anmeldung ${area} fehlgeschlagen: ${String(res.status())}`);
        // WebKit nimmt ein Secure-Cookie über http://localhost nicht an: nur im Test ohne Secure neu setzen.
        const cookies = await context.cookies();
        await context.clearCookies();
        await context.addCookies(cookies.map((c) => ({ ...c, secure: false })));
    }
    page = await context.newPage();
    page.on('console', (m) => m.type() === 'error' && consoleProblems.push(m.text()));
    const startUrl =
        ROLE === 'courier' ? readFileSync(`${HERE}state-return-link.txt`, 'utf8').trim() : BASE;
    await page.goto(startUrl, { waitUntil: 'load' });
    await page.waitForTimeout(800);
}

/** Beschreibung der Seite: Adresse, sichtbarer Text und nummerierte bedienbare Elemente. */
async function look() {
    const info = await page.evaluate(() => {
        for (const el of document.querySelectorAll('[data-ut]')) el.removeAttribute('data-ut');
        const sel =
            'a[href],button,input,textarea,select,summary,[role=button],[role=link],[role=tab],[role=radio],[role=checkbox],[role=combobox],[role=menuitem],[role=switch],[role=option],[tabindex]:not([tabindex="-1"])';
        const vh = window.innerHeight;
        const vw = window.innerWidth;
        const seen = new Set();
        const items = [];
        const nameOf = (el) => {
            const aria = el.getAttribute('aria-label');
            if (aria) return aria;
            const by = el.getAttribute('aria-labelledby');
            if (by) {
                const t = by
                    .split(/\s+/)
                    .map((id) => document.getElementById(id)?.textContent?.trim() ?? '')
                    .join(' ')
                    .trim();
                if (t) return t;
            }
            if (el.labels && el.labels.length > 0)
                return [...el.labels].map((l) => l.textContent.trim()).join(' ');
            const text = (el.innerText || el.textContent || '').replace(/\s+/g, ' ').trim();
            return (
                text ||
                el.getAttribute('title') ||
                el.getAttribute('placeholder') ||
                el.getAttribute('alt') ||
                ''
            );
        };
        for (const el of document.querySelectorAll(sel)) {
            if (seen.has(el)) continue;
            seen.add(el);
            const r = el.getBoundingClientRect();
            const st = getComputedStyle(el);
            if (r.width < 2 || r.height < 2 || st.visibility === 'hidden' || st.display === 'none')
                continue;
            if (el.closest('[aria-hidden=true]') && !el.closest('[role=dialog]')) continue;
            const inView = r.bottom > 0 && r.top < vh && r.right > 0 && r.left < vw;
            const idx = items.length + 1;
            el.setAttribute('data-ut', String(idx));
            const tag = el.tagName.toLowerCase();
            const role =
                el.getAttribute('role') ??
                (tag === 'a' ? 'link' : tag === 'input' ? el.type || 'text' : tag);
            const flags = [];
            if (el.disabled || el.getAttribute('aria-disabled') === 'true') flags.push('gesperrt');
            if (el.checked || el.getAttribute('aria-checked') === 'true') flags.push('gewählt');
            if (el.getAttribute('aria-pressed') === 'true') flags.push('gedrückt');
            if (el.getAttribute('aria-expanded'))
                flags.push(
                    el.getAttribute('aria-expanded') === 'true' ? 'aufgeklappt' : 'zugeklappt',
                );
            if (el.value && ['input', 'textarea'].includes(tag) && el.type !== 'password')
                flags.push(`Wert="${String(el.value).slice(0, 40)}"`);
            if (Math.min(r.width, r.height) < 36)
                flags.push(`klein ${Math.round(r.width)}x${Math.round(r.height)}px`);
            items.push({
                idx,
                role,
                name: nameOf(el).slice(0, 80),
                flags,
                inView,
                y: Math.round(r.top),
            });
        }
        const text = document.body.innerText.replace(/\n{3,}/g, '\n\n').trim();
        return {
            url:
                location.pathname +
                location.search +
                location.hash.replace(/schluessel=.*/, 'schluessel=…'),
            title: document.title,
            text,
            items,
            scrollY: Math.round(scrollY),
            scrollMax: Math.round(document.documentElement.scrollHeight - vh),
            vw,
            vh,
        };
    });
    const visible = info.items.filter((i) => i.inView);
    const off = info.items.length - visible.length;
    const lines = [
        `Seite: ${info.url}  | Fenster ${info.vw}x${info.vh}px (${deviceName}${ZOOM !== 1 ? `, Zoom ${ZOOM * 100}%` : ''}) | Scroll ${info.scrollY}/${Math.max(0, info.scrollMax)}`,
        '--- Text der Seite ---',
        info.text.slice(0, 3500) + (info.text.length > 3500 ? '\n[… gekürzt]' : ''),
        '--- Bedienelemente im sichtbaren Bereich (Nummer zum Klicken) ---',
        ...visible.map(
            (i) =>
                `[${i.idx}] ${i.role} "${i.name}"${i.flags.length ? ' (' + i.flags.join(', ') + ')' : ''}`,
        ),
        off > 0
            ? `(${off} weitere Bedienelemente außerhalb des sichtbaren Bereichs – nach unten/oben scrollen)`
            : '',
    ];
    return lines.filter((l) => l !== '').join('\n');
}

function counted(label) {
    actions += 1;
    return `Aktion ${actions} in Aufgabe „${task}“${actions > 25 ? ' – ACHTUNG: mehr als 25 Aktionen, Abbruch erwägen' : ''}: ${label}`;
}

async function settle() {
    await page.waitForTimeout(600);
}

async function handle(cmd, rest) {
    switch (cmd) {
        case 'look':
            return await look();
        case 'shot': {
            shotNo += 1;
            const name = (rest[0] ?? `s${String(shotNo)}`).replace(/[^a-z0-9_-]/gi, '_');
            const file = `${OUT}/shots/${PERSONA}-${String(shotNo).padStart(2, '0')}-${name}.png`;
            await page.screenshot({
                path: file,
                fullPage: rest[1] === 'full',
                animations: 'disabled',
            });
            return `Bildschirmfoto: ${file}`;
        }
        case 'click': {
            const target = rest[0];
            const loc = page.locator(`[data-ut="${target}"]`);
            if ((await loc.count()) === 0) return `Kein Element ${target}. Erst „look“ aufrufen.`;
            const label = `click [${target}]`;
            await loc
                .first()
                .scrollIntoViewIfNeeded()
                .catch(() => undefined);
            try {
                await loc.first().click({ timeout: 4000 });
            } catch (e) {
                return (
                    counted(label) + ` – KLICK FEHLGESCHLAGEN: ${String(e.message).split('\n')[0]}`
                );
            }
            await settle();
            return counted(label) + ' ok';
        }
        case 'tap': {
            await page.mouse.click(Number(rest[0]), Number(rest[1]));
            await settle();
            return counted(`tap ${rest[0]},${rest[1]}`) + ' ok';
        }
        case 'type': {
            const loc = page.locator(`[data-ut="${rest[0]}"]`);
            if ((await loc.count()) === 0) return `Kein Element ${rest[0]}. Erst „look“ aufrufen.`;
            await loc
                .first()
                .scrollIntoViewIfNeeded()
                .catch(() => undefined);
            await loc.first().fill(rest.slice(1).join(' '), { timeout: 4000 });
            return counted(`type [${rest[0]}]`) + ' ok';
        }
        case 'press':
            await page.keyboard.press(rest[0]);
            await settle();
            return counted(`press ${rest[0]}`) + ' ok';
        case 'scroll': {
            const dir = rest[0] === 'up' ? -1 : 1;
            const px = Number(rest[1] ?? Math.round((page.viewportSize()?.height ?? 600) * 0.8));
            await page.mouse.wheel(0, dir * px);
            await page.waitForTimeout(300);
            return counted(`scroll ${rest[0] ?? 'down'}`) + ' ok';
        }
        case 'back':
            await page.goBack().catch(() => undefined);
            await settle();
            return counted('zurück') + ' ok';
        case 'goto': {
            const url = new URL(rest[0], BASE);
            if (!url.href.startsWith(BASE)) return 'Nur Adressen der App sind erlaubt.';
            await page.goto(url.href, { waitUntil: 'load' });
            await settle();
            return counted(`goto ${rest[0]}`) + ' ok';
        }
        case 'offline':
            await context.setOffline(rest[0] === 'on');
            return `Netz ${rest[0] === 'on' ? 'AUS (offline)' : 'AN'}`;
        case 'device':
            await start(rest[0]);
            return `Gerät gewechselt: ${rest[0]}\n` + (await look());
        case 'task':
            task = rest.join(' ');
            actions = 0;
            return `Aufgabe gestartet: ${task}`;
        case 'result':
            // result <ja|umweg|nein> <1-7> <Text>
            log({
                kind: 'result',
                task,
                success: rest[0],
                ease: Number(rest[1]),
                actions,
                note: rest.slice(2).join(' '),
            });
            return `Ergebnis gespeichert (${task}: ${rest[0]}, Einfachheit ${rest[1]}, ${actions} Aktionen)`;
        case 'note':
            log({ kind: 'note', task, text: rest.join(' ') });
            return 'Notiz gespeichert';
        case 'problems':
            return consoleProblems.length
                ? consoleProblems.slice(-10).join('\n')
                : 'Keine Konsolenfehler';
        case 'quit':
            setTimeout(async () => {
                await browser?.close();
                process.exit(0);
            }, 100);
            return 'Beendet';
        default:
            return `Unbekannter Befehl: ${cmd}`;
    }
}

await start(deviceName);
const server = createServer((req, res) => {
    let body = '';
    req.on('data', (c) => (body += c));
    req.on('end', async () => {
        let out;
        try {
            const { cmd, rest } = JSON.parse(body);
            out = await handle(cmd, rest ?? []);
            log({ kind: 'cmd', task, cmd, rest, out: out.slice(0, 200) });
        } catch (e) {
            out = `FEHLER: ${String(e.message).split('\n')[0]}`;
        }
        res.end(out);
    });
});
server.listen(PORT, '127.0.0.1', () =>
    console.log(`Treiber ${PERSONA} bereit auf Port ${String(PORT)}`),
);
