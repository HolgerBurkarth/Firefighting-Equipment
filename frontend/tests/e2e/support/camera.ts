import { readFileSync } from 'node:fs';
import type { Page } from '@playwright/test';
import { prepareZXingModule, writeBarcode, type WriteInputBarcodeFormat } from 'zxing-wasm/writer';

/** Module eines Codes zeilenweise, 0 = dunkel (wie `BarcodeSymbol.data` von zxing-wasm). */
export interface BarcodeSymbol {
    readonly width: number;
    readonly height: number;
    readonly modules: readonly number[];
}

let prepared: Promise<unknown> | null = null;

/**
 * Erzeugt einen Code mit dem Schreibmodul von zxing-wasm (nur in Tests, in Node; die WASM-Datei aus
 * node_modules, nie vom CDN). Die App liest ihn mit dem Lesemodul aus dem Kamerabild.
 */
export async function barcode(
    text: string,
    format: WriteInputBarcodeFormat,
): Promise<BarcodeSymbol> {
    prepared ??= (() => {
        const file = readFileSync(
            new URL(
                '../../../node_modules/zxing-wasm/dist/writer/zxing_writer.wasm',
                import.meta.url,
            ),
        );
        return prepareZXingModule({
            overrides: {
                wasmBinary: file.buffer.slice(file.byteOffset, file.byteOffset + file.byteLength),
            },
            fireImmediately: true,
        });
    })();
    await prepared;
    const result = await writeBarcode(text, { format });
    if (result.error !== '') {
        throw new Error(`Code nicht erzeugt: ${result.error}`);
    }
    return {
        width: result.symbol.width,
        height: result.symbol.height,
        modules: Array.from(result.symbol.data),
    };
}

/** Was die simulierte Kamera tut. */
export type FakeCamera =
    | { readonly kind: 'code'; readonly symbol: BarcodeSymbol }
    /** Weißes Bild ohne Code (der Scanner sucht weiter). */
    | { readonly kind: 'blank' }
    /** getUserMedia lehnt ab, z. B. NotAllowedError. */
    | { readonly kind: 'error'; readonly name: string }
    /** Ohne navigator.mediaDevices (wie ohne sichere Verbindung). */
    | { readonly kind: 'unsupported' };

/** Zähler der simulierten Kamera im Fenster (geöffnet, beendete Spuren). */
export interface CameraCounts {
    readonly opened: number;
    readonly stopped: number;
}

/**
 * Ersetzt `getUserMedia` vor dem Laden: Das Kamerabild ist eine Canvas (`captureStream`) mit dem
 * Code auf Weiß, mit Ruhezone, alle 100 ms neu gezeichnet (sonst liefern manche Browser keine neuen
 * Bilder). Echt bleiben Lesemodul, WASM-Datei, CSP und Auslieferung durch PHP.
 *
 * Playwright-WebKit unter Windows kennt weder `MediaStream` noch `captureStream`: Dort ersetzt ein
 * Ersatzobjekt den Stream, und für ein Video mit diesem Objekt liefern `srcObject`, `play`,
 * `readyState`, `videoWidth`/`videoHeight` und `drawImage` die Canvas. Das Lesen (getImageData,
 * Lesemodul, CSP) ist dasselbe wie mit einem echten Stream.
 */
