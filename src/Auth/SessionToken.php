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
        $bytes = Base64Url::decode($value, self::BYTES);

        return $bytes === null ? null : new self($bytes);
    }

    public function cookieValue(): string
    {
        return Base64Url::encode($this->bytes);
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
