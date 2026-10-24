<?php

declare(strict_types=1);

namespace FirefightingEquipment\User\Password;

/**
 * Argon2id mit den Standardparametern von PHP. Ändern sich diese, meldet needsRehash() alte Hashes,
 * damit sie bei der nächsten Anmeldung neu berechnet werden.
 */
final readonly class PasswordHasher
{
    public function hash(#[\SensitiveParameter] string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID);
    }

    public function verify(#[\SensitiveParameter] string $password, #[\SensitiveParameter] string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(#[\SensitiveParameter] string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID);
    }
}
