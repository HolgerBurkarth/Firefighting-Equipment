<?php

declare(strict_types=1);

namespace FirefightingEquipment\Auth;

/**
 * Die Bremse hat gegriffen; erst nach retryAfterSeconds wieder versuchen.
 */
final class TooManyAttemptsException extends AuthException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct('Zu viele Fehlversuche.');
    }
}
