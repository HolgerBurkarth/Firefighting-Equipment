import type { Plugin } from 'vite';

/** Datei im Build mit der Kennung der ausgelieferten Version (src/runtime/appUpdate.ts liest sie). */
export const BUILD_INFO_FILE = 'build.json';

const BUILD_ID_PATTERN = /^[A-Za-z0-9._-]{1,40}$/;

/**
 * Kennung des Builds (src/runtime/build.ts): FF_BUILD_ID (z. B. zweiter Build der Browser-Tests),
 * sonst der Commit in GitHub Actions, sonst "dev". Gleicher Stand ergibt denselben Build.
 */
export function buildId(env: NodeJS.ProcessEnv = process.env): string {
    const id = env['FF_BUILD_ID'] ?? env['GITHUB_SHA']?.slice(0, 12) ?? 'dev';
    if (!BUILD_ID_PATTERN.test(id)) {
        throw new Error('FF_BUILD_ID: nur A-Z, a-z, 0-9, Punkt, Unterstrich, Bindestrich (1-40)');
    }
    return id;
}

/**
 * Legt build.json ({"build": "<kennung>"}) neben index.html. Die App vergleicht sie bei jeder
 * Prüfung auf Updates mit der eigenen Kennung: verschieden = neue Version, unabhängig davon, in
 * welchem Zustand der Service Worker ist. PHP liefert sie mit no-cache aus (FrontendHandler).
 */
export function buildInfo(id: string): Plugin {
    return {
        name: 'ff-build-info',
        apply: 'build',
        generateBundle() {
            this.emitFile({
                type: 'asset',
                fileName: BUILD_INFO_FILE,
                source: `${JSON.stringify({ build: id })}\n`,
            });
        },
    };
}
