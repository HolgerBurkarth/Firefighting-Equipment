<?php

declare(strict_types=1);

namespace FirefightingEquipment\User\Password;

/**
 * Argon2id, ohne Argumente mit den Standardparametern von PHP. Ändern sich diese, meldet
 * needsRehash() alte Hashes, damit sie bei der nächsten Anmeldung neu berechnet werden.
 *
 * Andere Parameter gibt es nur per Konstruktor im Code (Tests verwenden minimale Werte, damit sie
 * schnell laufen). Die Anwendung und die Kommandozeile erzeugen den Hasher immer ohne Argumente;
 * die Konfigurationsdatei kennt keine Hash-Parameter.
 */
final class PasswordHasher
{
    /**
     * Argon2id-Hash eines zufälligen, nirgends gespeicherten Werts mit den Standardparametern von PHP
     * (m=65536, t=4, p=1). Ein Test stellt sicher, dass er zu den Standardparametern passt.
     */
    public const string DEFAULT_DUMMY_HASH =
        '$argon2id$v=19$m=65536,t=4,p=1$eURMbkxoQjJEQTBra1FiUA$YLx4XIlhhaFshRwq9hnrnrH7EsOjmvCGsO8cDYaMq5U';

    /** @var array{memory_cost: int, time_cost: int, threads: int} */
    private readonly array $options;

    private ?string $dummyHash = null;

    /**
     * @param int $memoryCost Speicher in KiB, mindestens 8 je Thread
     */
    public function __construct(
        int $memoryCost = PASSWORD_ARGON2_DEFAULT_MEMORY_COST,
        int $timeCost = PASSWORD_ARGON2_DEFAULT_TIME_COST,
        int $threads = PASSWORD_ARGON2_DEFAULT_THREADS,
    ) {
        if ($timeCost < 1 || $threads < 1 || $memoryCost < 8 * $threads) {
            throw new \InvalidArgumentException('Ungültige Argon2id-Parameter.');
        }
        $this->options = ['memory_cost' => $memoryCost, 'time_cost' => $timeCost, 'threads' => $threads];
    }

    public function hash(#[\SensitiveParameter] string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID, $this->options);
    }

    public function verify(#[\SensitiveParameter] string $password, #[\SensitiveParameter] string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(#[\SensitiveParameter] string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, $this->options);
    }

    /**
     * Hash für Prüfungen ohne Konto, immer mit den Parametern dieses Hashers (gleiche Laufzeit wie
     * echte Hashes). Bei den Standardparametern DEFAULT_DUMMY_HASH, sonst einmal berechnet.
     */
    public function dummyHash(): string
    {
        return $this->dummyHash ??= $this->needsRehash(self::DEFAULT_DUMMY_HASH)
            ? $this->hash(random_bytes(32))
            : self::DEFAULT_DUMMY_HASH;
    }
}
