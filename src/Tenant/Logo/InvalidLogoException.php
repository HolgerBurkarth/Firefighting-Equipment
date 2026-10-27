<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tenant\Logo;

use FirefightingEquipment\Tenant\TenantException;

/**
 * Hochgeladenes Logo verletzt die Regeln (keine PNG, beschädigt, Größe). Die Meldung ist für
 * Benutzer gedacht.
 */
final class InvalidLogoException extends TenantException
{
    /**
     * @param array<string, int> $details maschinenlesbare Angaben (Feld "details" der API), z. B.
     *                                    {"width": 600, "height": 400}
     */
    public function __construct(string $message, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}
