<?php

declare(strict_types=1);

namespace FirefightingEquipment\Invitation;

/**
 * Klartext eines Einladungscodes: 12 Zeichen aus ALPHABET (ohne 0/O/1/I/L), angezeigt als
 * XXXX-XXXX-XXXX. Bei der Eingabe zählen Groß-/Kleinschreibung, Leerraum und Bindestriche nicht.
 * Gespeichert wird nur der SHA-256 der normalisierten Form (12 Großbuchstaben bzw. Ziffern).
 */
final readonly class InvitationCodeValue
{
    public const string ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    public const int LENGTH = 12;

    /** Längere Eingaben werden gar nicht erst normalisiert. */
    private const int MAX_INPUT_LENGTH = 64;

    private function __construct(#[\SensitiveParameter] private string $value)
    {
    }

    public static function generate(): self
    {
        $value = '';
        for ($i = 0; $i < self::LENGTH; ++$i) {
            $value .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return new self($value);
    }

    /**
     * @return self|null null, wenn die Eingabe auch normalisiert kein gültiger Code ist
     */
    public static function fromInput(#[\SensitiveParameter] string $input): ?self
    {
        if (strlen($input) > self::MAX_INPUT_LENGTH) {
            return null;
        }
        $value = preg_replace('/[\s\-]+/u', '', $input);
        if ($value === null) {
            return null;
        }
        // strtoupper ändert nur ASCII; andere Zeichen bleiben und scheitern an der Prüfung.
        $value = strtoupper($value);
        if (preg_match('/^[' . self::ALPHABET . ']{' . self::LENGTH . '}$/D', $value) !== 1) {
            return null;
        }

        return new self($value);
    }

    /** Normalisierte Form (12 Zeichen). */
    public function value(): string
    {
        return $this->value;
    }

    /** Anzeige als XXXX-XXXX-XXXX. */
    public function display(): string
    {
        return implode('-', str_split($this->value, 4));
    }

    /** SHA-256 (32 Byte, binär) für fe_invitation_codes.code_hash. */
    public function hash(): string
    {
        return hash('sha256', $this->value, true);
    }

    public function __debugInfo(): array
    {
        return ['value' => '***'];
    }
}
