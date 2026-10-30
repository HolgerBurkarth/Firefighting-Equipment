import { readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { extname, join, resolve } from 'node:path';
import { brotliCompressSync, constants, gzipSync } from 'node:zlib';
import type { Plugin } from 'vite';

/**
 * Endungen, die vorkomprimiert werden (WebAssembly: Lesemodul des Kamera-Scanners, rund 900 KiB).
 * Schriften (woff2/woff) sind schon komprimiert, index.html ändert PHP beim Ausliefern
 * (Basispfad), build.json ist zu klein.
 */
export const COMPRESSIBLE_EXTENSIONS: readonly string[] = ['.js', '.css', '.wasm'];

/** Textdateien: Git schreibt sie mit LF (.gitattributes); .wasm ist dort binär. */
const TEXT_EXTENSIONS: readonly string[] = ['.js', '.css'];

/** Fassungen wie ContentEncoding::fileSuffix() in PHP (per Test gleich), Brotli vor gzip. */
export const VARIANTS = [
    {
        suffix: '.br',
        compress: (source: Buffer, text: boolean): Buffer =>
            brotliCompressSync(source, {
                params: {
                    [constants.BROTLI_PARAM_MODE]: text
                        ? constants.BROTLI_MODE_TEXT
                        : constants.BROTLI_MODE_GENERIC,
                    [constants.BROTLI_PARAM_QUALITY]: constants.BROTLI_MAX_QUALITY,
                    [constants.BROTLI_PARAM_SIZE_HINT]: source.length,
                },
            }),
    },
    {
        suffix: '.gz',
        compress: (source: Buffer): Buffer =>
            gzipSync(source, { level: constants.Z_BEST_COMPRESSION }),
    },
] as const;

/**
 * Komprimierte Fassungen einer Datei; nur die, die kleiner als das Original sind. Textdateien mit
 * CR werden abgelehnt: Git schreibt .js und .css mit LF (.gitattributes, Branch deploy), danach
 * passte die Fassung nicht mehr zur Datei.
 */
export function compressedVariants(
    name: string,
    source: Buffer,
): { suffix: string; data: Buffer }[] {
    const text = TEXT_EXTENSIONS.includes(extname(name));
    if (text && source.includes(0x0d)) {
        throw new Error(
            `${name}: enthält CR; die komprimierten Fassungen passten nach Git nicht mehr`,
        );
    }
    return VARIANTS.map((variant) => ({
        suffix: variant.suffix,
        data: variant.compress(source, text),
    })).filter((variant) => variant.data.length < source.length);
}

/** Legt die Fassungen aller passenden Dateien im Ordner (mit Unterordnern) daneben. */
export function compressDirectory(directory: string): void {
    for (const entry of readdirSync(directory, { recursive: true, withFileTypes: true })) {
        if (!entry.isFile() || !COMPRESSIBLE_EXTENSIONS.includes(extname(entry.name))) {
            continue;
        }
        const file = join(entry.parentPath, entry.name);
        for (const variant of compressedVariants(entry.name, readFileSync(file))) {
            writeFileSync(file + variant.suffix, variant.data);
        }
    }
}

/**
 * STRATO komprimiert die Ausgabe nicht; daher legt der Build .br und .gz neben jede Datei, und PHP
 * sendet die passende Fassung (FrontendAssets). Läuft zuletzt (closeBundle, order post), nach
 * vite-plugin-pwa, damit auch sw.js und workbox-….js komprimiert werden.
 */
export function compressBuild(): Plugin {
    let outDir = '';
    return {
        name: 'ff-compress',
        apply: 'build',
        configResolved(config) {
            outDir = resolve(config.root, config.build.outDir);
        },
        closeBundle: {
            order: 'post',
            sequential: true,
            handler() {
                compressDirectory(outDir);
            },
        },
    };
}
