<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tenant;

/**
 * Zugriff auf fe_tenants. Es gibt bewusst weder Umbenennen des Kürzels noch Löschen:
 * Das Kürzel ist Teil der URL und unveränderlich, gesperrte Städte behalten ihre Daten.
 * Umbenannt wird nur der Name.
 */
final readonly class TenantRepository
{
    private const int ER_DUP_ENTRY = 1062;
    private const string COLUMNS = 'id, slug, name, active, created_at, updated_at';

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @return list<Tenant> nach Kürzel sortiert
     */
    public function all(): array
    {
        $rows = $this->pdo->query('SELECT ' . self::COLUMNS . ' FROM fe_tenants ORDER BY slug')->fetchAll();

        return array_map(self::hydrate(...), $rows);
    }

    /**
     * Alle Städte mit der Zahl ihrer Konten (Übersicht des Super-Admins).
     *
     * @return list<TenantSummary> nach Kürzel sortiert
     */
    public function summaries(): array
    {
        return $this->fetchSummaries('', []);
    }

    public function summary(int $id): ?TenantSummary
    {
        return $this->fetchSummaries(' WHERE t.id = ?', [$id])[0] ?? null;
    }

    public function findById(int $id): ?Tenant
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM fe_tenants WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return is_array($row) ? self::hydrate($row) : null;
    }

    public function findBySlug(TenantSlug $slug): ?Tenant
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM fe_tenants WHERE slug = ?');
        $stmt->execute([$slug->value]);
        $row = $stmt->fetch();

        return is_array($row) ? self::hydrate($row) : null;
    }

    /**
     * Legt eine aktive Stadt an.
     *
     * @throws DuplicateTenantSlugException
     */
    public function create(TenantSlug $slug, TenantName $name): Tenant
    {
        try {
            $this->pdo->prepare(
                'INSERT INTO fe_tenants (slug, name, active, created_at, updated_at)'
                . ' VALUES (?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            )->execute([$slug->value, $name->value]);
        } catch (\PDOException $e) {
            // Erst die Datenbank entscheidet; so ist das Kürzel auch bei gleichzeitigen Aufrufen eindeutig.
            if (($e->errorInfo[1] ?? null) === self::ER_DUP_ENTRY) {
                throw DuplicateTenantSlugException::for($slug);
            }
            throw $e;
        }

        return $this->findBySlug($slug) ?? throw new \LogicException('Angelegte Stadt nicht gefunden.');
    }

    /**
     * Ändert den Namen (das Kürzel bleibt). Groß- und Kleinschreibung zählt als Änderung.
     *
     * @return bool ob sich etwas geändert hat (false: hatte den Namen bereits)
     *
     * @throws TenantNotFoundException
     */
    public function rename(TenantSlug $slug, TenantName $name): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE fe_tenants SET name = ?, updated_at = UTC_TIMESTAMP() WHERE slug = ? AND name COLLATE utf8mb4_bin <> ?',
        );
        $stmt->execute([$name->value, $slug->value, $name->value]);
        if ($stmt->rowCount() > 0) {
            return true;
        }

        if ($this->findBySlug($slug) === null) {
            throw TenantNotFoundException::for($slug);
        }

        return false;
    }

    /**
     * Sperrt (false) oder entsperrt (true) eine Stadt. Beim Sperren werden in derselben Transaktion
     * alle Sitzungen der Konten dieser Stadt beendet (auch wenn sie schon gesperrt war), damit nach
     * dem Entsperren keine alten Sitzungen wieder gelten.
     *
     * @return bool ob sich etwas geändert hat (false: war bereits in diesem Zustand)
     *
     * @throws TenantNotFoundException
     */
    public function setActive(TenantSlug $slug, bool $active): bool
    {
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('TenantRepository::setActive startet eine eigene Transaktion; keine offene Transaktion erlaubt.');
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT id, active FROM fe_tenants WHERE slug = ? FOR UPDATE');
            $stmt->execute([$slug->value]);
            $row = $stmt->fetch();
            if (!is_array($row)) {
                throw TenantNotFoundException::for($slug);
            }

            $changed = ((int) $row['active'] === 1) !== $active;
            if ($changed) {
                $this->pdo->prepare('UPDATE fe_tenants SET active = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')
                    ->execute([(int) $active, $row['id']]);
            }
            if (!$active) {
                $this->pdo->prepare('DELETE FROM fe_sessions WHERE user_id IN (SELECT id FROM fe_users WHERE tenant_id = ?)')
                    ->execute([$row['id']]);
            }
            $this->pdo->commit();

            return $changed;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Gezählt wird in einer abgeleiteten Tabelle je Stadt (MariaDB erkennt unter
     * ONLY_FULL_GROUP_BY keine funktionale Abhängigkeit von t.id).
     *
     * @param string    $where SQL aus diesem Code, nie aus Eingaben
     * @param list<int>  $params
     *
     * @return list<TenantSummary>
     */
    private function fetchSummaries(string $where, array $params): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.id, t.slug, t.name, t.active, t.created_at, t.updated_at,'
            . ' COALESCE(c.active_users, 0) AS active_users, COALESCE(c.active_admins, 0) AS active_admins,'
            . ' COALESCE(c.invited_admins, 0) AS invited_admins'
            . ' FROM fe_tenants t LEFT JOIN ('
            . "SELECT tenant_id, SUM(status = 'active' AND locked_at IS NULL) AS active_users,"
            . " SUM(status = 'active' AND locked_at IS NULL AND role = 'admin') AS active_admins,"
            . " SUM(status = 'invited') AS invited_admins"
            . ' FROM fe_users WHERE tenant_id IS NOT NULL GROUP BY tenant_id'
            . ') c ON c.tenant_id = t.id'
            . $where
            . ' ORDER BY t.slug',
        );
        $stmt->execute($params);

        return array_map(
            static fn (array $row): TenantSummary => new TenantSummary(
                self::hydrate($row),
                (int) $row['active_users'],
                (int) $row['active_admins'],
                (int) $row['invited_admins'],
            ),
            $stmt->fetchAll(),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): Tenant
    {
        return new Tenant(
            (int) $row['id'],
            TenantSlug::fromString((string) $row['slug']),
            TenantName::fromString((string) $row['name']),
            (int) $row['active'] === 1,
            self::utc((string) $row['created_at']),
            self::utc((string) $row['updated_at']),
        );
    }

    private static function utc(string $value): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'))
            ?: throw new \UnexpectedValueException(sprintf('Ungültiger Zeitstempel: %s', $value));
    }
}
