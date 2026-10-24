<?php

declare(strict_types=1);

namespace FirefightingEquipment\Migration;

/**
 * Prüfsumme einer Migrationsdatei.
 */
final class Checksum
{
    /**
     * Wandelt CRLF in LF um, damit Windows- und Server-Checkout dieselbe Prüfsumme ergeben.
     */
    public static function normalize(string $content): string
    {
        return str_replace("\r\n", "\n", $content);
    }

    /**
     * @return string SHA-256 als Hex-String (64 Zeichen)
     */
    public static function of(string $content): string
    {
        return hash('sha256', self::normalize($content));
    }
}
