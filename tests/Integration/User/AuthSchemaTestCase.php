<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\User;

use FirefightingEquipment\Tests\Integration\DomainTestCase;

/**
 * Hilfen für die Schema-Tests der Benutzer- und Anmeldetabellen: Datensätze direkt per SQL
 * anlegen (an jeder Fachlogik vorbei) und erwartete Ablehnungen der Datenbank prüfen.
 */
abstract class AuthSchemaTestCase extends DomainTestCase
{
    protected const int ER_CONSTRAINT_FAILED = 4025;
    protected const int ER_DUP_ENTRY = 1062;
    protected const int ER_DATA_TOO_LONG = 1406;
    protected const int ER_ROW_IS_REFERENCED = 1451;
    protected const int ER_NO_REFERENCED_ROW = 1452;

    protected const string PASSWORD_HASH =
        '$argon2id$v=19$m=65536,t=4,p=1$c29tZXNhbHRzb21lc2FsdA$q5lXXdF4C1R1J0lC2Qn8eYb0d7oS5kqf0+0eY1m4gV8';

    /** Fester Zeitpunkt, damit Prüfungen relativ zu created_at nicht von der Uhr abhängen. */
    protected const string CREATED_AT = '2026-01-01 12:00:00';

    private int $emailCounter = 0;
    private int $codeCounter = 0;

    /**
     * @param array<string, scalar|null> $values Spaltennamen stammen nur aus dem Testcode.
     */
    protected function insert(string $table, array $values): int
    {
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', array_keys($values)),
            implode(', ', array_fill(0, count($values), '?')),
        );
        $this->pdo()->prepare($sql)->execute(array_values($values));

        return (int) $this->pdo()->lastInsertId();
    }

    protected function createTenant(string $slug = 'immendingen'): int
    {
        return $this->insert('fe_tenants', ['slug' => $slug, 'name' => 'Feuerwehr ' . $slug]);
    }

    /**
     * Legt ein freigeschaltetes Konto an; $overrides ersetzt einzelne Spalten.
     *
     * @param array<string, scalar|null> $overrides
     */
    protected function createUser(?int $tenantId, array $overrides = []): int
    {
        return $this->insert('fe_users', array_merge($this->activeUser($tenantId), $overrides));
    }

    protected function createSuperadmin(): int
    {
        return $this->createUser(null, ['role' => 'superadmin']);
    }

    /**
     * @return array<string, scalar|null>
     */
    protected function activeUser(?int $tenantId): array
    {
        return [
            'tenant_id' => $tenantId,
            'role' => 'user',
            'status' => 'active',
            'email' => sprintf('person%d@example.org', ++$this->emailCounter),
            'email_verified_at' => self::CREATED_AT,
            'password_hash' => self::PASSWORD_HASH,
            'name' => 'Max Mustermann',
            'display_name' => 'Max',
        ];
    }

    /**
     * @param array<string, scalar|null> $overrides
     */
    protected function createInvitationCode(int $tenantId, int $createdBy, array $overrides = []): int
    {
        return $this->insert('fe_invitation_codes', array_merge([
            'tenant_id' => $tenantId,
            'code_hash' => self::hash('code-' . ++$this->codeCounter),
            'max_uses' => 10,
            'created_by' => $createdBy,
            'created_at' => self::CREATED_AT,
            'expires_at' => '2026-01-31 12:00:00',
        ], $overrides));
    }

    protected static function hash(string $seed): string
    {
        return hash('sha256', $seed, true);
    }

    protected function countRows(string $table): int
    {
        return (int) $this->pdo()->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }

    protected function assertRejected(int $errorCode, callable $action): void
    {
        try {
            $action();
            self::fail('PDOException erwartet.');
        } catch (\PDOException $e) {
            self::assertSame($errorCode, $e->errorInfo[1] ?? null, $e->getMessage());
        }
    }
}
