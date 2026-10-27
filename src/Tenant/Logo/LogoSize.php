<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tenant\Logo;

/**
 * Gespeicherte Größen eines Logos bzw. des Standard-Symbols (Kantenlänge in px): 512 und 192 für
 * das Web-App-Manifest (transparent), 180 als apple-touch-icon auf weißem Hintergrund (iOS füllt
 * transparente Flächen schwarz).
 */
enum LogoSize: int
{
    case Large = 512;
    case Medium = 192;
    case Apple = 180;

    /** Spalte in fe_tenant_logos (fester Wert, nie aus der Anfrage) */
    public function column(): string
    {
        return 'png_' . $this->value;
    }

    /** true = auf weißen Hintergrund gelegt, ohne Transparenz */
    public function opaque(): bool
    {
        return $this === self::Apple;
    }
}
