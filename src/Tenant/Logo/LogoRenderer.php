<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tenant\Logo;

/**
 * Erzeugt aus einem hochgeladenen Logo neue PNG-Dateien in allen Größen (LogoSize). Gespeichert
 * wird nur das Ergebnis: GD schreibt nur die Bilddaten, Metadaten (Text, EXIF, Farbprofile) und
 * versteckte Inhalte der hochgeladenen Datei fallen dabei weg. Transparenz bleibt erhalten, außer bei
 * der Größe für Apple (auf Weiß gelegt).
 */
final class LogoRenderer
{
    /**
     * @throws InvalidLogoException
     */
    public static function fromUpload(string $bytes): LogoImages
    {
        [$width, $height] = PngInspector::inspect($bytes);
        $source = self::decode($bytes);
        if (imagesx($source) !== $width || imagesy($source) !== $height) {
            throw new InvalidLogoException('Die PNG-Datei kann nicht gelesen werden.');
        }

        $pngs = [];
        foreach (LogoSize::cases() as $size) {
            $pngs[$size->value] = self::encode(self::resize($source, $size));
        }

        return new LogoImages($pngs);
    }

    /**
     * @throws InvalidLogoException
     */
    private static function decode(string $bytes): \GdImage
    {
        // libpng meldet auch bei lesbaren Dateien Warnungen (z. B. zu Farbprofilen); sie dürfen weder
        // ins Protokoll noch die Anfrage abbrechen. Entscheidend ist nur das Ergebnis.
        set_error_handler(static fn (): bool => true);
        try {
            $image = imagecreatefromstring($bytes);
        } finally {
            restore_error_handler();
        }
        if ($image === false) {
            throw new InvalidLogoException('Die PNG-Datei kann nicht gelesen werden.');
        }
        if (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }
        imagealphablending($image, false);

        return $image;
    }

    private static function resize(\GdImage $source, LogoSize $size): \GdImage
    {
        $edge = $size->value;
        $target = imagecreatetruecolor($edge, $edge) ?: throw new \RuntimeException('GD: Bild nicht angelegt.');
        if ($size->opaque()) {
            imagealphablending($target, true);
            imagefill($target, 0, 0, self::color($target, 255, 255, 255, 0));
            imagesavealpha($target, false);
        } else {
            imagealphablending($target, false);
            imagefill($target, 0, 0, self::color($target, 0, 0, 0, 127));
            imagesavealpha($target, true);
        }
        imagecopyresampled($target, $source, 0, 0, 0, 0, $edge, $edge, imagesx($source), imagesy($source));

        return $target;
    }

    private static function color(\GdImage $image, int $red, int $green, int $blue, int $alpha): int
    {
        $color = imagecolorallocatealpha($image, $red, $green, $blue, $alpha);

        return $color === false ? throw new \RuntimeException('GD: Farbe nicht angelegt.') : $color;
    }

    private static function encode(\GdImage $image): string
    {
        $stream = fopen('php://memory', 'w+b') ?: throw new \RuntimeException('Kein Speicher-Stream.');
        try {
            if (!imagepng($image, $stream, 9)) {
                throw new \RuntimeException('GD: PNG nicht erzeugt.');
            }
            rewind($stream);

            return (string) stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }
}
