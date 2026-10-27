<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tenant;

/**
 * Akzentfarbe hat ein falsches Format oder zu wenig Kontrast zu weißer Schrift.
 */
final class InvalidAccentColorException extends TenantException
{
    /**
     * @param float|null $contrast erreichter Kontrast (auf zwei Stellen abgerundet); null bei falschem Format
     */
    public function __construct(string $message, public readonly ?float $contrast = null)
    {
        parent::__construct($message);
    }
}
