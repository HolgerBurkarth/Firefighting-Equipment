<?php

declare(strict_types=1);

namespace FirefightingEquipment\OAuth;

use FirefightingEquipment\Auth\Base64Url;

/**
 * Geheimer Wert des OAuth-Servers (Zugriffstoken, Refresh-Token, Code der Anmeldung): Präfix der Art
 * und 32 Zufallsbytes als base64url (43 Zeichen), z. B. "ffa_…". Das Präfix hilft Scannern für
 * Geheimnisse; gespeichert wird nur der SHA-256 der Bytes. Der Klartext steht nur in der Antwort an
 * Claude bzw. im Rücksprung, nie in Protokollen, Mails oder Fehlermeldungen.
 */
final readonly class OAuthSecret
{
    public const int BYTES = 32;

    private function __construct(
        public OAuthSecretKind $kind,
        #[\SensitiveParameter] private string $bytes,
    ) {
    }

    public static function generate(OAuthSecretKind $kind): self
    {
        return new self($kind, random_bytes(self::BYTES));
    }

    /**
     * @return self|null null, wenn der Wert nicht genau ein Geheimnis dieser Art in kanonischer Form ist
     */
    public static function parse(OAuthSecretKind $kind, #[\SensitiveParameter] string $value): ?self
    {
        if (!str_starts_with($value, $kind->value)) {
            return null;
        }
        $bytes = Base64Url::decode(substr($value, strlen($kind->value)), self::BYTES);

        return $bytes === null ? null : new self($kind, $bytes);
    }

    public function value(): string
    {
        return $this->kind->value . Base64Url::encode($this->bytes);
    }

    /** SHA-256 (32 Byte, binär) für die Spalten *_hash. */
    public function hash(): string
    {
        return hash('sha256', $this->bytes, true);
    }

    public function __debugInfo(): array
    {
        return ['kind' => $this->kind, 'bytes' => '***'];
    }
}
