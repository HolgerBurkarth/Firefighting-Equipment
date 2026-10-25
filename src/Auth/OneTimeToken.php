<?php

declare(strict_types=1);

namespace FirefightingEquipment\Auth;

/**
 * Token eines Einmal-Links (32 Zufallsbytes). Im Link steht es als base64url ohne Auffüllung
 * (43 Zeichen, hinter #token=), in fe_user_tokens nur sein SHA-256.
 */
final readonly class OneTimeToken
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
    public static function fromString(#[\SensitiveParameter] string $value): ?self
    {
        $bytes = Base64Url::decode($value, self::BYTES);

        return $bytes === null ? null : new self($bytes);
    }

    public function value(): string
    {
        return Base64Url::encode($this->bytes);
    }

    /** SHA-256 (32 Byte, binär) für fe_user_tokens.token_hash. */
    public function hash(): string
    {
        return hash('sha256', $this->bytes, true);
    }

    public function __debugInfo(): array
    {
        return ['bytes' => '***'];
    }
}
