import type { Connect, Plugin } from 'vite';

/**
 * Platzhalter für den Basispfad in index.html. Der Server (PHP, FrontendAssets::BASE_PLACEHOLDER)
 * ersetzt ihn je Anfrage durch "/firefighting/<bereich>/"; ein Build gilt so für alle Städte.
 */
export const BASE_PLACEHOLDER = '/__FF_BASE__/';

/** Bereich in der Adresse wie AppPath::parse (PHP): /firefighting/<bereich>/<rest>. */
const AREA_PATTERN = /^\/firefighting\/([a-z0-9-]+)(?:\/|$)/;

/** Bereich, wenn die Entwicklung ohne Stadt-Pfad aufgerufen wird. */
const DEV_AREA = 'test';

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
 * Basispfad wie auf dem Server: Im Build bleibt der Platzhalter stehen (PHP setzt ihn ein); nur
 * der Dev-Server (`npm run dev`) setzt ihn je Anfrage selbst ein.
 */
export function appBase(): Plugin {
    return {
        name: 'ff-app-base',

        transformIndexHtml: {
            order: 'pre',
            handler(html, ctx) {
                if (ctx.server === undefined) {
                    // Build: Platzhalter muss erhalten bleiben (prüft injectBase).
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
