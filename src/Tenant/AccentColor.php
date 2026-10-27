<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tenant;

/**
 * Akzentfarbe einer Stadt als #rrggbb (Kleinbuchstaben). Eingabe: Leerraum am Rand wird entfernt,
 * dann "#" und 6 oder 3 Hex-Ziffern (Groß-/Kleinschreibung egal; #a33 = #aa3333). Weiße Schrift auf
 * der Farbe muss einen Kontrast von mindestens MIN_CONTRAST haben (WCAG 2.x, relative Luminanz).
 * Dieselben Formatregeln prüft die Datenbank per CHECK (Migration 0021).
 */
final readonly class AccentColor
{
    /** Standardfarbe (Feuerwehrrot), Kontrast zu Weiß 6,57:1. */
    public const string DEFAULT = '#b71c1c';

    /** WCAG 2.x, Stufe AA für normale Schrift */
    public const float MIN_CONTRAST = 4.5;

    private const string FORMAT_MESSAGE = 'Die Akzentfarbe muss im Format #RRGGBB angegeben werden (z. B. #B71C1C).';

    private function __construct(public string $value)
    {
    }

    /**
     * @throws InvalidAccentColorException
     */
    public static function fromString(string $input): self
    {
        $value = trim($input);
        if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})\z/', $value) !== 1) {
            throw new InvalidAccentColorException(self::FORMAT_MESSAGE);
        }
        $value = strtolower($value);
        if (strlen($value) === 4) {
            $value = '#' . $value[1] . $value[1] . $value[2] . $value[2] . $value[3] . $value[3];
        }

        $contrast = self::contrastOf($value);
        if ($contrast < self::MIN_CONTRAST) {
            $shown = self::floorTwoDecimals($contrast);
            throw new InvalidAccentColorException(
                sprintf(
                    'Weiße Schrift auf %s erreicht nur einen Kontrast von %s:1; nötig sind mindestens %s:1. Bitte eine dunklere Farbe wählen.',
                    strtoupper($value),
                    number_format($shown, 2, ',', ''),
                    number_format(self::MIN_CONTRAST, 1, ',', ''),
                ),
                $shown,
            );
        }

        return new self($value);
    }

    public static function default(): self
    {
        return new self(self::DEFAULT);
    }

    /**
     * Gespeicherter Wert (Format per CHECK gesichert). Der Kontrast wird nicht erneut geprüft, damit
     * eine strengere Regel später gespeicherte Farben nicht unlesbar macht.
     */
    public static function stored(string $value): self
    {
        if (preg_match('/^#[0-9a-f]{6}\z/', $value) !== 1) {
            throw new \UnexpectedValueException('Gespeicherte Akzentfarbe hat ein ungültiges Format.');
        }

        return new self($value);
    }

    /**
     * Kontrast weißer Schrift auf dieser Farbe (1 bis 21).
     */
    public function contrastWithWhite(): float
    {
        return self::contrastOf($this->value);
    }

    /**
     * Abgerundet, damit eine abgelehnte Farbe nie mit „4,50“ angezeigt wird.
     */
    public static function floorTwoDecimals(float $value): float
    {
        return floor($value * 100) / 100;
    }

    /**
     * WCAG 2.x: (L1 + 0,05) / (L2 + 0,05), Weiß hat L = 1.
     *
     * @param string $hex #rrggbb
     */
    private static function contrastOf(string $hex): float
    {
        $luminance = 0.0;
        foreach ([1 => 0.2126, 3 => 0.7152, 5 => 0.0722] as $offset => $weight) {
            $channel = hexdec(substr($hex, $offset, 2)) / 255;
            $linear = $channel <= 0.04045 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
            $luminance += $weight * $linear;
        }

        return 1.05 / ($luminance + 0.05);
    }
}
