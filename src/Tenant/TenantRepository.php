<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tenant;

/**
 * Zugriff auf fe_tenants. Es gibt bewusst weder Umbenennen des Kürzels noch Löschen:
 * Das Kürzel ist Teil der URL und unveränderlich, gesperrte Städte behalten ihre Daten.
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
     * Sperrt (false) oder entsperrt (true) eine Stadt.
     *
     * @return bool ob sich etwas geändert hat (false: war bereits in diesem Zustand)
     *
     * @throws TenantNotFoundException
     */
    public function setActive(TenantSlug $slug, bool $active): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE fe_tenants SET active = ?, updated_at = UTC_TIMESTAMP() WHERE slug = ? AND active <> ?',
        );
        $stmt->execute([(int) $active, $slug->value, (int) $active]);
        if ($stmt->rowCount() > 0) {
            return true;
        }

        if ($this->findBySlug($slug) === null) {
            throw TenantNotFoundException::for($slug);
        }

        return false;
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
