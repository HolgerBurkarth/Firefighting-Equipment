// @vitest-environment node
import { describe, expect, it } from 'vitest';
import { NAVIGATE_FALLBACK_DENYLIST } from '../../build/navigateFallback';

/** Wie NavigationRoute von Workbox: Pfad samt Query-String gegen jede Regel. */
function servedByServer(pathAndQuery: string): boolean {
    return NAVIGATE_FALLBACK_DENYLIST.some((pattern) => pattern.test(pathAndQuery));
}

describe('NAVIGATE_FALLBACK_DENYLIST', () => {
    it.each([
        '/firefighting/test/api/v1/tenant',
        '/firefighting/service/api/v1/health',
        '/firefighting/test/manifest.webmanifest',
        '/firefighting/test/manifest.webmanifest?v=1',
        '/firefighting/test/mcp',
        '/firefighting/test/mcp?x=1',
        '/firefighting/test/mcp/',
        '/firefighting/test/claude-verbinden',
        '/firefighting/test/claude-verbinden?response_type=code&client_id=probe-1&state=a%2Fb',
        '/firefighting/test/oauth/register',
        '/firefighting/test/oauth/token',
        '/firefighting/test/oauth',
        '/firefighting/test/.well-known/openid-configuration',
        '/firefighting/test/.well-known/oauth-protected-resource',
        '/firefighting/hattingen-ost/.well-known/jwks.json',
    ])('%s kommt vom Server', (path) => {
        expect(servedByServer(path)).toBe(true);
    });

    it.each([
        '/firefighting/test/',
        '/firefighting/test/anmelden',
        '/firefighting/test/suchen?q=mcp',
        '/firefighting/test/geraete/12/details',
        '/firefighting/test/mcpx',
        '/firefighting/test/claude-verbinden-x',
        '/firefighting/test/oauthx',
        '/firefighting/test/geraete/mcp',
        '/firefighting/test/geraete/oauth/x',
        '/firefighting/test/passwort-zuruecksetzen?weiter=/firefighting/test/mcp',
        '/firefighting/test/apix',
    ])('%s liefert der Service Worker als App-Seite', (path) => {
        expect(servedByServer(path)).toBe(false);
    });
});
