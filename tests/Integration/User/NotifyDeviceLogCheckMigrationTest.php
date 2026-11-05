<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\User;

use FirefightingEquipment\Migration\SqlSplitter;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Migrationen 0039/0040 (CHECK chk_fe_users_notify_device_log ohne Bedingung an die Kontoart): Jede
 * Zeile, die die alte CHECK aus 0018 erfüllt, erfüllt die neue, und 0040 läuft auf solchen Zeilen
 * durch, ohne etwas zu ändern. DomainTestCase führt beide auf leeren Tabellen aus; hier steht vorher
 * wieder die alte CHECK (am Ende in jedem Fall wieder die neue).
 */
#[CoversNothing]
final class NotifyDeviceLogCheckMigrationTest extends AuthSchemaTestCase
{
    private const string OLD = self::MIGRATIONS_DIR . '/0018_add_fe_users_notify_device_log.sql';
    private const string DROP = self::MIGRATIONS_DIR . '/0039_drop_fe_users_notify_device_log_check.sql';
    private const string ADD = self::MIGRATIONS_DIR . '/0040_add_fe_users_notify_device_log_check.sql';

    private const array ROLES = ['superadmin', 'admin', 'user'];
    private const array STATUSES = ['invited', 'unverified', 'pending', 'active', 'deleted'];

    protected function tearDown(): void
    {
        if ($this->hasConnection()) {
            // Übrige Zeilen erfüllen die alte CHECK, also auch die neue.
            $this->pdo()->exec('ALTER TABLE fe_users DROP CONSTRAINT IF EXISTS chk_fe_users_notify_device_log');
            $this->runFile(self::ADD);
        }
        parent::tearDown();
    }

    public function testNewConditionHoldsForEveryCombinationTheOldOneAllowed(): void
    {
        $values = static fn (array $list): string => implode(' UNION ALL ', array_map(
            static fn (string|int $v): string => 'SELECT ' . (is_int($v) ? (string) $v : "'" . $v . "'") . ' AS v',
            $list,
        ));
        $combinations = 'SELECT r.v AS role, s.v AS status, n.v AS notify_device_log FROM (' . $values(self::ROLES) . ') r'
            . ' CROSS JOIN (' . $values(self::STATUSES) . ') s CROSS JOIN (' . $values([0, 1, 2]) . ') n';

        $count = fn (string $where): int => (int) $this->pdo()->query('SELECT COUNT(*) FROM (' . $combinations . ') c WHERE ' . $where)->fetchColumn();

        self::assertSame(0, $count('(' . $this->condition(self::OLD) . ') AND NOT (' . $this->condition(self::ADD) . ')'));
        self::assertSame(
            ['user active' => 1, 'user pending' => 1],
            [
                'user active' => $count("role = 'user' AND status = 'active' AND notify_device_log = 1 AND (" . $this->condition(self::ADD) . ')'),
                'user pending' => $count("role = 'user' AND status = 'pending' AND notify_device_log = 1 AND (" . $this->condition(self::ADD) . ')'),
            ],
            'Neu erlaubt: die Option an Konten der Kontoart user (entscheidet die Rolle im Code).',
        );
        self::assertSame(0, $count("status = 'deleted' AND notify_device_log = 1 AND (" . $this->condition(self::ADD) . ')'));
        self::assertSame(0, $count('notify_device_log = 2 AND (' . $this->condition(self::ADD) . ')'));
    }

    public function testMigrationsRunOnRowsOfTheOldCheckAndChangeNothing(): void
    {
        $this->pdo()->exec('ALTER TABLE fe_users DROP CONSTRAINT chk_fe_users_notify_device_log');
        $this->pdo()->exec('ALTER TABLE fe_users ADD CONSTRAINT chk_fe_users_notify_device_log CHECK (' . $this->condition(self::OLD) . ')');
        $tenantId = $this->createTenant();

        $accepted = [];
        foreach (self::ROLES as $role) {
            foreach (['active', 'deleted'] as $status) {
                foreach ([0, 1] as $notify) {
                    foreach ([false, true] as $locked) {
                        $overrides = ['role' => $role, 'status' => $status, 'notify_device_log' => $notify]
                            + ($locked ? ['locked_at' => self::CREATED_AT] : [])
                            + ($status === 'deleted' ? self::anonymized() : []);
                        try {
                            $this->createUser($role === 'superadmin' ? null : $tenantId, $overrides);
                            $accepted[] = sprintf('%s %s %d%s', $role, $status, $notify, $locked ? ' gesperrt' : '');
                        } catch (\PDOException $e) {
                            self::assertSame(self::ER_CONSTRAINT_FAILED, $e->errorInfo[1] ?? null, $e->getMessage());
                        }
                    }
                }
            }
        }
        foreach (['admin active 1', 'admin active 1 gesperrt', 'superadmin active 1', 'user active 0', 'user deleted 0'] as $expected) {
            self::assertContains($expected, $accepted);
        }
        self::assertSame([], array_values(array_filter(
            $accepted,
            static fn (string $row): bool => str_starts_with($row, 'user') && str_contains($row, ' 1') || str_contains($row, 'deleted 1'),
        )), 'Die alte CHECK lässt die Option nur an Admins und Super-Admins zu.');

        $before = $this->pdo()->query('SELECT * FROM fe_users ORDER BY id')->fetchAll();
        $this->runFile(self::DROP);
        $this->runFile(self::ADD);

        self::assertSame($before, $this->pdo()->query('SELECT * FROM fe_users ORDER BY id')->fetchAll());
        $clause = (string) $this->pdo()->query(
            "SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()"
            . " AND TABLE_NAME = 'fe_users' AND CONSTRAINT_NAME = 'chk_fe_users_notify_device_log'",
        )->fetchColumn();
        self::assertStringNotContainsString('superadmin', $clause);
        self::assertStringContainsString('deleted', $clause);
    }

    /** Bedingung der CHECK einer Migration (nach „ADD CONSTRAINT … CHECK (“ bis zur letzten Klammer). */
    private function condition(string $path): string
    {
        $content = file_get_contents($path);
        self::assertIsString($content);
        self::assertSame(1, preg_match('/ADD CONSTRAINT chk_fe_users_notify_device_log CHECK \((.*)\);\s*\z/s', $content, $match), $path);

        return $match[1];
    }

    private function runFile(string $path): void
    {
        $content = file_get_contents($path);
        self::assertIsString($content);
        foreach (SqlSplitter::split($content) as $statement) {
            $this->pdo()->exec($statement);
        }
    }

    /**
     * @return array<string, scalar|null>
     */
    private static function anonymized(): array
    {
        return [
            'email' => null,
            'email_verified_at' => null,
            'password_hash' => null,
            'name' => null,
            'display_name' => null,
            'deleted_at' => self::CREATED_AT,
        ];
    }
}
