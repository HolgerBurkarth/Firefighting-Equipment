<?php

declare(strict_types=1);

namespace FirefightingEquipment\Auth;

/**
 * Passwort richtig, aber das Konto darf sich (noch) nicht anmelden. Wird erst nach geprüftem
 * Passwort gemeldet, damit niemand ohne Passwort den Zustand eines Kontos erfährt.
 */
final class AccountUnavailableException extends AuthException
{
    public const string LOCKED = 'locked';
    public const string EMAIL_NOT_VERIFIED = 'email_not_verified';
    public const string PENDING_APPROVAL = 'pending_approval';

    /**
     * @param self::LOCKED|self::EMAIL_NOT_VERIFIED|self::PENDING_APPROVAL $reason
     */
    public function __construct(public readonly string $reason)
    {
        parent::__construct(sprintf('Konto nicht nutzbar: %s', $reason));
    }
}
