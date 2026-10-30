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

/** Zähler der simulierten Kamera im Fenster. */
export interface CameraCounts {
    /** Erfolgreiche Aufrufe von getUserMedia. */
    readonly opened: number;
    /** Beendete Spuren (jede höchstens einmal, wie `MediaStreamTrack.stop`). */
    readonly stopped: number;
    /** Videos, deren `srcObject` noch einen Stream der Kamera hält (0 = überall gelöst). */
    readonly attached: number;
}

/** Erwarteter Stand: `opened`-mal geöffnet, jede Spur beendet, kein Video hält den Stream. */
export function cameraReleased(opened: number): CameraCounts {
    return { opened, stopped: opened, attached: 0 };
}

/**
 * Ersetzt die Kamera vor dem Laden, in allen Browsern und unter Windows wie Linux auf DEMSELBEN Weg:
 * `getUserMedia` liefert einen Ersatz-Stream aus eigenen Klassen (`FakeStream`, `FakeTrack`), kein
 * `MediaStream`. Gründe: Playwright-WebKit unter Windows kennt weder `MediaStream` noch
 * `captureStream`, und an echten Spuren gehen eigene Eigenschaften (ein Zähler in `stop`) verloren:
 * WebKit räumt das JS-Objekt einer Spur weg, solange nur der Stream gehalten wird, und `getTracks()`
 * liefert danach ein neues Objekt (in CI unter Linux zählte die Attrappe so 0 statt 1).
 *
 * Für ein Video mit dem Ersatz-Stream liefern `srcObject`, `play`, `readyState`,
 * `videoWidth`/`videoHeight` und `drawImage` die Canvas mit dem Code auf Weiß (mit Ruhezone, alle
 * 100 ms neu gezeichnet). Die App durchläuft damit überall dieselben Schritte (Stream an das Video,
 * Bild über eine Canvas lesen, Spuren beenden, `srcObject` lösen); echt bleiben Lesemodul,
 * WASM-Datei, CSP und Auslieferung durch PHP.
 */
export async function fakeCamera(page: Page, camera: FakeCamera): Promise<void> {
    await page.addInitScript((camera: FakeCamera) => {
        class FakeTrack {
            readonly kind = 'video';
            readyState: 'live' | 'ended' = 'live';

            constructor(private readonly onStop: () => void) {}

            stop(): void {
                if (this.readyState === 'live') {
                    this.readyState = 'ended';
                    this.onStop();
                }
            }
        }

        class FakeStream {
            private readonly tracks: readonly FakeTrack[];

            constructor(
                readonly canvas: HTMLCanvasElement,
                onStop: () => void,
            ) {
                this.tracks = [new FakeTrack(onStop)];
            }

            getTracks(): FakeTrack[] {
                return [...this.tracks];
            }

            getVideoTracks(): FakeTrack[] {
                return this.getTracks();
            }
        }

        const counts = { opened: 0, stopped: 0 };
        /** Video → Ersatz-Stream, den es gerade zeigt. */
        const sources = new Map<HTMLMediaElement, FakeStream>();
        Object.assign(window, {
            ffCameraCounts: () => ({ ...counts, attached: sources.size }),
        });
        if (camera.kind === 'unsupported') {
            Object.defineProperty(navigator, 'mediaDevices', {
                configurable: true,
                value: undefined,
            });
            return;
        }

        const media = HTMLMediaElement.prototype;
        const srcObject = Object.getOwnPropertyDescriptor(media, 'srcObject');
        Object.defineProperty(media, 'srcObject', {
            configurable: true,
            get(this: HTMLMediaElement): unknown {
                return sources.get(this) ?? srcObject?.get?.call(this);
            },
            set(this: HTMLMediaElement, value: unknown) {
                if (value instanceof FakeStream) {
                    sources.set(this, value);
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
                    const canvas = sources.get(this)?.canvas;
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
            const canvas =
                image instanceof HTMLMediaElement ? sources.get(image)?.canvas : undefined;
            drawImage.call(this, canvas ?? image, ...rest);
        };

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
            counts.opened += 1;
            const stream = new FakeStream(canvas, () => {
                counts.stopped += 1;
                clearInterval(timer);
            });
            return Promise.resolve(stream as unknown as MediaStream);
        };
        Object.defineProperty(navigator, 'mediaDevices', {
            configurable: true,
            value: { getUserMedia },
        });
    }, camera);
}

/** Zähler der simulierten Kamera. */
export function cameraCounts(page: Page): Promise<CameraCounts> {
    return page.evaluate(() =>
        (window as unknown as { ffCameraCounts: () => CameraCounts }).ffCameraCounts(),
    );
}
