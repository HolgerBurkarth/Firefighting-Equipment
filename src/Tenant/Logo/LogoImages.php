<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tenant\Logo;

/**
 * Die neu erzeugten PNG-Dateien eines Logos in allen Größen (LogoSize) samt Versionskennung.
 */
final readonly class LogoImages
{
    /**
     * @param array<int, string> $pngs Kantenlänge => PNG-Datei, genau die Werte von LogoSize
     */
    public function __construct(private array $pngs)
    {
        foreach (LogoSize::cases() as $size) {
            if (!isset($pngs[$size->value])) {
                throw new \InvalidArgumentException(sprintf('Größe %d fehlt.', $size->value));
            }
        }
        if (count($pngs) !== count(LogoSize::cases())) {
            throw new \InvalidArgumentException('Unbekannte Größe.');
        }
    }

    public function png(LogoSize $size): string
    {
        return $this->pngs[$size->value];
    }

    /**
     * Die ersten 16 Hex-Zeichen von SHA-256 über alle Größen (ändert sich mit jedem neuen Bild).
     */
    public function version(): string
    {
        return substr(hash('sha256', implode('', array_map(fn (LogoSize $s): string => hash('sha256', $this->png($s), true), LogoSize::cases()))), 0, 16);
    }
}
