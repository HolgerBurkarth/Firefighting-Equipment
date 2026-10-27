<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tenant;

/**
 * Erscheinungsbild einer Stadt: eigene Akzentfarbe (null = Standard) und Versionskennung des Logos
 * (null = kein Logo).
 */
final readonly class TenantAppearance
{
    public function __construct(
        public ?AccentColor $accentColor,
        public ?string $logoVersion,
    ) {
    }

    /** Die eigene Farbe oder die Standardfarbe. */
    public function effectiveAccentColor(): AccentColor
    {
        return $this->accentColor ?? AccentColor::default();
    }
}
