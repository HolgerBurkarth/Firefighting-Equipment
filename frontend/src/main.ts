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

const updater = new AppUpdater(browserUpdateEnvironment());
const router = createAppRouter(base);
installUpdateGuard(router, updater);

createApp(App, { base, updater }).use(router).mount('#app');

if (import.meta.env.PROD) {
    void registerServiceWorker(base).then((registration) => {
        if (registration !== null) {
            updater.attach(registration);
        }
        startUpdateChecks(router, updater);
    });
}
