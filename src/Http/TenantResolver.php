<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http;

use FirefightingEquipment\Database\ConnectionException;
use FirefightingEquipment\Database\LazyConnection;
use FirefightingEquipment\Tenant\InvalidTenantValueException;
use FirefightingEquipment\Tenant\Tenant;
use FirefightingEquipment\Tenant\TenantRepository;
use FirefightingEquipment\Tenant\TenantSlug;

/**
 * Lädt die Stadt zum Kürzel aus der Adresse.
 */
final readonly class TenantResolver
{
    /**
     * @throws HttpException 404 not_found (ungültiges Kürzel), 404 tenant_not_found,
     *                       403 tenant_inactive, 503 database_unavailable
     */
    public function resolve(string $scope, LazyConnection $db): Tenant
    {
        try {
            $slug = TenantSlug::fromString($scope);
        } catch (InvalidTenantValueException) {
            throw HttpException::notFound();
        }

        try {
            $tenant = new TenantRepository($db->get())->findBySlug($slug);
        } catch (ConnectionException | \PDOException $e) {
            throw HttpException::databaseUnavailable($e);
        }

        if ($tenant === null) {
            throw HttpException::tenantNotFound();
        }
        if (!$tenant->active) {
            throw HttpException::tenantInactive();
        }

        return $tenant;
    }
}
