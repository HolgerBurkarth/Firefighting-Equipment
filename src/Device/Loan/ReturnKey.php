<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Loan;

/**
 * Geheimer Schlüssel einer Rückgabestelle (Phase 2, P2.6): 32 Zufallsbytes, base64url ohne Auffüllung (43
 * Zeichen). Er steht im Aushang (QR-Code, Link mit Fragment) und nie in der Datenbank: dort liegt nur der
 * SHA-256 (32 Byte). Ein Schlüssel gibt nur die Rückgabe an einem Standort frei, sonst nichts.
 */
final readonly class ReturnKey
{
    public const int LENGTH = 43;

    private function __construct(public string $value)
    {
    }

    public static function generate(): self
    {
        return new self(rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='));
    }

    /** null bei falschem Format (gilt wie ein unbekannter Schlüssel). */
    public static function fromInput(string $value): ?self
    {
        return preg_match('/\A[A-Za-z0-9_-]{43}\z/', $value) === 1 ? new self($value) : null;
    }

    /** SHA-256 des Schlüssels, 32 Byte. */
    public function hash(): string
    {
        return hash('sha256', $this->value, true);
    }
}
