import '@fontsource/source-sans-3/latin-400.css';
import '@fontsource/source-sans-3/latin-600.css';
import '@fontsource/source-sans-3/latin-700.css';
import './styles/tokens.css';
import './styles/base.css';

import { createApp } from 'vue';
import App from './App.vue';
import { setForbiddenHandler, setOutdatedHandler } from './api/client';
import { configureStoredSession, recheckSession } from './auth/session';
import { bookingQueue, deviceStore, installDataLayer, locationStore, otherQueues } from './data';
import { installBookingQueue, installOfflineCopy } from './data/offline';
import { installServiceDataLayer } from './data/service';
import { createAppRouter, preloadPage } from './router';
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
import { installConnectivity } from './runtime/connectivity';
import { registerServiceWorker } from './runtime/serviceWorker';
import { applyThemeMode, browserStorage, loadThemeMode } from './runtime/theme';

const base = currentAppBase();

// Gespeicherten Anzeigemodus vor dem ersten Zeichnen der App setzen.
applyThemeMode(document.documentElement, loadThemeMode(browserStorage()));
// Web-App-Manifest und Gerätedaten nur unter Städten (unter service gibt es beides nicht), dort
// stattdessen die Listen des Super-Admins.
if (base.isService) {
    installServiceDataLayer();
} else {
    linkManifest(document);
    installDataLayer();
    // Offline-Modus (KONZEPT.md, "Offline-Modus – Entscheidungen", 49 und 50): letzte bestätigte
    // Sitzung und lokale Kopie der Daten je Stadt und Konto.
    configureStoredSession(`ff-session:${base.area}`, browserStorage());
    installOfflineCopy({ area: base.area, devices: deviceStore, locations: locationStore });
    installBookingQueue({
        area: base.area,
        queue: bookingQueue,
        devices: deviceStore,
        others: otherQueues,
    });
    // Rechte ändern sich während der Sitzung (Plan Phase 1, Schritt 1.7c): auth/me neu beim
    // Zurückkehren in die App und nach 403 forbidden; neue Rechte übernimmt setSession.
    setForbiddenHandler(() => {
        void recheckSession('forbidden');
    });
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            void recheckSession('visible');
        }
    });
}
installConnectivity();

// Kennung des Builds (Support, Browser-Tests der Updates).
document.documentElement.dataset['build'] = BUILD_ID;

const updater = new AppUpdater(browserUpdateEnvironment(BUILD_ID));
const router = createAppRouter(base);
// Die erste Seite lädt gleichzeitig mit tenant und auth/me (Plan Phase 1, Schritt 1.7d).
preloadPage(router, router.options.history.location);
// Antworten der API, die ein Update verlangen (409 client_outdated, neuere X-Api-Version): sofort
// prüfen (CLAUDE.md, "Versionierung der API für das Frontend").
setOutdatedHandler((reason) => {
    void updater.reportOutdated(reason, () => window.location.href);
});

installUpdateGuard(router, updater);

createApp(App, { base, updater }).provide(UPDATER_KEY, updater).use(router).mount('#app');

if (import.meta.env.PROD) {
    // Die erste Seite wartet nicht auf die Prüfung (Plan Phase 1, Schritt 1.7d): Ist die Seite aus
    // dem Cache des Service Workers veraltet, kommt die neue Version beim nächsten Seitenwechsel
    // (KONZEPT.md, "Updates der App").
    const registered = registerServiceWorker(base).then((registration) => {
        if (registration !== null) {
            updater.attach(registration);
        }
    });
    void updater.checkOnStart(registered);
    startUpdateChecks(router, updater);
}
