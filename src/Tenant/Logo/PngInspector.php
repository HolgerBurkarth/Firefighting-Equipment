<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tenant\Logo;

/**
 * Prüft ein hochgeladenes Logo, bevor GD es dekodiert: Größe der Datei, PNG-Signatur, alle Chunks
 * (Länge, CRC), IHDR als erster Chunk, IEND am Ende und nichts danach (keine angehängten Daten),
 * keine Animation (acTL), quadratisch und Kantenlänge MIN_EDGE bis MAX_EDGE. Die Maße kommen aus
 * IHDR, also vor dem Dekodieren (Schutz vor Dekompressionsbomben).
 */
final class PngInspector
{
    /** 500 KB */
    public const int MAX_BYTES = 512_000;

    public const int MIN_EDGE = 512;
    public const int MAX_EDGE = 512;

    public const string SIGNATURE = "\x89PNG\r\n\x1A\n";

    /** Farbtyp => erlaubte Bittiefen (PNG-Spezifikation, Tabelle 11.1) */
    private const array BIT_DEPTHS = [0 => [1, 2, 4, 8, 16], 2 => [8, 16], 3 => [1, 2, 4, 8], 4 => [8, 16], 6 => [8, 16]];

    private const string DAMAGED = 'Die PNG-Datei ist beschädigt.';
    private const string INCOMPLETE = 'Die PNG-Datei ist unvollständig.';

    /**
     * @return array{int, int} Breite und Höhe in px
     *
     * @throws InvalidLogoException
     */
    public static function inspect(string $bytes): array
    {
        $length = strlen($bytes);
        if ($length === 0) {
            throw new InvalidLogoException('Die Datei ist leer.');
        }
        if ($length > self::MAX_BYTES) {
            throw new InvalidLogoException(
                sprintf('Das Logo ist zu groß (%s KB); erlaubt sind höchstens %d KB.', number_format($length / 1024, 0, ',', '.'), intdiv(self::MAX_BYTES, 1024)),
                ['bytes' => $length, 'max_bytes' => self::MAX_BYTES],
            );
        }
        if (!str_starts_with($bytes, self::SIGNATURE)) {
            throw new InvalidLogoException('Die Datei ist keine PNG-Datei. Bitte das Logo als PNG hochladen.');
        }

        [$width, $height] = self::walkChunks($bytes);

        if ($width !== $height) {
            throw new InvalidLogoException(
                sprintf('Das Logo muss quadratisch sein (hochgeladen: %d × %d px).', $width, $height),
                ['width' => $width, 'height' => $height],
            );
        }
        if ($width < self::MIN_EDGE || $width > self::MAX_EDGE) {
            throw new InvalidLogoException(
                sprintf('Das Logo muss %s sein (hochgeladen: %d × %d px).', self::edgeRule(), $width, $height),
                ['width' => $width, 'height' => $height, 'min_edge' => self::MIN_EDGE, 'max_edge' => self::MAX_EDGE],
            );
        }

        return [$width, $height];
    }

    private static function edgeRule(): string
    {
        return self::MIN_EDGE === self::MAX_EDGE
            ? sprintf('genau %1$d × %1$d px groß', self::MIN_EDGE)
            : sprintf('mindestens %1$d × %1$d und höchstens %2$d × %2$d px groß', self::MIN_EDGE, self::MAX_EDGE);
    }

    /**
     * @return array{int, int}
     *
     * @throws InvalidLogoException
     */
    private static function walkChunks(string $bytes): array
    {
        $length = strlen($bytes);
        $position = strlen(self::SIGNATURE);
        $size = null;
        $hasData = false;
        $complete = false;

        while ($position < $length) {
            if ($length - $position < 12) {
                throw new InvalidLogoException(self::INCOMPLETE);
            }
            $dataLength = self::uint32($bytes, $position);
            $type = substr($bytes, $position + 4, 4);
            if ($dataLength > 0x7FFFFFFF || $length - $position - 12 < $dataLength) {
                throw new InvalidLogoException(self::INCOMPLETE);
            }
            if (preg_match('/^[A-Za-z]{4}\z/', $type) !== 1) {
                throw new InvalidLogoException(self::DAMAGED);
            }
            $data = substr($bytes, $position + 8, $dataLength);
            if (crc32($type . $data) !== self::uint32($bytes, $position + 8 + $dataLength)) {
                throw new InvalidLogoException(self::DAMAGED);
            }

            if ($size === null) {
                $size = $type === 'IHDR' ? self::header($data) : throw new InvalidLogoException(self::DAMAGED);
            } elseif ($type === 'IHDR') {
                throw new InvalidLogoException(self::DAMAGED);
            }
            if ($type === 'acTL') {
                throw new InvalidLogoException('Animierte PNG-Dateien werden nicht unterstützt.');
            }
            $hasData = $hasData || $type === 'IDAT';
            $position += 12 + $dataLength;

            if ($type === 'IEND') {
                $complete = true;
                break;
            }
        }

        if (!$complete || $size === null) {
            throw new InvalidLogoException(self::INCOMPLETE);
        }
        if ($position !== $length) {
            throw new InvalidLogoException('Nach dem Ende der PNG-Datei folgen weitere Daten. Bitte die Datei mit einem Bildprogramm neu speichern.');
        }
        if (!$hasData) {
            throw new InvalidLogoException(self::DAMAGED);
        }

        return $size;
    }

    /**
     * @return array{int, int}
     *
     * @throws InvalidLogoException
     */
    private static function header(string $data): array
    {
        if (strlen($data) !== 13) {
            throw new InvalidLogoException(self::DAMAGED);
        }
        $width = self::uint32($data, 0);
        $height = self::uint32($data, 4);
        [$bitDepth, $colorType, $compression, $filter, $interlace] = array_map(ord(...), str_split(substr($data, 8, 5)));
        if (
            $width < 1 || $width > 0x7FFFFFFF || $height < 1 || $height > 0x7FFFFFFF
            || !in_array($bitDepth, self::BIT_DEPTHS[$colorType] ?? [], true)
            || $compression !== 0 || $filter !== 0 || !in_array($interlace, [0, 1], true)
        ) {
            throw new InvalidLogoException(self::DAMAGED);
        }

        return [$width, $height];
    }

    private static function uint32(string $bytes, int $offset): int
    {
        $value = unpack('N', $bytes, $offset);

        return is_array($value) && is_int($value[1]) ? $value[1] : throw new \LogicException('unpack fehlgeschlagen');
    }
}
