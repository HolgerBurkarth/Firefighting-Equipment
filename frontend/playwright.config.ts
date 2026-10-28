import { defineConfig, devices } from '@playwright/test';

const isCi = process.env['CI'] !== undefined;
const PORT = 4173;

/**
 * `php -S` beantwortet Anfragen nacheinander in einem Prozess. Laden zu viele Browser gleichzeitig
 * (je Seite rund 20 Dateien), weist er Verbindungen ab (z. B. NS_ERROR_CONNECTION_REFUSED). Unter
 * Linux (CI) laufen deshalb mehrere PHP-Prozesse (PHP_CLI_SERVER_WORKERS). Unter Windows gibt es
 * das nicht: Dort sind die parallelen Playwright-Worker begrenzt (Standard wäre die Hälfte der
 * Kerne, lokal 8). Mit 4 lief die Suite in wiederholten Komplettläufen stabil (README, "Frontend").
 */
const isWindows = process.platform === 'win32';
const PHP_SERVER_WORKERS = 4;
const WINDOWS_TEST_WORKERS = 4;

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
    // Keine Wiederholungen: Sie würden zeitweise auftretende Fehler verdecken.
    retries: 0,
    ...(isWindows && { workers: WINDOWS_TEST_WORKERS }),
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
        ...(!isWindows && { env: { PHP_CLI_SERVER_WORKERS: String(PHP_SERVER_WORKERS) } }),
        url: `http://localhost:${PORT}/firefighting/test/`,
        reuseExistingServer: !isCi,
        timeout: 60_000,
    },
});
