<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http\Frontend;

/**
 * Content-Security-Policy der App-Seite (index.html). Nur gleiche Herkunft, keine Inline-Skripte
 * oder -Stile, kein eval.
 *
 * Spätere Bausteine ergänzen gezielt nur die Quelle, die sie brauchen, und begründen sie hier, z. B.
 * Kamera-Scanner: worker-src blob:, script-src 'wasm-unsafe-eval'; QR-Code/Logo-Vorschau:
 * img-src data: blob:. Nie 'unsafe-inline' oder 'unsafe-eval' (per Test gesichert).
 */
final class ContentSecurityPolicy
{
    /** @var array<string, list<string>> Direktive => Quellen, in dieser Reihenfolge ausgegeben */
    public const array DIRECTIVES = [
        'default-src' => ["'self'"],
        'script-src' => ["'self'"],
        'style-src' => ["'self'"],
        'img-src' => ["'self'"],
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
