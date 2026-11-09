<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Loan;

use FirefightingEquipment\Device\DeviceRuleViolationException;
use FirefightingEquipment\Device\LocationNotFoundException;
use FirefightingEquipment\Tenant\Tenant;

/**
 * Rückgabestellen (Phase 2, P2.6, fe_return_points): je Standort mit der Aufgabe „Rückgabe“ höchstens ein
 * gültiger Schlüssel. Erneuern widerruft den alten. Gespeichert wird nur der Hash.
 */
final readonly class ReturnPointRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Neuer Schlüssel für den Standort (der alte gilt nicht mehr).
     *
     * @throws LocationNotFoundException    unbekannt oder fremd
     * @throws DeviceRuleViolationException ohne die Aufgabe „Rückgabe“ oder gesperrt
     */
    public function renew(Tenant $tenant, int $locationId, int $userId): ReturnKey
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT name, task, active FROM fe_locations WHERE id = ? AND tenant_id = ? FOR UPDATE');
            $stmt->execute([$locationId, $tenant->id]);
            $row = $stmt->fetch();
            if (!is_array($row)) {
                throw LocationNotFoundException::forId($locationId);
            }
            if ($row['task'] !== 'returns' || (int) $row['active'] !== 1) {
                throw DeviceRuleViolationException::because(sprintf('„%s“ ist keine Rückgabestelle.', (string) $row['name']));
            }
            $this->pdo->prepare('UPDATE fe_return_points SET revoked_at = UTC_TIMESTAMP() WHERE tenant_id = ? AND location_id = ? AND revoked_at IS NULL')
                ->execute([$tenant->id, $locationId]);
            $key = ReturnKey::generate();
            $this->pdo->prepare('INSERT INTO fe_return_points (tenant_id, location_id, secret_hash, created_by) VALUES (?, ?, ?, ?)')
                ->execute([$tenant->id, $locationId, $key->hash(), $userId]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $key;
    }

    /**
     * Widerruft den gültigen Schlüssel (Aushang ungültig). Wiederholbar.
     *
     * @throws LocationNotFoundException unbekannt oder fremd
     */
    public function revoke(Tenant $tenant, int $locationId): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM fe_locations WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$locationId, $tenant->id]);
        if ($stmt->fetchColumn() === false) {
            throw LocationNotFoundException::forId($locationId);
        }
        $this->pdo->prepare('UPDATE fe_return_points SET revoked_at = UTC_TIMESTAMP() WHERE tenant_id = ? AND location_id = ? AND revoked_at IS NULL')
            ->execute([$tenant->id, $locationId]);
    }

    /**
     * Der aktive Standort mit der Aufgabe „Rückgabe“ zu diesem Schlüssel.
     *
     * @return array{id: int, name: string}|null null: unbekannt, widerrufen, Standort gesperrt oder ohne die Aufgabe
     */
    public function locationFor(Tenant $tenant, ReturnKey $key): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT l.id, l.name FROM fe_return_points r JOIN fe_locations l ON l.id = r.location_id AND l.tenant_id = r.tenant_id"
            . " WHERE r.tenant_id = ? AND r.secret_hash = ? AND r.revoked_at IS NULL AND l.active = 1 AND l.task = 'returns'",
        );
        $stmt->execute([$tenant->id, $key->hash()]);
        $row = $stmt->fetch();

        return is_array($row) ? ['id' => (int) $row['id'], 'name' => (string) $row['name']] : null;
    }

    /**
     * Wann der gültige Schlüssel je Standort angelegt wurde (UTC, …Z); Standorte ohne gültigen fehlen.
     *
     * @return array<int, string>
     */
    public function activeSince(Tenant $tenant): array
    {
        $stmt = $this->pdo->prepare('SELECT location_id, created_at FROM fe_return_points WHERE tenant_id = ? AND revoked_at IS NULL');
        $stmt->execute([$tenant->id]);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int) $row['location_id']] = str_replace(' ', 'T', (string) $row['created_at']) . 'Z';
        }

        return $result;
    }
}