export async function fakeCamera(page: Page, camera: FakeCamera): Promise<void> {
    await page.addInitScript((camera: FakeCamera) => {
        const counts = { opened: 0, stopped: 0 };
        Object.assign(window, { ffCamera: counts });
        if (camera.kind === 'unsupported') {
            Object.defineProperty(navigator, 'mediaDevices', {
                configurable: true,
                value: undefined,
            });
            return;
        }

        const nativeStream = typeof HTMLCanvasElement.prototype.captureStream === 'function';
        /** Ersatz-Stream → Canvas, Video → Canvas (nur ohne `captureStream`). */
        const streams = new WeakMap<object, HTMLCanvasElement>();
        const sources = new WeakMap<HTMLMediaElement, HTMLCanvasElement>();
        if (!nativeStream) {
            const media = HTMLMediaElement.prototype;
            const srcObject = Object.getOwnPropertyDescriptor(media, 'srcObject');
            Object.defineProperty(media, 'srcObject', {
                configurable: true,
                get(this: HTMLMediaElement): unknown {
                    return sources.has(this) ? {} : srcObject?.get?.call(this);
                },
                set(this: HTMLMediaElement, value: unknown) {
                    const canvas =
                        typeof value === 'object' && value !== null
                            ? streams.get(value)
                            : undefined;
                    if (canvas !== undefined) {
                        sources.set(this, canvas);
                        return;
                    }
                    sources.delete(this);
                    srcObject?.set?.call(this, value);
                },
            });
            const play = Object.getOwnPropertyDescriptor(media, 'play')?.value as (
                this: HTMLMediaElement,
            ) => Promise<void>;
            media.play = function (this: HTMLMediaElement): Promise<void> {
                return sources.has(this) ? Promise.resolve() : play.call(this);
            };
            const size = (name: 'readyState' | 'videoWidth' | 'videoHeight'): void => {
                const prototype = name === 'readyState' ? media : HTMLVideoElement.prototype;
                const original = Object.getOwnPropertyDescriptor(prototype, name);
                Object.defineProperty(prototype, name, {
                    configurable: true,
                    get(this: HTMLMediaElement): unknown {
                        const canvas = sources.get(this);
                        if (canvas === undefined) {
                            return original?.get?.call(this);
                        }
                        if (name === 'readyState') {
                            return HTMLMediaElement.HAVE_ENOUGH_DATA;
                        }
                        return name === 'videoWidth' ? canvas.width : canvas.height;
                    },
                });
            };
            size('readyState');
            size('videoWidth');
            size('videoHeight');
            const drawImage = Object.getOwnPropertyDescriptor(
                CanvasRenderingContext2D.prototype,
                'drawImage',
            )?.value as (this: CanvasRenderingContext2D, ...args: unknown[]) => void;
            CanvasRenderingContext2D.prototype.drawImage = function (
                this: CanvasRenderingContext2D,
                image: unknown,
                ...rest: unknown[]
            ): void {
                const canvas = image instanceof HTMLMediaElement ? sources.get(image) : undefined;
                drawImage.call(this, canvas ?? image, ...rest);
            };
        }

        const getUserMedia = (): Promise<MediaStream> => {
            if (camera.kind === 'error') {
                return Promise.reject(new DOMException('Test', camera.name));
            }
            const symbol =
                camera.kind === 'code' ? camera.symbol : { width: 30, height: 30, modules: [] };
            const scale = 4;
            const quiet = 10;
            const canvas = document.createElement('canvas');
            canvas.width = (symbol.width + 2 * quiet) * scale;
            canvas.height = (symbol.height + 2 * quiet) * scale;
            const context = canvas.getContext('2d');
            let frame = 0;
            const draw = (): void => {
                if (context === null) {
                    return;
                }
                context.fillStyle = '#ffffff';
                context.fillRect(0, 0, canvas.width, canvas.height);
                context.fillStyle = '#000000';
                symbol.modules.forEach((value, index) => {
                    if (value === 0) {
                        const x = (index % symbol.width) + quiet;
                        const y = Math.floor(index / symbol.width) + quiet;
                        context.fillRect(x * scale, y * scale, scale, scale);
                    }
                });
                // Ein Bildpunkt in der Ecke wechselt, damit jedes Bild neu ist.
                frame += 1;
                context.fillStyle = frame % 2 === 0 ? '#ffffff' : '#fefefe';
                context.fillRect(0, 0, 1, 1);
            };
            draw();
            const timer = setInterval(draw, 100);
            let stream: MediaStream;
            if (nativeStream) {
                stream = canvas.captureStream(15);
            } else {
                const track = { stop: (): void => undefined };
                stream = { getTracks: () => [track] } as unknown as MediaStream;
                streams.set(stream, canvas);
            }
            counts.opened += 1;
            for (const track of stream.getTracks()) {
                const stop = track.stop.bind(track);
                track.stop = () => {
                    counts.stopped += 1;
                    clearInterval(timer);
                    stop();
                };
            }
            return Promise.resolve(stream);
        };
        Object.defineProperty(navigator, 'mediaDevices', {
            configurable: true,
            value: { getUserMedia },
        });
    }, camera);
}

/** Zähler der simulierten Kamera. */
export function cameraCounts(page: Page): Promise<CameraCounts> {
    return page.evaluate(() => (window as unknown as { ffCamera: CameraCounts }).ffCamera);
}
