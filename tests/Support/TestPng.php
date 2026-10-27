<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Support;

/**
 * Test-PNGs, zur Laufzeit mit GD erzeugt (nichts committet), und Werkzeuge für Chunks.
 */
final class TestPng
{
    /**
     * Transparentes Bild mit einem roten Quadrat in der Mitte; Ecken voll transparent.
     *
     * @param bool $palette true = Palettenbild mit tRNS statt Truecolor mit Alpha
     * @param int  $red     Rotanteil des Quadrats (für verschiedene Bilder)
     */
    public static function create(int $width = 512, ?int $height = null, bool $palette = false, int $red = 200): string
    {
        $height ??= $width;
        if ($palette) {
            $image = imagecreate($width, $height) ?: throw new \RuntimeException('GD');
            imagecolortransparent($image, imagecolorallocate($image, 1, 2, 3) ?: 0);
            $color = imagecolorallocate($image, $red, 0, 0) ?: 0;
        } else {
            $image = imagecreatetruecolor($width, $height) ?: throw new \RuntimeException('GD');
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127) ?: 0);
            $color = imagecolorallocatealpha($image, $red, 0, 0, 0) ?: 0;
        }
        imagefilledrectangle($image, intdiv($width, 4), intdiv($height, 4), intdiv($width * 3, 4), intdiv($height * 3, 4), $color);

        $stream = fopen('php://memory', 'w+b') ?: throw new \RuntimeException('Stream');
        imagepng($image, $stream);
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    /** Ein Chunk mit Länge und korrekter CRC. */
    public static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }

    /** Fügt einen Chunk direkt nach IHDR ein. */
    public static function withChunk(string $png, string $type, string $data): string
    {
        $afterHeader = 8 + 12 + 13;

        return substr($png, 0, $afterHeader) . self::chunk($type, $data) . substr($png, $afterHeader);
    }

    /**
     * @return list<string> Typen aller Chunks in der Reihenfolge der Datei
     */
    public static function chunkTypes(string $png): array
    {
        $types = [];
        $position = 8;
        while ($position + 8 <= strlen($png)) {
            $length = unpack('N', $png, $position)[1];
            $types[] = substr($png, $position + 4, 4);
            $position += 12 + $length;
        }

        return $types;
    }

    /**
     * Alpha (0 = deckend, 127 = transparent) und Farbe eines Pixels.
     *
     * @return array{int, int} [alpha, 0xRRGGBB]
     */
    public static function pixel(string $png, int $x, int $y): array
    {
        $image = imagecreatefromstring($png) ?: throw new \RuntimeException('GD');
        $color = imagecolorsforindex($image, imagecolorat($image, $x, $y));

        return [$color['alpha'], ($color['red'] << 16) | ($color['green'] << 8) | $color['blue']];
    }
}
