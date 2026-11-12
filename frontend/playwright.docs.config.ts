import { defineConfig, devices } from '@playwright/test';

/**
 * Anleitungen als PDF (Runde 14, Punkt 4; `npm run docs:anleitungen`): Bildschirmfotos der gebauten App
 * mit erfundenen Beispieldaten (API-Attrappe wie in den Browser-Tests, nie echte Daten), zusammengesetzt
 * zu je einem PDF je Rolle in docs/anleitungen/. Nur Chromium (page.pdf), nicht Teil von
 * `npm run test:e2e` und nicht in der CI.
 */
export default defineConfig({
    testDir: 'tests/docs',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    reporter: [['list']],
    timeout: 120_000,
    use: {
        locale: 'de-DE',
        timezoneId: 'Europe/Berlin',
        serviceWorkers: 'block',
        ...devices['Desktop Chrome'],
        deviceScaleFactor: 2,
    },
    projects: [{ name: 'anleitungen' }],
});
