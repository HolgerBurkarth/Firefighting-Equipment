<?php

declare(strict_types=1);

namespace FirefightingEquipment\Config;

/**
 * Optionale Gruppe 'app' der Konfiguration. Standardwerte = Produktion.
 */
final readonly class AppConfig
{
    public const string DEFAULT_BASE_URL = 'https://www.prodad.de';

    public function __construct(
        /** Schema, Host, optional Port; ohne Pfad und ohne / am Ende (z. B. http://localhost:8000). */
        public string $baseUrl = self::DEFAULT_BASE_URL,
    ) {
    }
}
