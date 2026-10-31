/**
 * Firefox meldet einen Schrift-Download, den das Neuladen oder Schließen der Seite abbricht, als
 * Konsolenfehler (status=2152398850 = NS_BINDING_ABORTED), z. B. „downloadable font: download failed
 * (font-family: "Source Sans 3" …): status=2152398850 source: http://localhost:4173/firefighting/
 * test/assets/source-sans-3-latin-600-normal-….woff2“. Das ist kein Fehler der App.
 */
export const NS_BINDING_ABORTED = 2152398850;

const FONT_DOWNLOAD_FAILED =
    /downloadable font: download failed \(font-family: [^)]*\): status=(\d+) source: ([^\s"\]]+)/;

/** Schrift im Ordner assets/ des Builds (Namen wie FrontendAssets, nur woff2/woff). */
const BUILD_FONT_PATH = /^\/firefighting\/[a-z0-9-]+\/assets\/[A-Za-z0-9][A-Za-z0-9_.-]*\.woff2?$/;

/**
 * true nur für genau diesen Fall: Firefox, abgebrochener Schrift-Download (NS_BINDING_ABORTED), Datei
 * aus assets/ des eigenen Testservers (`origin`, liefert nur den eigenen Build). Andere Schriftfehler
 * (z. B. 404, fremder Server, Datei außerhalb von assets/) und andere Browser bleiben Fehler.
 */
export function isAbortedFontDownload(text: string, browserName: string, origin: string): boolean {
    if (browserName !== 'firefox') {
        return false;
    }
    const match = FONT_DOWNLOAD_FAILED.exec(text);
    if (match?.[1] !== String(NS_BINDING_ABORTED) || match[2] === undefined) {
        return false;
    }
    let source: URL;
    try {
        source = new URL(match[2]);
    } catch {
        return false;
    }
    return (
        source.origin === origin && source.search === '' && BUILD_FONT_PATH.test(source.pathname)
    );
}
