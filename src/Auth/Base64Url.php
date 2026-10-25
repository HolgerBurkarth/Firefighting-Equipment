<?php

declare(strict_types=1);

namespace FirefightingEquipment\Auth;

/**
 * base64url ohne Auffüllung für Tokens (Cookie, Einmal-Links). Dekodiert wird nur die kanonische
 * Form, damit es je Token genau eine Schreibweise gibt.
 */
final class Base64Url
{
    public static function encode(#[\SensitiveParameter] string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * @return string|null die Bytes; null, wenn $value nicht die kanonische Form von genau $bytes Byte ist
     */
    public static function decode(#[\SensitiveParameter] string $value, int $bytes): ?string
    {
        if (strlen($value) !== intdiv($bytes * 4 + 2, 3) || preg_match('/^[A-Za-z0-9_-]*$/D', $value) !== 1) {
            return null;
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false || strlen($decoded) !== $bytes) {
            return null;
        }

        // Die letzten Bits des letzten Zeichens sind sonst frei.
        return self::encode($decoded) === $value ? $decoded : null;
    }
}
