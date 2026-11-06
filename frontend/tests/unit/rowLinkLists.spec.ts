// @vitest-environment node
import { readFileSync, readdirSync } from 'node:fs';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

/** Regel für alle Listen: Zeile mit `v-row-link` und Link mit `data-row-link` gehören zusammen. */
const src = fileURLToPath(new URL('../../src/', import.meta.url));

function vueFiles(dir: string): string[] {
    return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const path = join(dir, entry.name);
        if (entry.isDirectory()) {
            return vueFiles(path);
        }
        return entry.name.endsWith('.vue') ? [path] : [];
    });
}

const files = vueFiles(src).map((path) => ({
    name: relative(src, path).replace(/\\/g, '/'),
    text: readFileSync(path, 'utf8'),
}));

describe('Listen mit ganzer Zeile als Link', () => {
    it('sind genau diese (neue Listen mit Link im Namen hier ergänzen)', () => {
        const used = files
            .filter((file) => file.text.includes('v-row-link'))
            .map((file) => file.name)
            .sort();
        expect(used).toEqual([
            'components/TenantRolesPanel.vue',
            'components/TenantTable.vue',
            'pages/AdminDashboardPage.vue',
            'pages/DeviceTypesPage.vue',
            'pages/DevicesPage.vue',
            'pages/LocationsPage.vue',
        ]);
    });

    it.each(files.filter((file) => /v-row-link|data-row-link/.test(file.text)))(
        '$name: je Zeile mit v-row-link ein Link mit data-row-link',
        ({ text }) => {
            const rows = text.match(/\sv-row-link\b/g)?.length ?? 0;
            const links = text.match(/\sdata-row-link\b/g)?.length ?? 0;
            expect(rows).toBeGreaterThan(0);
            expect(links).toBe(rows);
            expect(text).toMatch(/import \{ vRowLink \} from '(\.\.\/components|\.)\/rowLink';/);
        },
    );
});
