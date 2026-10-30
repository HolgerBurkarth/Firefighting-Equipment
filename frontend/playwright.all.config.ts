import { ALL_PROJECTS, createConfig, WINDOWS_TEST_WORKERS } from './playwright.config';

/**
 * Alle fünf Profile auch lokal (`npm run test:e2e:all`, Lastläufe über `npm run test:load`).
 *
 * Bewusst ohne eigene Grenze für Firefox (`workers` im Projekt): Gemessen am 30.10.2026 (README,
 * "Frontend") mit je 3 Komplettläufen bei 4 Workern: ohne Grenze 398–408 s und fehlerfrei, 2
 * Firefox-Worker 401–408 s mit einmal NS_ERROR_CONNECTION_REFUSED, 1 Firefox-Worker 735–749 s
 * fehlerfrei. Die Abweisung kommt vom PHP-Server des einzelnen Workers (je Worker ein eigener),
 * nicht von der Zahl der Firefox-Worker; eine Grenze machte den Lauf nur langsamer.
 */
export default createConfig(ALL_PROJECTS, WINDOWS_TEST_WORKERS.all);
