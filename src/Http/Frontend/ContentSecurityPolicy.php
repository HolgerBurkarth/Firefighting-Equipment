<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http\Frontend;

/**
 * Content-Security-Policy der App-Seite (index.html). Nur gleiche Herkunft, keine Inline-Skripte
 * oder -Stile, kein eval.
 *
 * Spätere Bausteine ergänzen gezielt nur die Quelle, die sie brauchen, und begründen sie hier, z. B.
 * QR-Code/Logo-Vorschau: img-src data: blob:. Nie 'unsafe-inline' oder 'unsafe-eval' (per Test
 * gesichert).
 *
 * Kamera-Scanner (zxing-wasm, frontend/src/scanner/): script-src 'wasm-unsafe-eval' erlaubt NUR das
 * Übersetzen von WebAssembly (WebAssembly.instantiateStreaming), kein eval von JavaScript. Die
 * WASM-Datei kommt aus assets/ ('self', connect-src), kein Worker, kein blob:, kein CDN. Das
 * Kamerabild (video.srcObject) braucht keine Quelle.
 *
 * Logo in den Einstellungen (frontend/src/runtime/logoImage.ts): img-src blob: erlaubt nur Bilder
 * aus Dateien, die der Admin selbst wählt bzw. die die App daraus zeichnet (URL.createObjectURL):
 * Der Browser liest das Bild (PNG, JPG, SVG …) in ein <img>, die Canvas bringt es auf 512 × 512 PNG,
 * die Vorschau zeigt dieses PNG. Kein data:, keine fremde Herkunft; SVG in <img> führt keine Skripte
 * aus.
 */
final class ContentSecurityPolicy
{
    /** @var array<string, list<string>> Direktive => Quellen, in dieser Reihenfolge ausgegeben */
    public const array DIRECTIVES = [
        'default-src' => ["'self'"],
        'script-src' => ["'self'", "'wasm-unsafe-eval'"],
        'style-src' => ["'self'"],
        'img-src' => ["'self'", 'blob:'],
        'font-src' => ["'self'"],
        'connect-src' => ["'self'"],
        'manifest-src' => ["'self'"],
        'worker-src' => ["'self'"],
        'object-src' => ["'none'"],
        'base-uri' => ["'self'"],
        'form-action' => ["'self'"],
        'frame-ancestors' => ["'none'"],
    ];

    public static function header(): string
    {
        $parts = [];
        foreach (self::DIRECTIVES as $directive => $sources) {
            $parts[] = $directive . ' ' . implode(' ', $sources);
        }

        return implode('; ', $parts);
    }
}
