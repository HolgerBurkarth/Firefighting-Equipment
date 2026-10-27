<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http;

/**
 * Die einzigen erlaubten Ausnahmen von "Cache-Control: no-store", nur für CacheableResponse (Logo,
 * Standard-Symbol, Web-App-Manifest; alle öffentlich und ohne personenbezogene Daten).
 */
enum CachePolicy
{
    /** Adresse mit passender Versionskennung (?v=…): Inhalt ändert sich unter dieser Adresse nie. */
    case Immutable;

    /** Ohne oder mit veralteter Versionskennung bzw. Manifest: vor jeder Verwendung per ETag prüfen. */
    case Revalidate;

    public function header(): string
    {
        return match ($this) {
            self::Immutable => 'public, max-age=31536000, immutable',
            self::Revalidate => 'no-cache',
        };
    }
}
