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
import { registerServiceWorker } from './runtime/serviceWorker';
import { applyThemeMode, browserStorage, loadThemeMode } from './runtime/theme';

const base = currentAppBase();

// Gespeicherten Anzeigemodus vor dem ersten Zeichnen der App setzen.
applyThemeMode(document.documentElement, loadThemeMode(browserStorage()));
// Web-App-Manifest nur unter Städten (unter service gibt es keins).
if (!base.isService) {
    linkManifest(document);
}

createApp(App, { base }).use(createAppRouter(base)).mount('#app');

if (import.meta.env.PROD) {
    void registerServiceWorker(base);
}
