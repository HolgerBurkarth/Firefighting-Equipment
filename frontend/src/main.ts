import '@fontsource/source-sans-3/latin-400.css';
import '@fontsource/source-sans-3/latin-600.css';
import '@fontsource/source-sans-3/latin-700.css';
import './styles/tokens.css';
import './styles/base.css';

import { createApp } from 'vue';
import App from './App.vue';
import { createAppRouter } from './router';
import { currentAppBase } from './runtime/appBase';
import { linkManifest } from './runtime/appearance';
import {
    AppUpdater,
    UPDATER_KEY,
    browserUpdateEnvironment,
    installUpdateGuard,
    startUpdateChecks,
} from './runtime/appUpdate';
import { BUILD_ID } from './runtime/build';
import { registerServiceWorker } from './runtime/serviceWorker';
import { applyThemeMode, browserStorage, loadThemeMode } from './runtime/theme';

const base = currentAppBase();

// Gespeicherten Anzeigemodus vor dem ersten Zeichnen der App setzen.
applyThemeMode(document.documentElement, loadThemeMode(browserStorage()));
// Web-App-Manifest nur unter Städten (unter service gibt es keins).
if (!base.isService) {
    linkManifest(document);
}

// Kennung des Builds (Support, Browser-Tests der Updates).
document.documentElement.dataset['build'] = BUILD_ID;

// Adresse beim Start samt #token=… bzw. #code=… (die Seiten entfernen das Fragment erst danach).
const startUrl = window.location.href;
const updater = new AppUpdater(browserUpdateEnvironment(BUILD_ID));
const router = createAppRouter(base);

// Service Worker vor der ersten Seite registrieren: Der Start prüft, ob die Seite aus seinem Cache
// veraltet ist, und lädt dann sofort die neue Version (KONZEPT.md, "Updates der App").
const registered = import.meta.env.PROD
    ? registerServiceWorker(base).then((registration) => {
          if (registration !== null) {
              updater.attach(registration);
          }
      })
    : Promise.resolve();
const startup = import.meta.env.PROD
    ? updater.checkOnStart(startUrl, registered)
    : Promise.resolve(false);
installUpdateGuard(router, updater, startup);

createApp(App, { base, updater }).provide(UPDATER_KEY, updater).use(router).mount('#app');

if (import.meta.env.PROD) {
    // Lädt der Start neu, keine Prüfungen mehr (ihre Anfragen bräche das Neuladen ab).
    void startup.then((reloading) => {
        if (!reloading) {
            startUpdateChecks(router, updater);
        }
    });
}
