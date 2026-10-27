import { defineConfig, devices } from '@playwright/test';

const isCi = process.env['CI'] !== undefined;
const PORT = 4173;

/**
 * Browser-Tests gegen den Build (`npm run build`), ausgeliefert wie auf dem Server von PHP
 * (`php -S` mit public/index.php, also mit CSP und Caching aus FrontendHandler). Die Datenbank wird
 * dafür nicht gebraucht: API und Manifest kommen aus page.route() (tests/e2e/fixtures.ts). Jeder
 * Konsolenfehler und jede CSP-Verletzung lässt den Test scheitern.
 */
export default defineConfig({
    testDir: 'tests/e2e',
    fullyParallel: true,
    forbidOnly: isCi,
    retries: 0,
    reporter: isCi ? [['github'], ['html', { open: 'never' }]] : [['list']],
    use: {
        baseURL: `http://localhost:${PORT}`,
        locale: 'de-DE',
        timezoneId: 'Europe/Berlin',
        // Ein Service Worker würde Anfragen an page.route() vorbei beantworten; nur die Tests in
        // serviceWorker.spec.ts lassen ihn zu.
        serviceWorkers: 'block',
        trace: 'retain-on-failure',
    },
    projects: [
        { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
        { name: 'firefox', use: { ...devices['Desktop Firefox'] } },
        { name: 'webkit', use: { ...devices['Desktop Safari'] } },
        { name: 'handy-webkit', use: { ...devices['iPhone 13'] } },
        { name: 'handy-chromium', use: { ...devices['Pixel 7'] } },
    ],
    webServer: {
        command: `php -q -S localhost:${PORT} public/index.php`,
        cwd: '..',
        url: `http://localhost:${PORT}/firefighting/test/`,
        reuseExistingServer: !isCi,
        timeout: 60_000,
    },
});
