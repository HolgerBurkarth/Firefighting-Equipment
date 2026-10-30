/** Fehler beim Öffnen der Kamera, je mit eigener Meldung (`CAMERA_ERROR_MESSAGES`). */
export type CameraError = 'unsupported' | 'denied' | 'missing' | 'busy' | 'load';

export const CAMERA_ERROR_MESSAGES: Readonly<Record<CameraError, string>> = {
    unsupported:
        'Dieser Browser kann die Kamera nicht verwenden. Bitte tippen Sie den Code ins Suchfeld.',
    denied: 'Der Zugriff auf die Kamera ist nicht erlaubt. Bitte erlauben Sie ihn in den Einstellungen des Browsers und versuchen Sie es erneut.',
    missing: 'Es wurde keine Kamera gefunden.',
    busy: 'Die Kamera lässt sich nicht starten. Vielleicht verwendet eine andere App sie gerade.',
    load: 'Der Scanner konnte nicht geladen werden. Bitte prüfen Sie die Verbindung und versuchen Sie es erneut.',
};

/** Rückkamera, wo vorhanden; die Auflösung genügt für Barcodes und bleibt schnell lesbar. */
export const CAMERA_CONSTRAINTS: MediaStreamConstraints = {
    audio: false,
    video: {
        facingMode: { ideal: 'environment' },
        width: { ideal: 1280 },
        height: { ideal: 720 },
    },
};

/** Ordnet einen Fehler von `getUserMedia` einer Meldung zu. */
export function cameraError(error: unknown): CameraError {
    const name = error instanceof Error || error instanceof DOMException ? error.name : '';
    switch (name) {
        case 'NotAllowedError':
        case 'SecurityError':
            return 'denied';
        case 'NotFoundError':
        case 'OverconstrainedError':
            return 'missing';
        default:
            return 'busy';
    }
}

/**
 * Öffnet die Kamera; wirft `CameraOpenError` mit dem Code. `navigator.mediaDevices` fehlt ohne
 * sichere Verbindung (HTTPS bzw. localhost) und in manchen eingebetteten Browsern.
 */
export async function openCamera(
    mediaDevices: Pick<MediaDevices, 'getUserMedia'> | undefined = (navigator as Partial<Navigator>)
        .mediaDevices,
): Promise<MediaStream> {
    if (typeof mediaDevices?.getUserMedia !== 'function') {
        throw new CameraOpenError('unsupported');
    }
    try {
        return await mediaDevices.getUserMedia(CAMERA_CONSTRAINTS);
    } catch (error) {
        throw new CameraOpenError(cameraError(error));
    }
}

export class CameraOpenError extends Error {
    constructor(readonly code: CameraError) {
        super(CAMERA_ERROR_MESSAGES[code]);
        this.name = 'CameraOpenError';
    }
}

/** Beendet alle Spuren (Kamera-Lampe aus). */
export function stopStream(stream: MediaStream | null): void {
    stream?.getTracks().forEach((track) => {
        track.stop();
    });
}

/** Längste Seite des Bildes, das gelesen wird (schneller bei großen Kameras, genügt für Codes). */
export const MAX_FRAME_EDGE = 1280;

/** Größe des gelesenen Bildes bei gleichem Seitenverhältnis, höchstens `MAX_FRAME_EDGE`. */
export function frameSize(width: number, height: number): { width: number; height: number } {
    const scale = Math.min(1, MAX_FRAME_EDGE / Math.max(width, height, 1));
    return {
        width: Math.max(1, Math.round(width * scale)),
        height: Math.max(1, Math.round(height * scale)),
    };
}
