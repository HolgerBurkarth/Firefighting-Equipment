<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http\Frontend;

use FirefightingEquipment\Http\CacheableResponse;
use FirefightingEquipment\Http\ContentEncoding;

/**
 * Liest den Build des Frontends (frontend/dist, außerhalb von public/). Ausgeliefert werden nur
 * index.html (mit eingesetztem Basispfad) und Dateien mit erlaubter Endung direkt im Build-Ordner
 * oder in assets/; Namen nur aus [A-Za-z0-9_.-], ohne Unterordner, nie ".." oder versteckte Dateien.
 * Liegt neben einer Datei eine vorkomprimierte Fassung (".br", ".gz", frontend/build/compress.ts),
 * wird sie statt der Datei gesendet, wenn Accept-Encoding sie erlaubt; direkt abrufbar ist sie nie
 * (Endung nicht erlaubt), und ohne die Datei selbst gilt sie nicht.
 * WebAssembly (Lesemodul des Kamera-Scanners) nur aus assets/, also immer mit Hash im Namen.
 */
final readonly class FrontendAssets
{
    /** Platzhalter in index.html; wie BASE_PLACEHOLDER in frontend/build/appBase.ts (per Test). */
    public const string BASE_PLACEHOLDER = '/__FF_BASE__/';

    /** Endung => Content-Type; nur, was der Build tatsächlich enthält. */
    public const array CONTENT_TYPES = [
        'js' => CacheableResponse::JAVASCRIPT,
        'css' => CacheableResponse::CSS,
        'woff2' => CacheableResponse::WOFF2,
        'woff' => CacheableResponse::WOFF,
        'wasm' => CacheableResponse::WASM,
    ];

    /** Endungen, die nur in assets/ ausgeliefert werden (nie direkt im Build-Ordner). */
    public const array ASSETS_ONLY = ['wasm'];

    /**
     * Kennung der ausgelieferten Version ({"build": "…"}), nur direkt im Build-Ordner; wie
     * BUILD_INFO_FILE in frontend/build/buildInfo.ts (per Test). Die App vergleicht sie mit der
     * eigenen (frontend/src/runtime/appUpdate.ts). Bewusst nur diese eine Datei, keine Endung .json.
     */
    public const string BUILD_INFO_FILE = 'build.json';

    private const string FILE_NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/';

    public function __construct(private string $directory)
    {
    }

    /** frontend/dist im Projektordner (dorthin legt der Branch "deploy" den Build). */
    public static function defaultDirectory(): string
    {
        return dirname(__DIR__, 3) . '/frontend/dist';
    }

    /**
     * index.html mit dem Basispfad des Bereichs.
     *
     * @param string $basePath z. B. "/firefighting/immendingen/" (nur [a-z0-9/-], prüft AppPath)
     *
     * @throws FrontendUnavailableException Build fehlt oder index.html ohne Platzhalter
     */
    public function indexHtml(string $basePath): FrontendFile
    {
        $file = $this->directory . '/index.html';
        $html = is_file($file) ? file_get_contents($file) : false;
        if ($html === false) {
            throw new FrontendUnavailableException('index.html des Frontends fehlt (Build fehlt?).');
        }
        if (substr_count($html, self::BASE_PLACEHOLDER) !== 1) {
            throw new FrontendUnavailableException('index.html enthält den Platzhalter für den Basispfad nicht genau einmal.');
        }

        return new FrontendFile(
            CacheableResponse::HTML,
            str_replace(self::BASE_PLACEHOLDER, htmlspecialchars($basePath, ENT_QUOTES | ENT_HTML5), $html),
        );
    }

    /**
     * Datei im Build-Ordner ("sw.js", "build.json") oder in assets/ ("assets/index-abc.js"), in der
     * besten vorhandenen Fassung laut Accept-Encoding (ContentEncoding::negotiate).
     *
     * @param string|null $acceptEncoding Header Accept-Encoding der Anfrage
     *
     * @return FrontendFile|null null, wenn der Name nicht erlaubt ist oder die Datei fehlt
     */
    public function file(string $relativePath, ?string $acceptEncoding = null): ?FrontendFile
    {
        $inAssets = str_starts_with($relativePath, 'assets/');
        $name = $inAssets ? substr($relativePath, strlen('assets/')) : $relativePath;
        if (preg_match(self::FILE_NAME_PATTERN, $name) !== 1 || str_contains($name, '..')) {
            return null;
        }
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $contentType = $relativePath === self::BUILD_INFO_FILE
            ? CacheableResponse::JSON
            : self::CONTENT_TYPES[$extension] ?? null;
        if ($contentType === null || (!$inAssets && in_array($extension, self::ASSETS_ONLY, true))) {
            return null;
        }

        $root = realpath($this->directory);
        if ($root === false) {
            return null;
        }
        $path = self::fileInside($root, $this->directory . '/' . $relativePath);
        if ($path === null) {
            return null;
        }

        $variants = [];
        foreach (ContentEncoding::cases() as $encoding) {
            $variant = self::fileInside($root, $path . $encoding->fileSuffix());
            if ($variant !== null) {
                $variants[$encoding->value] = $variant;
            }
        }
        $encoding = ContentEncoding::negotiate(
            $acceptEncoding,
            array_map(ContentEncoding::from(...), array_keys($variants)),
        );

        $body = file_get_contents($encoding === null ? $path : $variants[$encoding->value]);
        if ($body === false) {
            throw new \RuntimeException(sprintf('Datei des Frontends nicht lesbar: %s', $relativePath));
        }

        return new FrontendFile($contentType, $body, $encoding, $variants !== []);
    }

    /** Echter Pfad einer vorhandenen Datei innerhalb des Build-Ordners, sonst null. */
    private static function fileInside(string $root, string $path): ?string
    {
        $real = realpath($path);

        return $real !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR) && is_file($real) ? $real : null;
    }
}
