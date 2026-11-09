<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Loan;

use FirefightingEquipment\Device\Device;
use FirefightingEquipment\Device\DeviceRuleViolationException;
use FirefightingEquipment\Device\InvalidDeviceValueException;
use FirefightingEquipment\Tenant\Tenant;

/**
 * Verleih (Phase 2, P2.5, fe_device_loans): Ansprechpartner, „Rückgabe bis“ und Kommentar zur Buchung auf
 * einen Standort mit der Aufgabe „Ausgeliehen an“. Offen ist ein Verleih, solange returned_at fehlt, die
 * Ausrüstung nicht ausgesondert ist und noch am Standort des Leihnehmers steht (hat jemand sie von Hand
 * woandershin gebucht, gilt der Verleih nicht mehr; ein neuer Verleih schließt ihn).
 */
final readonly class LoanRepository
{
    /** Bedingung „offen“ für Abfragen mit den Aliasen l (Verleih) und d (Gerät). */
    private const string OPEN = "l.returned_at IS NULL AND d.status <> 'retired' AND d.location_id = l.borrower_location_id";

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Der Standort des Leihnehmers: aktiv, Ebene 1, Aufgabe „Ausgeliehen an“.
     *
     * @return array{id: int, name: string}
     *
     * @throws InvalidDeviceValueException unbekannt, fremd oder ohne die Aufgabe
     */
    public function borrower(Tenant $tenant, int $locationId): array
    {
        $stmt = $this->pdo->prepare("SELECT id, name FROM fe_locations WHERE id = ? AND tenant_id = ? AND task = 'borrower' AND active = 1");
        $stmt->execute([$locationId, $tenant->id]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new InvalidDeviceValueException('Der Leihnehmer wurde nicht gefunden. Verliehen wird nur an Standorte mit der Aufgabe „Ausgeliehen an“.');
        }

        return ['id' => (int) $row['id'], 'name' => (string) $row['name']];
    }

    /**
     * Arbeit für DeviceStateChange::$inTransaction: schließt einen veralteten offenen Verleih der
     * Ausrüstung und legt den neuen an (in der Transaktion der Buchung).
     *
     * @throws DeviceRuleViolationException die Ausrüstung ist schon verliehen
     */
    public function lending(Tenant $tenant, int $userId, int $borrowerLocationId, ?string $contact, ?string $dueBackOn, ?string $comment): \Closure
    {
        return function (Device $current) use ($tenant, $userId, $borrowerLocationId, $contact, $dueBackOn, $comment): void {
            $stmt = $this->pdo->prepare(
                'SELECT l.id, l.borrower_location_id, b.name FROM fe_device_loans l JOIN fe_locations b ON b.id = l.borrower_location_id AND b.tenant_id = l.tenant_id'
                . ' WHERE l.tenant_id = ? AND l.device_id = ? AND l.returned_at IS NULL FOR UPDATE',
            );
            $stmt->execute([$tenant->id, $current->id]);
            $open = $stmt->fetch();
            if (is_array($open)) {
                if ((int) $open['borrower_location_id'] === $current->locationId) {
                    throw DeviceRuleViolationException::because(sprintf('%s ist schon verliehen (an „%s“).', $current->identifier->value, (string) $open['name']));
                }
                $this->pdo->prepare('UPDATE fe_device_loans SET returned_at = UTC_TIMESTAMP() WHERE id = ?')->execute([(int) $open['id']]);
            }
            $this->pdo->prepare(
                'INSERT INTO fe_device_loans (tenant_id, device_id, borrower_location_id, contact, due_back_on, comment, lent_by) VALUES (?, ?, ?, ?, ?, ?, ?)',
            )->execute([$tenant->id, $current->id, $borrowerLocationId, $contact, $dueBackOn, $comment, $userId]);
        };
    }

    /**
     * Arbeit für DeviceRepository::returnLoaned (Rückgabe, P2.6): der offene Verleih der Ausrüstung muss
     * existieren und sie muss noch beim Leihnehmer stehen; er wird in derselben Transaktion geschlossen.
     *
     * @throws DeviceRuleViolationException die Ausrüstung ist nicht verliehen
     */
    public function returning(Tenant $tenant): \Closure
    {
        return function (Device $current) use ($tenant): void {
            $stmt = $this->pdo->prepare('SELECT id, borrower_location_id FROM fe_device_loans WHERE tenant_id = ? AND device_id = ? AND returned_at IS NULL FOR UPDATE');
            $stmt->execute([$tenant->id, $current->id]);
            $open = $stmt->fetch();
            if (!is_array($open) || (int) $open['borrower_location_id'] !== $current->locationId) {
                throw DeviceRuleViolationException::because(sprintf('%s ist nicht verliehen.', $current->identifier->value));
            }
            $this->pdo->prepare('UPDATE fe_device_loans SET returned_at = UTC_TIMESTAMP() WHERE id = ?')->execute([(int) $open['id']]);
        };
    }

    /**
     * Die verliehene Ausrüstung zu einem Code (Nummer ohne Groß-/Kleinschreibung oder Scan-Code genau) –
     * nur Nummer und Typ, mehr verrät die Rückgabe ohne Anmeldung nicht.
     *
     * @return array{id: int, identifier: string, device_type: string}|null
     */
    public function findLoaned(Tenant $tenant, string $code): ?array
    {
        $code = trim($code);
        if ($code === '' || mb_strlen($code) > 200 || !mb_check_encoding($code, 'UTF-8')) {
            return null;
        }
        $identifier = trim((string) preg_replace('/\s+/u', ' ', $code));
        $stmt = $this->pdo->prepare(
            'SELECT d.id, d.identifier, t.name AS type_name FROM fe_device_loans l'
            . ' JOIN fe_devices d ON d.id = l.device_id AND d.tenant_id = l.tenant_id'
            . ' JOIN fe_device_types t ON t.id = d.device_type_id AND t.tenant_id = d.tenant_id'
            . " WHERE l.tenant_id = ? AND " . self::OPEN . " AND (d.identifier_key = REPLACE(LOWER(?), 'ẞ', 'ß') OR d.scan_code = ?)"
            . ' ORDER BY d.id LIMIT 1',
        );
        $stmt->execute([$tenant->id, $identifier, $code]);
        $row = $stmt->fetch();

        return is_array($row) ? ['id' => (int) $row['id'], 'identifier' => (string) $row['identifier'], 'device_type' => (string) $row['type_name']] : null;
    }

    /**
     * Schließt den offenen Verleih einer Ausrüstung (Rückgabe, P2.6). Wiederholbar.
     */
    public function close(Tenant $tenant, int $deviceId): void
    {
        $this->pdo->prepare('UPDATE fe_device_loans SET returned_at = UTC_TIMESTAMP() WHERE tenant_id = ? AND device_id = ? AND returned_at IS NULL')
            ->execute([$tenant->id, $deviceId]);
    }

    /**
     * Der offene Verleih einer Ausrüstung (nur wenn sie noch beim Leihnehmer steht).
     *
     * @return array{id: int, borrower_id: int, borrower: string}|null
     */
    public function openFor(Tenant $tenant, int $deviceId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT l.id, l.borrower_location_id, b.name FROM fe_device_loans l'
            . ' JOIN fe_devices d ON d.id = l.device_id AND d.tenant_id = l.tenant_id'
            . ' JOIN fe_locations b ON b.id = l.borrower_location_id AND b.tenant_id = l.tenant_id'
            . ' WHERE l.tenant_id = ? AND l.device_id = ? AND ' . self::OPEN,
        );
        $stmt->execute([$tenant->id, $deviceId]);
        $row = $stmt->fetch();

        return is_array($row) ? ['id' => (int) $row['id'], 'borrower_id' => (int) $row['borrower_location_id'], 'borrower' => (string) $row['name']] : null;
    }

    /**
     * Offene Verleihe nach Leihnehmer und „Rückgabe bis“ (ohne Datum zuletzt).
     *
     * @return list<array<string, mixed>> Zeilen mit id, device_id, borrower_id, borrower, contact, due_back_on, comment, lent_at, lent_by_id, lent_by_name
     */
    public function listOpen(Tenant $tenant, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT l.id, l.device_id, l.borrower_location_id AS borrower_id, b.name AS borrower, l.contact, l.due_back_on, l.comment,'
            . " l.lent_at, l.lent_by AS lent_by_id, IF(u.status = 'deleted', CONCAT('Gelöschter Benutzer #', u.id), COALESCE(NULLIF(u.display_name, ''), u.name)) AS lent_by_name"
            . ' FROM fe_device_loans l'
            . ' JOIN fe_devices d ON d.id = l.device_id AND d.tenant_id = l.tenant_id'
            . ' JOIN fe_locations b ON b.id = l.borrower_location_id AND b.tenant_id = l.tenant_id'
            . ' JOIN fe_users u ON u.id = l.lent_by AND u.tenant_id = l.tenant_id'
            . ' WHERE l.tenant_id = ? AND ' . self::OPEN
            . ' ORDER BY NATURAL_SORT_KEY(b.name), l.due_back_on IS NULL, l.due_back_on, l.id LIMIT ' . $limit,
        );
        $stmt->execute([$tenant->id]);

        return $stmt->fetchAll();
    }

    /**
     * @return array{open: int, overdue: int} offene Verleihe, davon mit „Rückgabe bis“ vor $today
     */
    public function counts(Tenant $tenant, string $today): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) AS open_count, COALESCE(SUM(l.due_back_on < ?), 0) AS overdue_count FROM fe_device_loans l'
            . ' JOIN fe_devices d ON d.id = l.device_id AND d.tenant_id = l.tenant_id WHERE l.tenant_id = ? AND ' . self::OPEN,
        );
        $stmt->execute([$today, $tenant->id]);
        $row = $stmt->fetch();

        return ['open' => (int) ($row['open_count'] ?? 0), 'overdue' => (int) ($row['overdue_count'] ?? 0)];
    }
}
