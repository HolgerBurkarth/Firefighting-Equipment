<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tenant;

/**
 * Zu einem Kürzel gibt es keine Stadt.
 */
final class TenantNotFoundException extends TenantException
{
    public static function for(TenantSlug $slug): self
    {
        return new self(sprintf('Keine Stadt mit dem Kürzel „%s“ gefunden.', $slug->value));
    }
}
