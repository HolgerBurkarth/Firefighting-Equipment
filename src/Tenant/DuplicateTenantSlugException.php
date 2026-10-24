<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tenant;

/**
 * Das Kürzel ist bereits an eine Stadt vergeben.
 */
final class DuplicateTenantSlugException extends TenantException
{
    public static function for(TenantSlug $slug): self
    {
        return new self(sprintf('Das Kürzel „%s“ ist bereits vergeben.', $slug->value));
    }
}
