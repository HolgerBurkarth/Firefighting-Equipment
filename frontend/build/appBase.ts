import { readFile, stat } from 'node:fs/promises';
import { join, normalize, sep } from 'node:path';
import type { Connect, Plugin } from 'vite';

/**
 * Platzhalter für den Basispfad in index.html. Der Server (PHP) ersetzt ihn je Anfrage durch
 * "/firefighting/<bereich>/"; ein Build gilt so für alle Städte.
 */
export const BASE_PLACEHOLDER = '/__FF_BASE__/';

/** Bereich in der Adresse wie AppPath::parse (PHP): /firefighting/<bereich>/<rest>. */
const AREA_PATTERN = /^\/firefighting\/([a-z0-9-]+)(?:\/(.*))?$/;

/** Bereich, wenn die Entwicklung ohne Stadt-Pfad aufgerufen wird. */
const DEV_AREA = 'test';

/**
 * Vorläufige Content-Security-Policy für `vite preview` (Browser-Tests). Ab Punkt 2 der
 * Einführung liefert PHP die App aus und ist allein maßgeblich.
 */
export const PREVIEW_CSP = [
    "default-src 'self'",
    "script-src 'self'",
    "style-src 'self'",
    "img-src 'self'",
    "font-src 'self'",
    "connect-src 'self'",
    "manifest-src 'self'",
    "worker-src 'self'",
    "object-src 'none'",
    "base-uri 'self'",
    "form-action 'self'",
    "frame-ancestors 'none'",
].join('; ');

export function basePathFor(area: string): string {
    return `/firefighting/${area}/`;
}

export function injectBase(html: string, area: string): string {
    if (!html.includes(BASE_PLACEHOLDER)) {
        throw new Error(`index.html enthält den Platzhalter ${BASE_PLACEHOLDER} nicht.`);
    }
    return html.replace(BASE_PLACEHOLDER, basePathFor(area));
}

/**
 * Basispfad wie auf dem Server: Im Build bleibt der Platzhalter stehen; der Dev-Server und
 * `vite preview` setzen ihn je Anfrage ein und liefern für App-Adressen index.html.
 */
export function appBase(): Plugin {
    return {
        name: 'ff-app-base',

        transformIndexHtml: {
            order: 'pre',
            handler(html, ctx) {
                if (ctx.server === undefined) {
                    // Build: Platzhalter muss erhalten bleiben (prüft injectBase beim Ausliefern).
                    injectBase(html, DEV_AREA);
                    return html;
                }
                const match = AREA_PATTERN.exec(ctx.originalUrl ?? '');
                return injectBase(html, match?.[1] ?? DEV_AREA);
            },
        },

        configureServer(server) {
            server.middlewares.use(redirectRoot);
        },

        configurePreviewServer(server) {
            const distDir = normalize(server.config.build.outDir);
            server.middlewares.use(redirectRoot);
            server.middlewares.use((req, res, next) => {
                void servePreview(distDir, req, res, next);
            });
        },
    };
}

const redirectRoot: Connect.NextHandleFunction = (req, res, next) => {
    if (req.url === '/' || req.url === '/firefighting/') {
        res.statusCode = 302;
        res.setHeader('Location', basePathFor(DEV_AREA));
        res.end();
        return;
    }
    next();
};

async function servePreview(
    distDir: string,
    req: Connect.IncomingMessage,
    res: import('node:http').ServerResponse,
    next: Connect.NextFunction,
): Promise<void> {
    const path = (req.url ?? '').split(/[?#]/)[0] ?? '';
    const match = AREA_PATTERN.exec(path);
    if (match === null) {
        next();
        return;
    }
    const area = match[1] ?? DEV_AREA;
    const rest = match[2] ?? '';

    // API und Manifest kommen in den Browser-Tests aus page.route(); hier nie die App-Seite.
    if (rest.startsWith('api/') || rest === 'manifest.webmanifest') {
        res.statusCode = 404;
        res.end();
        return;
    }

    if (rest !== '' && rest !== 'index.html' && (await isFile(distDir, rest))) {
        req.url = '/' + rest;
        next();
        return;
    }
    if (rest.startsWith('assets/')) {
        res.statusCode = 404;
        res.end();
        return;
    }

    const html = await readFile(join(distDir, 'index.html'), 'utf8');
    res.statusCode = 200;
    res.setHeader('Content-Type', 'text/html; charset=utf-8');
    res.setHeader('Cache-Control', 'no-cache');
    res.setHeader('Content-Security-Policy', PREVIEW_CSP);
    res.end(injectBase(html, area));
}

async function isFile(distDir: string, rest: string): Promise<boolean> {
    const file = normalize(join(distDir, rest));
    if (!file.startsWith(distDir + sep)) {
        return false;
    }
    try {
        return (await stat(file)).isFile();
    } catch {
        return false;
    }
}
