<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\User;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * fe_sessions: nur Token-Hashes, Höchstdauer 90 Tage, kein Löschen von Benutzern mit Sitzungen.
 */
#[CoversNothing]
final class SessionSchemaTest extends AuthSchemaTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = $this->createUser($this->createTenant());
    }

    public function testValidSessionIsAcceptedWithDefaults(): void
    {
        $id = $this->insert('fe_sessions', [
            'user_id' => $this->userId,
            'token_hash' => self::hash('token'),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        ]);

        $row = $this->pdo()->query(
            'SELECT device_label, created_at = last_seen_at AS same_time,'
            . ' TIMESTAMPDIFF(SECOND, created_at, UTC_TIMESTAMP()) AS age FROM fe_sessions WHERE id = ' . $id,
        )->fetch();

        self::assertNull($row['device_label']);
        self::assertSame(1, $row['same_time']);
        self::assertLessThan(60, abs($row['age']));
    }

    public function testNinetyDaysIsAccepted(): void
    {
        $this->createSession(['expires_at' => '2026-04-01 12:00:00', 'last_seen_at' => '2026-03-01 08:00:00']);

        self::assertSame(1, $this->countRows('fe_sessions'));
    }

    /**
     * @return iterable<string, array{array<string, scalar|null>}>
     */
    public static function invalidSessions(): iterable
    {
        yield 'länger als 90 Tage' => [['expires_at' => '2026-04-01 12:00:01']];
        yield 'Ablauf gleich Anlegen' => [['expires_at' => self::CREATED_AT]];
        yield 'zuletzt gesehen vor Anlegen' => [['last_seen_at' => '2026-01-01 11:59:59']];
        yield 'Hash zu kurz' => [['token_hash' => str_repeat("\x01", 31)]];
        yield 'leere Gerätebezeichnung' => [['device_label' => '']];
        yield 'Gerätebezeichnung nur Leerzeichen' => [['device_label' => '   ']];
    }

    /**
     * @param array<string, scalar|null> $overrides
     */
    #[DataProvider('invalidSessions')]
    public function testDatabaseRejectsInvalidSession(array $overrides): void
    {
        $this->assertRejected(self::ER_CONSTRAINT_FAILED, fn () => $this->createSession($overrides));
        self::assertSame(0, $this->countRows('fe_sessions'));
    }

    public function testDeviceLabelIsAccepted(): void
    {
        $this->createSession(['device_label' => 'Chrome auf Android']);
        $this->createSession(['device_label' => str_repeat('ä', 100)]);

        self::assertSame(2, $this->countRows('fe_sessions'));
    }

    public function testDatabaseRejectsTooLongDeviceLabel(): void
    {
        $this->assertRejected(
            self::ER_DATA_TOO_LONG,
            fn () => $this->createSession(['device_label' => str_repeat('ä', 101)]),
        );
    }

    public function testDatabaseRejectsDuplicateHash(): void
    {
        $this->createSession(['token_hash' => self::hash('token')]);

        $this->assertRejected(
            self::ER_DUP_ENTRY,
            fn () => $this->createSession(['token_hash' => self::hash('token')]),
        );
    }

    public function testDatabaseRejectsUnknownUser(): void
    {
        $this->assertRejected(self::ER_NO_REFERENCED_ROW, fn () => $this->createSession(['user_id' => 999]));
    }

    public function testDatabaseRejectsDeletingUserWithSession(): void
    {
        $this->createSession();

        $this->assertRejected(
            self::ER_ROW_IS_REFERENCED,
            fn () => $this->pdo()->exec('DELETE FROM fe_users WHERE id = ' . $this->userId),
        );
    }

    /**
     * @param array<string, scalar|null> $overrides
     */
    private function createSession(array $overrides = []): int
    {
        return $this->insert('fe_sessions', array_merge([
            'user_id' => $this->userId,
            'token_hash' => self::hash('session-' . $this->countRows('fe_sessions')),
            'created_at' => self::CREATED_AT,
            'last_seen_at' => self::CREATED_AT,
            'expires_at' => '2026-01-31 12:00:00',
        ], $overrides));
    }
}
