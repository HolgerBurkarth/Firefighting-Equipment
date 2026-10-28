/**
 * Dateinamen des Builds passend zu FrontendAssets in PHP (FILE_NAME_PATTERN): nur A-Z, a-z, 0-9,
 * Unterstrich, Punkt und Bindestrich, erstes Zeichen Buchstabe oder Ziffer. Sonst liefert der Server
 * die Datei nicht aus (404), z. B. den gemeinsamen Teil "_plugin-vue_export-helper" von Vue.
 */
export function sanitizeFileName(name: string): string {
    const cleaned = name.replace(/[^A-Za-z0-9_.-]/g, '_').replace(/^[^A-Za-z0-9]+/, '');
    return cleaned === '' ? 'chunk' : cleaned;
}
