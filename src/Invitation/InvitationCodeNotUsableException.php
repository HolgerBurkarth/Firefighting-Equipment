<?php

declare(strict_types=1);

namespace FirefightingEquipment\Invitation;

/**
 * Einladungscode unbekannt (auch: Code einer anderen Stadt), abgelaufen, aufgebraucht oder
 * widerrufen. Bewusst nicht unterschieden.
 */
final class InvitationCodeNotUsableException extends InvitationException
{
    public static function create(): self
    {
        return new self('Der Einladungscode ist ungültig, abgelaufen, aufgebraucht oder widerrufen.');
    }
}
