<?php

declare(strict_types=1);

/**
 * Erzeugt das Standard-Symbol (resources/app-icon/app-icon-<größe>.png) neu: Flamme in Weiß auf der
 * Standardfarbe, über LogoRenderer in denselben Größen wie ein Logo. Platzhalter, bis das Frontend
 * ein gestaltetes Symbol hat. Nur von Hand ausführen (php scripts/generate-default-app-icon.php),
 * die Dateien werden committet. Jede Änderung ändert die Versionskennung (DefaultAppIcon::version).
 */

use FirefightingEquipment\Tenant\AccentColor;
use FirefightingEquipment\Tenant\Logo\DefaultAppIcon;
use FirefightingEquipment\Tenant\Logo\LogoRenderer;
use FirefightingEquipment\Tenant\Logo\LogoSize;
use FirefightingEquipment\Tenant\Logo\PngInspector;

require dirname(__DIR__) . '/vendor/autoload.php';

// Viermal so groß zeichnen und dann verkleinern (glatte Kanten).
$scale = 4;
$edge = PngInspector::MIN_EDGE * $scale;
$at = static fn (float $value): int => (int) round($value * $edge);

$canvas = imagecreatetruecolor($edge, $edge) ?: throw new RuntimeException('GD');
[$red, $green, $blue] = array_map(hexdec(...), str_split(substr(AccentColor::DEFAULT, 1), 2));
$background = imagecolorallocate($canvas, (int) $red, (int) $green, (int) $blue);
$white = imagecolorallocate($canvas, 255, 255, 255);
imagefill($canvas, 0, 0, $background);

// Äußere Flamme: Kreis unten, Spitze oben.
imagefilledellipse($canvas, $at(0.5), $at(0.62), $at(0.42), $at(0.42), $white);
imagefilledpolygon($canvas, [$at(0.29), $at(0.6), $at(0.71), $at(0.6), $at(0.5), $at(0.16)], $white);
// Innere Flamme in der Grundfarbe.
imagefilledellipse($canvas, $at(0.5), $at(0.69), $at(0.2), $at(0.2), $background);
imagefilledpolygon($canvas, [$at(0.4), $at(0.68), $at(0.6), $at(0.68), $at(0.5), $at(0.44)], $background);

$small = imagecreatetruecolor(PngInspector::MIN_EDGE, PngInspector::MIN_EDGE) ?: throw new RuntimeException('GD');
imagecopyresampled($small, $canvas, 0, 0, 0, 0, PngInspector::MIN_EDGE, PngInspector::MIN_EDGE, $edge, $edge);
ob_start();
imagepng($small);
$images = LogoRenderer::fromUpload((string) ob_get_clean());

if (!is_dir(DefaultAppIcon::DIRECTORY) && !mkdir(DefaultAppIcon::DIRECTORY, 0o755, true)) {
    throw new RuntimeException('Ordner nicht angelegt.');
}
foreach (LogoSize::cases() as $size) {
    file_put_contents(DefaultAppIcon::path($size), $images->png($size));
    echo DefaultAppIcon::path($size), PHP_EOL;
}
