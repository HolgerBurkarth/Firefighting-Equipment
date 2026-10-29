import { defineConfig, devices, type PlaywrightTestConfig } from '@playwright/test';

const isCi = process.env['CI'] !== undefined;

/**
 * Alle Profile. Lokal laufen standardmäßig nur LOCAL_PROJECTS (`npm run test:e2e`), alle fünf mit
 * `npm run test:e2e:all` (playwright.all.config.ts) und immer in CI: Firefox und die Handy-Profile
 * prüft vor allem CI.
 */
type Projects = NonNullable<PlaywrightTestConfig['projects']>;

export const ALL_PROJECTS: Projects = [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    { name: 'firefox', use: { ...devices['Desktop Firefox'] } },
    { name: 'webkit', use: { ...devices['Desktop Safari'] } },
    { name: 'handy-webkit', use: { ...devices['iPhone 13'] } },
    { name: 'handy-chromium', use: { ...devices['Pixel 7'] } },
];
const LOCAL_PROJECTS = ['chromium', 'webkit'];

/**
 * Parallele Playwright-Worker unter Windows. Dort läuft je Server nur ein PHP-Prozess
 * (tests/e2e/support/phpServer.ts); unter hoher Last weist er Verbindungen ab (Firefox:
 * NS_ERROR_CONNECTION_REFUSED). Gemessen (README, "Frontend"): Mit allen fünf Profilen scheiterte
 * Firefox ab 6 Workern, also 4. Nur Chromium und WebKit liefen mit 4, 6 und 8 stabil (6 und 8 gleich
 * schnell), also 6 mit Reserve. Unter Linux (CI) gilt der Standard von Playwright.
 */
export const WINDOWS_TEST_WORKERS = { all: 4, local: 6 } as const;

/**
 * Browser-Tests gegen den Build (`npm run build`), ausgeliefert wie auf dem Server von PHP
 * (`php -S` mit public/index.php, also mit CSP und Caching aus FrontendHandler). Jeder Worker
 * startet eigene Server (tests/e2e/support/phpServer.ts, Fixtures in tests/e2e/fixtures.ts). Die
 * Datenbank wird nicht gebraucht: API und Manifest kommen aus page.route(). Jeder Konsolenfehler
 * und jede CSP-Verletzung lässt den Test scheitern.
 */
export function createConfig(projects: Projects, windowsWorkers: number): PlaywrightTestConfig {
    return defineConfig({
        testDir: 'tests/e2e',
        // Baut die zweite Version für appUpdate.spec.ts (test-builds/next).
        globalSetup: './tests/e2e/globalSetup.ts',
        fullyParallel: true,
        forbidOnly: isCi,
        // Keine Wiederholungen: Sie würden zeitweise auftretende Fehler verdecken.
        retries: 0,
        ...(process.platform === 'win32' && { workers: windowsWorkers }),
        reporter: isCi ? [['github'], ['html', { open: 'never' }]] : [['list']],
        use: {
            locale: 'de-DE',
            timezoneId: 'Europe/Berlin',
            // Ein Service Worker würde Anfragen an page.route() vorbei beantworten; nur die Tests
            // in serviceWorker.spec.ts und appUpdate.spec.ts lassen ihn zu.
            serviceWorkers: 'block',
            trace: 'retain-on-failure',
        },
        projects,
    });
}

export default isCi
    ? createConfig(ALL_PROJECTS, WINDOWS_TEST_WORKERS.all)
    : createConfig(
          ALL_PROJECTS.filter((project) => LOCAL_PROJECTS.includes(project.name ?? '')),
          WINDOWS_TEST_WORKERS.local,
      );
