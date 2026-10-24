<?php

declare(strict_types=1);

namespace FirefightingEquipment\Auth;

/**
 * Zufälliges Sitzungs-Token (32 Byte). Im Cookie steht es als base64url ohne Auffüllung
 * (43 Zeichen), in der Datenbank nur sein SHA-256.
 */
final readonly class SessionToken
{
    public const int BYTES = 32;
    private const string PATTERN = '/^[A-Za-z0-9_-]{43}$/D';

    private function __construct(#[\SensitiveParameter] private string $bytes)
    {
    }

    public static function generate(): self
    {
        return new self(random_bytes(self::BYTES));
    }

    /**
     * @return self|null null, wenn der Wert kein Token in kanonischer Form ist
     */
    public static function fromCookie(#[\SensitiveParameter] string $value): ?self
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            return null;
        }

        $bytes = base64_decode(strtr($value, '-_', '+/'), true);
        if ($bytes === false || strlen($bytes) !== self::BYTES) {
            return null;
        }

        $token = new self($bytes);

        // Nur eine Schreibweise je Token (die letzten Bits des 43. Zeichens sind sonst frei).
        return $token->cookieValue() === $value ? $token : null;
    }

    public function cookieValue(): string
    {
        return rtrim(strtr(base64_encode($this->bytes), '+/', '-_'), '=');
    }

    /** SHA-256 (32 Byte, binär) für fe_sessions.token_hash. */
    public function hash(): string
    {
        return hash('sha256', $this->bytes, true);
    }

    public function __debugInfo(): array
    {
        return ['bytes' => '***'];
    }
}
