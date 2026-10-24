<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tenant;

/**
 * Eine Stadt (Mandant). Interner Schlüssel ist die ID, nach außen sichtbar ist das Kürzel.
 */
final readonly class Tenant
{
    public function __construct(
        public int $id,
        public TenantSlug $slug,
        public TenantName $name,
        /** false = gesperrt; die Daten bleiben erhalten. */
        public bool $active,
        /** UTC */
        public \DateTimeImmutable $createdAt,
        /** UTC */
        public \DateTimeImmutable $updatedAt,
    ) {
    }
}
