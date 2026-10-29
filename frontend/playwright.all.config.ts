import { ALL_PROJECTS, createConfig, WINDOWS_TEST_WORKERS } from './playwright.config';

/** Alle fünf Profile auch lokal (`npm run test:e2e:all`, Lastläufe über `npm run test:load`). */
export default createConfig(ALL_PROJECTS, WINDOWS_TEST_WORKERS.all);
