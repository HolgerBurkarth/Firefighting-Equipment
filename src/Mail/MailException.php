<?php

declare(strict_types=1);

namespace FirefightingEquipment\Mail;

/**
 * Mail ungültig (z. B. Zeilenumbruch im Betreff) oder Versand fehlgeschlagen. Die Meldung enthält
 * nie den Text der Mail (er kann Links mit Tokens enthalten).
 */
final class MailException extends \RuntimeException
{
    public static function timeBudgetExceeded(): self
    {
        return new self('Zeitbudget für den Versand überschritten; Mail nicht gesendet.');
    }
}
