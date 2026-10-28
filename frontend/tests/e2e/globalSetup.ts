import { spawnSync } from 'node:child_process';
import { mkdirSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

/** Zweiter Build mit anderer Kennung für die Update-Tests (tests/e2e/appUpdate.spec.ts). */
export const NEXT_BUILD_ID = 'e2e-next';

const frontend = fileURLToPath(new URL('../../', import.meta.url));

/**
 * Baut die „neue Version“ nach test-builds/next (frontend/dist ist die alte, von `npm run build`).
 * Gleicher Quellcode, nur eine andere Build-Kennung: dadurch andere Dateinamen und ein anderes
 * sw.js, also für den Browser ein echtes Update. Die Schaltdateien in test-builds/state werden
 * geleert (siehe tests/e2e/support/update-server.php).
 */
export default function globalSetup(): void {
    const state = join(frontend, 'test-builds', 'state');
    rmSync(state, { recursive: true, force: true });
    mkdirSync(state, { recursive: true });
    const result = spawnSync(
        'npx',
        ['vite', 'build', '--outDir', 'test-builds/next', '--emptyOutDir'],
        {
            cwd: frontend,
            env: { ...process.env, FF_BUILD_ID: NEXT_BUILD_ID },
            shell: process.platform === 'win32',
            stdio: ['ignore', 'ignore', 'inherit'],
        },
    );
    if (result.status !== 0) {
        throw new Error('Zweiter Build für die Update-Tests ist gescheitert.');
    }
}
