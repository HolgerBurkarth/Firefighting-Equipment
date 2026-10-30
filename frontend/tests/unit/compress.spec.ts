// @vitest-environment node
import { mkdirSync, mkdtempSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { brotliDecompressSync, gunzipSync } from 'node:zlib';
import { afterEach, describe, expect, it } from 'vitest';
import { compressDirectory, compressedVariants } from '../../build/compress';

const SCRIPT = Buffer.from('export const text = "Feuerwehr";\n'.repeat(50));

describe('compressedVariants', () => {
    it('Brotli und gzip, beide verlustfrei', () => {
        const variants = compressedVariants('app.js', SCRIPT);
        expect(variants.map((variant) => variant.suffix)).toEqual(['.br', '.gz']);
        const [br, gz] = variants;
        expect(brotliDecompressSync(br?.data ?? Buffer.alloc(0))).toEqual(SCRIPT);
        expect(gunzipSync(gz?.data ?? Buffer.alloc(0))).toEqual(SCRIPT);
    });

    it('nur Fassungen, die kleiner sind', () => {
        expect(compressedVariants('mini.css', Buffer.from('a{}'))).toEqual([]);
    });

    it('lehnt Dateien mit CR ab (Git schreibt LF)', () => {
        expect(() =>
            compressedVariants('crlf.js', Buffer.from('a();\r\nb();\r\n'.repeat(20))),
        ).toThrow('crlf.js');
    });

    it('WebAssembly ist binär: CR erlaubt, verlustfrei', () => {
        const wasm = Buffer.alloc(4096, Buffer.from([0x00, 0x61, 0x73, 0x6d, 0x0d, 0x0a, 0x01]));
        const [br, gz] = compressedVariants('zxing_reader-abc.wasm', wasm);
        expect(brotliDecompressSync(br?.data ?? Buffer.alloc(0))).toEqual(wasm);
        expect(gunzipSync(gz?.data ?? Buffer.alloc(0))).toEqual(wasm);
    });
});

describe('compressDirectory', () => {
    let directory = '';

    afterEach(() => {
        rmSync(directory, { recursive: true, force: true });
    });

    it('legt .br und .gz neben Skripte und Stile, auch in assets/', () => {
        directory = mkdtempSync(join(tmpdir(), 'ff-compress-'));
        mkdirSync(join(directory, 'assets'));
        writeFileSync(join(directory, 'sw.js'), SCRIPT);
        writeFileSync(join(directory, 'assets', 'index-abc.css'), SCRIPT);
        writeFileSync(join(directory, 'assets', 'font-abc.woff2'), SCRIPT);
        writeFileSync(join(directory, 'assets', 'zxing_reader-abc.wasm'), SCRIPT);
        writeFileSync(join(directory, 'index.html'), SCRIPT);
        writeFileSync(join(directory, 'build.json'), SCRIPT);

        compressDirectory(directory);

        expect(readdirSync(directory).sort()).toEqual([
            'assets',
            'build.json',
            'index.html',
            'sw.js',
            'sw.js.br',
            'sw.js.gz',
        ]);
        expect(readdirSync(join(directory, 'assets')).sort()).toEqual([
            'font-abc.woff2',
            'index-abc.css',
            'index-abc.css.br',
            'index-abc.css.gz',
            'zxing_reader-abc.wasm',
            'zxing_reader-abc.wasm.br',
            'zxing_reader-abc.wasm.gz',
        ]);
        expect(gunzipSync(readFileSync(join(directory, 'assets', 'index-abc.css.gz')))).toEqual(
            SCRIPT,
        );
    });
});
