<?php

declare(strict_types=1);

namespace FirefightingEquipment\OAuth;

/**
 * Fehler einer Anfrage an „Claude verbinden“: mit Rücksprung (Client und Adresse sind geprüft) oder
 * ohne (dann zeigt die Seite nur die Meldung).
 */
final class AuthorizationRequestException extends \RuntimeException
{
    private function __construct(string $message, public readonly ?string $redirectUrl)
    {
        parent::__construct($message);
    }

    public static function withoutRedirect(string $message): self
    {
        return new self($message, null);
    }

    public static function withRedirect(string $message, string $redirectUrl): self
    {
        return new self($message, $redirectUrl);
    }
}
