<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, useTemplateRef } from 'vue';
import type { BarcodeReader } from '../scanner/barcodeReader';
import {
    CAMERA_ERROR_MESSAGES,
    CameraOpenError,
    frameSize,
    openCamera,
    stopStream,
    type CameraError,
} from '../scanner/camera';
import FocusHeader from './FocusHeader.vue';

/**
 * Kamera-Scanner (KONZEPT.md, Entscheidungen 17 und 27), bildschirmfüllend über der Seite. Lädt das
 * Lesemodul erst jetzt (eigener Teil des Builds), öffnet die Rückkamera und liest alle
 * `SCAN_INTERVAL_MS` ein Bild; beim ersten Code ist die Kamera sofort aus und `detected` meldet den
 * Text unverändert (auch mit GS1-Trennzeichen).
 */
const emit = defineEmits<{
    detected: [code: string];
    close: [];
}>();

/** Pause zwischen zwei gelesenen Bildern (das Lesen selbst dauert am Handy einige 10 ms). */
const SCAN_INTERVAL_MS = 120;

type ScannerState = 'starting' | 'scanning' | 'detected' | 'error';

const state = ref<ScannerState>('starting');
const error = ref<CameraError | null>(null);
const video = useTemplateRef<HTMLVideoElement>('video');

let stream: MediaStream | null = null;
let timer: ReturnType<typeof setTimeout> | undefined;
/** Jeder Start erhält eine neue Nummer; ältere Abläufe (z. B. nach dem Schließen) enden still. */
let run = 0;
const canvas = document.createElement('canvas');

function stopCamera(): void {
    clearTimeout(timer);
    stopStream(stream);
    stream = null;
    if (video.value !== null) {
        video.value.srcObject = null;
    }
}

function fail(code: CameraError): void {
    stopCamera();
    error.value = code;
    state.value = 'error';
}

async function start(): Promise<void> {
    const current = ++run;
    stopCamera();
    error.value = null;
    state.value = 'starting';
    const [reader, camera] = await Promise.allSettled([
        import('../scanner/barcodeReader').then((module) => module.loadBarcodeReader()),
        openCamera(),
    ]);
    if (current !== run) {
        if (camera.status === 'fulfilled') {
            stopStream(camera.value);
        }
        return;
    }
    if (camera.status === 'rejected') {
        fail(camera.reason instanceof CameraOpenError ? camera.reason.code : 'busy');
        return;
    }
    stream = camera.value;
    if (reader.status === 'rejected') {
        fail('load');
        return;
    }
    const element = video.value;
    if (element === null) {
        stopCamera();
        return;
    }
    element.srcObject = stream;
    // iOS spielt nur mit playsinline und muted ab; ein Fehler hier (z. B. abgebrochen) ist egal,
    // gelesen wird, sobald das Video Bilder liefert.
    await element.play().catch(() => undefined);
    if (current !== run) {
        return;
    }
    state.value = 'scanning';
    void scanFrame(current, reader.value);
}

async function scanFrame(current: number, reader: BarcodeReader): Promise<void> {
    const element = video.value;
    const context = canvas.getContext('2d', { willReadFrequently: true });
    if (
        element !== null &&
        context !== null &&
        element.readyState >= HTMLMediaElement.HAVE_CURRENT_DATA &&
        element.videoWidth > 0
    ) {
        const size = frameSize(element.videoWidth, element.videoHeight);
        canvas.width = size.width;
        canvas.height = size.height;
        context.drawImage(element, 0, 0, size.width, size.height);
        const code = await reader(context.getImageData(0, 0, size.width, size.height)).catch(
            () => null,
        );
        if (current !== run || state.value !== 'scanning') {
            return;
        }
        if (code !== null) {
            state.value = 'detected';
            stopCamera();
            emit('detected', code);
            return;
        }
    }
    timer = setTimeout(() => void scanFrame(current, reader), SCAN_INTERVAL_MS);
}

function close(): Promise<void> {
    run += 1;
    stopCamera();
    emit('close');
    return Promise.resolve();
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        void close();
    }
}

onMounted(() => {
    void start();
});

onBeforeUnmount(() => {
    run += 1;
    stopCamera();
});
</script>

<template>
    <Teleport to="body">
        <div
            class="scanner"
            role="dialog"
            aria-modal="true"
            aria-label="Code scannen"
            data-testid="code-scanner"
            @keydown="onKeydown"
        >
            <FocusHeader
                title="Code scannen"
                back-label="Scanner schließen"
                :back-action="close"
                focus-title
            />
            <div class="scanner-body">
                <div v-show="state === 'starting' || state === 'scanning'" class="viewfinder">
                    <video
                        ref="video"
                        class="viewfinder-video"
                        playsinline
                        muted
                        autoplay
                        data-testid="scanner-video"
                    ></video>
                    <div class="viewfinder-frame" aria-hidden="true"></div>
                </div>

                <p v-if="state === 'starting'" class="hint" role="status">
                    Kamera wird gestartet …
                </p>
                <p v-else-if="state === 'scanning'" class="hint" role="status">
                    Halten Sie den Barcode oder QR-Code in den Rahmen.
                </p>
                <p v-else-if="state === 'detected'" class="hint" role="status">
                    Code erkannt – Gerät wird gesucht …
                </p>
                <div
                    v-else-if="error !== null"
                    class="notice notice-error"
                    role="alert"
                    data-testid="scanner-error"
                >
                    <p>{{ CAMERA_ERROR_MESSAGES[error] }}</p>
                    <button v-if="error !== 'unsupported'" class="btn" type="button" @click="start">
                        Erneut versuchen
                    </button>
                </div>

                <button class="btn" type="button" @click="close">Abbrechen</button>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
.scanner {
    position: fixed;
    inset: 0;
    z-index: 50;
    overflow-y: auto;
    background: var(--bg);
    color: var(--text);
}

.scanner-body {
    display: flex;
    flex-direction: column;
    gap: 14px;
    max-width: 600px;
    margin: 0 auto;
    padding: 16px;
}

.viewfinder {
    position: relative;
    overflow: hidden;
    aspect-ratio: 3 / 4;
    max-height: 65vh;
    border-radius: var(--radius-lg);
    background: var(--camera-bg);
}

.viewfinder-video {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.viewfinder-frame {
    position: absolute;
    inset: 22% 12%;
    border: 3px solid var(--camera-frame);
    border-radius: var(--radius-md);
}

.hint {
    margin: 0;
    color: var(--muted);
    text-align: center;
}
</style>
