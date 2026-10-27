<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tenant\Logo;

/**
 * Standard-Symbol für Städte ohne Logo (Web-App-Manifest), ausgeliefert vom Backend aus
 * resources/app-icon/ (erzeugt mit scripts/generate-default-app-icon.php). Hängt von der noch offenen
 * Frontend-Technik ab: Später kann es eine statische Datei des Frontends werden.
 */
final class DefaultAppIcon
{
    public const string DIRECTORY = __DIR__ . '/../../../resources/app-icon';

    public static function path(LogoSize $size): string
    {
        return self::DIRECTORY . '/app-icon-' . $size->value . '.png';
    }

    public static function png(LogoSize $size): string
    {
        $png = file_get_contents(self::path($size));

        return $png === false ? throw new \RuntimeException(sprintf('Standard-Symbol %d fehlt.', $size->value)) : $png;
    }

    /**
     * Versionskennung wie bei einem Logo (LogoImages::version).
     */
    public static function version(): string
    {
        $pngs = [];
        foreach (LogoSize::cases() as $size) {
            $pngs[$size->value] = self::png($size);
        }

        return new LogoImages($pngs)->version();
    }
}
