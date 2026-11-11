import { defineConfig } from '@playwright/test';

/**
 * Live-Lauf gegen die Test-Stadt (`npm run test:live-offline`), NICHT in der CI und nicht Teil von
 * `npm run test:e2e`. Eigene Konfiguration: eine Datei, ein Worker, keine Wiederholungen, kein
 * Trace, keine Screenshots, kein Video (beim Anmelden dürfen keine Eingaben aufgezeichnet werden).
 */
export default defineConfig({
    testDir: '.',
    testMatch: 'offline.live.ts',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    forbidOnly: true,
    timeout: 600_000,
    expect: { timeout: 20_000 },
    reporter: [['line']],
    outputDir: '../../test-results/live',
    use: {
        locale: 'de-DE',
        timezoneId: 'Europe/Berlin',
        trace: 'off',
        screenshot: 'off',
        video: 'off',
    },
});
