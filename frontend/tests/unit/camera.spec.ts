import { describe, expect, it } from 'vitest';
import {
    CAMERA_CONSTRAINTS,
    CAMERA_ERROR_MESSAGES,
    cameraError,
    CameraOpenError,
    frameSize,
    MAX_FRAME_EDGE,
    openCamera,
    stopStream,
} from '../../src/scanner/camera';

describe('openCamera', () => {
    it('ohne mediaDevices (keine sichere Verbindung) → unsupported', async () => {
        await expect(openCamera(undefined)).rejects.toMatchObject({ code: 'unsupported' });
    });

    it('fragt die Rückkamera ohne Ton', async () => {
        const asked: MediaStreamConstraints[] = [];
        const stream = {} as MediaStream;
        const result = await openCamera({
            getUserMedia: (constraints) => {
                asked.push(constraints ?? {});
                return Promise.resolve(stream);
            },
        });
        expect(result).toBe(stream);
        expect(asked).toEqual([CAMERA_CONSTRAINTS]);
        expect(CAMERA_CONSTRAINTS).toMatchObject({
            audio: false,
            video: { facingMode: { ideal: 'environment' } },
        });
    });

    it.each([
        ['NotAllowedError', 'denied'],
        ['SecurityError', 'denied'],
        ['NotFoundError', 'missing'],
        ['OverconstrainedError', 'missing'],
        ['NotReadableError', 'busy'],
        ['AbortError', 'busy'],
    ] as const)('%s → %s', async (name, code) => {
        const failing = {
            getUserMedia: () => Promise.reject(new DOMException('x', name)),
        };
        const error: unknown = await openCamera(failing).catch((caught: unknown) => caught);
        expect(error).toBeInstanceOf(CameraOpenError);
        expect(error).toMatchObject({ code, message: CAMERA_ERROR_MESSAGES[code] });
    });

    it('unbekannte Fehler → busy', () => {
        expect(cameraError('kaputt')).toBe('busy');
    });
});

describe('frameSize', () => {
    it('kleine Bilder unverändert', () => {
        expect(frameSize(640, 480)).toEqual({ width: 640, height: 480 });
    });

    it('große Bilder auf die längste Seite verkleinert, Seitenverhältnis bleibt', () => {
        expect(frameSize(1920, 1080)).toEqual({ width: MAX_FRAME_EDGE, height: 720 });
        expect(frameSize(1080, 1920)).toEqual({ width: 720, height: MAX_FRAME_EDGE });
    });

    it('nie 0', () => {
        expect(frameSize(0, 0)).toEqual({ width: 1, height: 1 });
    });
});

describe('stopStream', () => {
    it('beendet alle Spuren', () => {
        const stopped: string[] = [];
        const track = (id: string) => ({ stop: () => stopped.push(id) });
        stopStream({ getTracks: () => [track('a'), track('b')] } as unknown as MediaStream);
        stopStream(null);
        expect(stopped).toEqual(['a', 'b']);
    });
});
