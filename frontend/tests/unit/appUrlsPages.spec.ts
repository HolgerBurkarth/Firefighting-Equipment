// @vitest-environment node
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { createRoutes } from '../../src/router';

/** Seiten, auf die Mail-Links zeigen (AppUrls::*_PAGE im Backend), muss es im Frontend geben. */
const appUrlsPhp = readFileSync(new URL('../../../src/Http/AppUrls.php', import.meta.url), 'utf8');
const pages = [...appUrlsPhp.matchAll(/const string \w+_PAGE = '([a-z-]+)';/g)].map(
    (match) => match[1] ?? '',
);

/** Kommt mit Schritt 5 (Einladung annehmen). */
const NOT_YET = ['einladung-annehmen'];

const tenantPaths = createRoutes({
    path: '/firefighting/test/',
    area: 'test',
    isService: false,
}).map((route) => route.path);

describe('Seiten der Mail-Links (AppUrls)', () => {
    it('liest die Seiten aus AppUrls.php', () => {
        expect(pages).toContain('passwort-zuruecksetzen');
        expect(pages).toContain('email-bestaetigen');
    });

    it.each(pages.filter((page) => !NOT_YET.includes(page)))(
        '/%s gibt es unter Städten',
        (page) => {
            expect(tenantPaths).toContain(`/${page}`);
        },
    );
});
