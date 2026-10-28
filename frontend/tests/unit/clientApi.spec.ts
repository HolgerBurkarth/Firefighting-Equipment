// @vitest-environment node
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { CLIENT_API_HEADER, CLIENT_API_VERSION } from '../../src/api/client';

const ROOT = new URL('../../../', import.meta.url);

const shared = JSON.parse(readFileSync(new URL('resources/client-api.json', ROOT), 'utf8')) as {
    header: string;
    client_api: number;
};

const clientApiPhp = readFileSync(new URL('src/Http/ClientApi.php', ROOT), 'utf8');

describe('Vertragsnummer der API (X-Client-Api)', () => {
    it('gleich mit resources/client-api.json', () => {
        expect(CLIENT_API_HEADER).toBe(shared.header);
        expect(CLIENT_API_VERSION).toBe(shared.client_api);
    });

    it('gleich mit ClientApi im Backend', () => {
        expect(clientApiPhp).toContain(`public const int CURRENT = ${String(CLIENT_API_VERSION)};`);
        expect(clientApiPhp).toContain(`public const string HEADER = '${CLIENT_API_HEADER}';`);
    });
});
