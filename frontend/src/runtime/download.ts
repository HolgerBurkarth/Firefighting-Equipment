import type { DownloadedFile } from '../api/client';

/** Freigabe der `blob:`-Adresse erst nach dem Start des Downloads (Firefox braucht sie noch kurz). */
const REVOKE_DELAY_MS = 10_000;

/**
 * Speichert eine heruntergeladene Datei (apiDownload) über eine `blob:`-Adresse und
 * `<a download>`; kein Link direkt auf die API, sonst kämen Fehler als Datei.
 */
export function saveDownload(file: DownloadedFile, doc: Document = document): void {
    const url = URL.createObjectURL(file.blob);
    const link = doc.createElement('a');
    link.href = url;
    link.download = file.fileName;
    link.click();
    setTimeout(() => {
        URL.revokeObjectURL(url);
    }, REVOKE_DELAY_MS);
}
