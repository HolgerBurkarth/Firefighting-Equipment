import '@fontsource/source-sans-3/latin-400.css';
import '@fontsource/source-sans-3/latin-600.css';
import '@fontsource/source-sans-3/latin-700.css';
import './styles/tokens.css';
import './styles/base.css';

import { createApp } from 'vue';
import App from './App.vue';
import { createAppRouter } from './router';
import { currentAppBase } from './runtime/appBase';
import { registerServiceWorker } from './runtime/serviceWorker';
import { applyThemeMode, browserStorage, loadThemeMode } from './runtime/theme';

const base = currentAppBase();

// Gespeicherten Anzeigemodus vor dem ersten Zeichnen der App setzen.
applyThemeMode(document.documentElement, loadThemeMode(browserStorage()));

createApp(App, { base }).use(createAppRouter(base)).mount('#app');

if (import.meta.env.PROD) {
    void registerServiceWorker(base);
}
