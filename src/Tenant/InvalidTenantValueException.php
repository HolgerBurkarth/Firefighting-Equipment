<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tenant;

/**
 * Kürzel oder Name einer Stadt verletzt die Regeln.
 */
final class InvalidTenantValueException extends TenantException
{
    /**
     * @param string|null $suggestion gültige Schreibweise, wenn sie sich eindeutig ergibt (bisher nur
     *                                ein Kürzel in Kleinbuchstaben)
     */
    public function __construct(string $message, public readonly ?string $suggestion = null)
    {
        parent::__construct($message);
    }
}
