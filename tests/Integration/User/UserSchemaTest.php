<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\User;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * fe_users: Die Datenbank erzwingt Rolle/Stadt, eindeutige E-Mail je Stadt und die Regeln des
 * Lebenszyklus (invited → unverified → pending → active, deleted = anonymisiert).
 */
#[CoversNothing]
final class UserSchemaTest extends AuthSchemaTestCase
{
    public function testActiveUserIsAcceptedWithDefaults(): void
    {
        $tenantId = $this->createTenant();
        $id = $this->createUser($tenantId);

        $row = $this->pdo()->query(
            'SELECT tenant_key, locked_at, deleted_at, invitation_code_id, created_at = updated_at AS same_time,'
            . ' TIMESTAMPDIFF(SECOND, created_at, UTC_TIMESTAMP()) AS age FROM fe_users WHERE id = ' . $id,
        )->fetch();

        self::assertSame($tenantId, $row['tenant_key']);
        self::assertNull($row['locked_at']);
        self::assertNull($row['deleted_at']);
        self::assertNull($row['invitation_code_id']);
        self::assertSame(1, $row['same_time']);
        self::assertLessThan(60, abs($row['age']));
    }

    public function testSuperadminWithoutTenantIsAccepted(): void
    {
        $id = $this->createSuperadmin();

        self::assertSame(0, $this->pdo()->query('SELECT tenant_key FROM fe_users WHERE id = ' . $id)->fetchColumn());
    }

    public function testInvitedFirstAdminIsAccepted(): void
    {
        $tenantId = $this->createTenant();

        $this->createUser($tenantId, [
            'role' => 'admin',
            'status' => 'invited',
            'email_verified_at' => null,
            'password_hash' => null,
            'name' => null,
            'display_name' => null,
        ]);

        self::assertSame(1, $this->countRows('fe_users'));
    }

    public function testRegisteredUsersAreAccepted(): void
    {
        $tenantId = $this->createTenant();
        $codeId = $this->createInvitationCode($tenantId, $this->createUser($tenantId, ['role' => 'admin']));

        $this->createUser($tenantId, [
            'status' => 'unverified',
            'email_verified_at' => null,
            'display_name' => null,
            'invitation_code_id' => $codeId,
        ]);
        $this->createUser($tenantId, [
            'status' => 'pending',
            'display_name' => null,
            'invitation_code_id' => $codeId,
        ]);

        self::assertSame(3, $this->countRows('fe_users'));
    }

    public function testLockedUserIsAccepted(): void
    {
        $this->createUser($this->createTenant(), ['locked_at' => self::CREATED_AT]);

        self::assertSame(1, $this->countRows('fe_users'));
    }

    public function testSeveralAnonymizedUsersAreAccepted(): void
    {
        $tenantId = $this->createTenant();

        $this->createUser($tenantId, self::anonymized());
        $this->createUser($tenantId, self::anonymized());
        $this->createUser($tenantId, ['role' => 'admin'] + self::anonymized());

        self::assertSame(3, $this->countRows('fe_users'));
    }

    public function testSameEmailInDifferentTenantsAndForSuperadminIsAccepted(): void
    {
        $this->createUser($this->createTenant('immendingen'), ['email' => 'max@example.org']);
        $this->createUser($this->createTenant('tuttlingen'), ['email' => 'max@example.org']);
        $this->createUser(null, ['role' => 'superadmin', 'email' => 'max@example.org']);

        self::assertSame(3, $this->countRows('fe_users'));
    }

    public function testEmailsDifferingOnlyBySimilarCharactersAreDistinct(): void
    {
        $tenantId = $this->createTenant();

        $this->createUser($tenantId, ['email' => 'strasse@x.de']);
        $this->createUser($tenantId, ['email' => 'straße@x.de']);
        $this->createUser($tenantId, ['email' => 'rene@x.de']);
        $this->createUser($tenantId, ['email' => 'rené@x.de']);

        self::assertSame(4, $this->countRows('fe_users'));
    }

    public function testDatabaseRejectsDuplicateEmailInSameTenant(): void
    {
        $tenantId = $this->createTenant();
        $this->createUser($tenantId, ['email' => 'max@example.org']);

        $this->assertRejected(
            self::ER_DUP_ENTRY,
            fn () => $this->createUser($tenantId, ['email' => 'max@example.org']),
        );
    }

    public function testDatabaseRejectsDuplicateSuperadminEmail(): void
    {
        $this->createUser(null, ['role' => 'superadmin', 'email' => 'max@example.org']);

        $this->assertRejected(
            self::ER_DUP_ENTRY,
            fn () => $this->createUser(null, ['role' => 'superadmin', 'email' => 'max@example.org']),
        );
    }

    /**
     * Abweichungen von einem gültigen, freigeschalteten Konto einer Stadt.
     *
     * @return iterable<string, array{array<string, scalar|null>}>
     */
    public static function invalidUsers(): iterable
    {
        yield 'Super-Admin mit Stadt' => [['role' => 'superadmin']];
        yield 'Admin ohne Stadt' => [['role' => 'admin', 'tenant_id' => null]];
        yield 'Benutzer ohne Stadt' => [['tenant_id' => null]];
        yield 'unbekannte Rolle' => [['role' => 'owner']];
        yield 'Rolle groß' => [['role' => 'ADMIN']];
        yield 'unbekannter Status' => [['status' => 'locked']];
        yield 'Super-Admin eingeladen' => [[
            'role' => 'superadmin', 'tenant_id' => null, 'status' => 'invited',
            'email_verified_at' => null, 'password_hash' => null,
        ]];
        yield 'E-Mail mit Großbuchstaben' => [['email' => 'Max@example.org']];
        yield 'E-Mail mit Umlaut groß' => [['email' => 'jürgen@MÜNCHEN.de']];
        yield 'E-Mail mit Leerzeichen' => [['email' => 'max mustermann@example.org']];
        yield 'E-Mail mit Leerzeichen am Anfang' => [['email' => ' max@example.org']];
        yield 'E-Mail mit Zeilenumbruch am Ende' => [['email' => "max@example.org\n"]];
        yield 'E-Mail ohne @' => [['email' => 'max.example.org']];
        yield 'E-Mail mit zwei @' => [['email' => 'max@mustermann@example.org']];
        yield 'E-Mail ohne lokalen Teil' => [['email' => '@example.org']];
        yield 'E-Mail ohne Domain' => [['email' => 'max@']];
        yield 'leere E-Mail' => [['email' => '']];
        yield 'bcrypt-Hash' => [['password_hash' => '$2y$10$abcdefghijklmnopqrstuuJ8s9Gk0hX1yYpG3qZ0lFZ6h7fX9q1aW']];
        yield 'Argon2i-Hash' => [['password_hash' => '$argon2i$v=19$m=65536,t=4,p=1$c2FsdA$aGFzaA']];
        yield 'Klartext-Passwort' => [['password_hash' => 'geheim12345']];
        yield 'Argon2id groß' => [['password_hash' => '$ARGON2ID$v=19$m=65536,t=4,p=1$c2FsdA$aGFzaA']];
        yield 'leerer Name' => [['name' => '   ']];
        yield 'leerer Anzeigename' => [['display_name' => '']];
        yield 'aktiv ohne E-Mail' => [['email' => null]];
        yield 'aktiv ohne Passwort' => [['password_hash' => null]];
        yield 'aktiv ohne Anzeigename' => [['display_name' => null]];
        yield 'aktiv ohne bestätigte E-Mail' => [['email_verified_at' => null]];
        yield 'aktiv mit deleted_at' => [['deleted_at' => self::CREATED_AT]];
        yield 'eingeladen mit Passwort' => [['status' => 'invited', 'email_verified_at' => null]];
        yield 'eingeladen mit bestätigter E-Mail' => [['status' => 'invited', 'password_hash' => null]];
        yield 'unbestätigt ohne Einladungscode' => [['status' => 'unverified', 'email_verified_at' => null]];
        yield 'wartend ohne Einladungscode' => [['status' => 'pending']];
        yield 'gelöscht ohne deleted_at' => [['deleted_at' => null] + self::anonymized()];
        yield 'gelöscht mit E-Mail' => [['email' => 'max@example.org'] + self::anonymized()];
        yield 'gelöscht mit bestätigter E-Mail' => [['email_verified_at' => self::CREATED_AT] + self::anonymized()];
        yield 'gelöscht mit Passwort' => [['password_hash' => self::PASSWORD_HASH] + self::anonymized()];
        yield 'gelöscht mit Name' => [['name' => 'Max Mustermann'] + self::anonymized()];
        yield 'gelöscht mit Anzeigename' => [['display_name' => 'Max'] + self::anonymized()];
    }

    /**
     * @param array<string, scalar|null> $overrides
     */
    #[DataProvider('invalidUsers')]
    public function testDatabaseRejectsInvalidUser(array $overrides): void
    {
        $tenantId = $this->createTenant();

        $this->assertRejected(self::ER_CONSTRAINT_FAILED, fn () => $this->createUser($tenantId, $overrides));
        self::assertSame(0, $this->countRows('fe_users'));
    }

    /**
     * Registrierte Konten mit gültigem Einladungscode, aber verletzter Regel.
     *
     * @return iterable<string, array{array<string, scalar|null>}>
     */
    public static function invalidRegisteredUsers(): iterable
    {
        yield 'unbestätigt mit bestätigter E-Mail' => [['status' => 'unverified']];
        yield 'unbestätigt ohne Passwort' => [['status' => 'unverified', 'email_verified_at' => null, 'password_hash' => null]];
        yield 'unbestätigt ohne Name' => [['status' => 'unverified', 'email_verified_at' => null, 'name' => null]];
        yield 'wartend ohne bestätigte E-Mail' => [['status' => 'pending', 'email_verified_at' => null]];
        yield 'wartend ohne Name' => [['status' => 'pending', 'name' => null]];
    }

    /**
     * @param array<string, scalar|null> $overrides
     */
    #[DataProvider('invalidRegisteredUsers')]
    public function testDatabaseRejectsInvalidRegisteredUser(array $overrides): void
    {
        $tenantId = $this->createTenant();
        $codeId = $this->createInvitationCode($tenantId, $this->createUser($tenantId, ['role' => 'admin']));

        $this->assertRejected(
            self::ER_CONSTRAINT_FAILED,
            fn () => $this->createUser($tenantId, ['invitation_code_id' => $codeId] + $overrides),
        );
        self::assertSame(1, $this->countRows('fe_users'));
    }

    public function testDatabaseRejectsTooLongValues(): void
    {
        $tenantId = $this->createTenant();

        $this->assertRejected(
            self::ER_DATA_TOO_LONG,
            fn () => $this->createUser($tenantId, ['email' => str_repeat('a', 243) . '@example.org']),
        );
        $this->assertRejected(
            self::ER_DATA_TOO_LONG,
            fn () => $this->createUser($tenantId, ['name' => str_repeat('ä', 101)]),
        );
        $this->assertRejected(
            self::ER_DATA_TOO_LONG,
            fn () => $this->createUser($tenantId, ['display_name' => str_repeat('ä', 101)]),
        );
    }

    public function testDatabaseRejectsUnknownTenant(): void
    {
        $this->assertRejected(self::ER_NO_REFERENCED_ROW, fn () => $this->createUser(999));
    }

    public function testDatabaseRejectsInvitationCodeOfOtherTenant(): void
    {
        $otherTenantId = $this->createTenant('tuttlingen');
        $codeId = $this->createInvitationCode(
            $otherTenantId,
            $this->createUser($otherTenantId, ['role' => 'admin']),
        );
        $tenantId = $this->createTenant();

        $this->assertRejected(
            self::ER_NO_REFERENCED_ROW,
            fn () => $this->createUser($tenantId, ['invitation_code_id' => $codeId]),
        );
    }

    public function testDatabaseRejectsDeletingTenantWithUsers(): void
    {
        $tenantId = $this->createTenant();
        $this->createUser($tenantId);

        $this->assertRejected(
            self::ER_ROW_IS_REFERENCED,
            fn () => $this->pdo()->exec('DELETE FROM fe_tenants WHERE id = ' . $tenantId),
        );
    }

    public function testDatabaseRejectsChangingSuperadminToTenantRole(): void
    {
        $id = $this->createSuperadmin();

        $this->assertRejected(
            self::ER_CONSTRAINT_FAILED,
            fn () => $this->pdo()->exec("UPDATE fe_users SET role = 'admin' WHERE id = " . $id),
        );
    }

    public function testDatabaseRejectsAnonymizedSuperadmin(): void
    {
        $this->assertRejected(
            self::ER_CONSTRAINT_FAILED,
            fn () => $this->createUser(null, ['role' => 'superadmin'] + self::anonymized()),
        );

        $id = $this->createSuperadmin();
        $this->assertRejected(
            self::ER_CONSTRAINT_FAILED,
            fn () => $this->pdo()->exec(
                "UPDATE fe_users SET status = 'deleted', email = NULL, email_verified_at = NULL, password_hash = NULL,"
                . " name = NULL, display_name = NULL, deleted_at = '" . self::CREATED_AT . "' WHERE id = " . $id,
            ),
        );
    }

    public function testProtectedActiveSuperadminIsAccepted(): void
    {
        $id = $this->createUser(null, ['role' => 'superadmin', 'protected' => 1]);

        self::assertSame(1, $this->pdo()->query('SELECT protected FROM fe_users WHERE id = ' . $id)->fetchColumn());
    }

    public function testProtectedDefaultsToZero(): void
    {
        $id = $this->createSuperadmin();

        self::assertSame(0, $this->pdo()->query('SELECT protected FROM fe_users WHERE id = ' . $id)->fetchColumn());
    }

    /**
     * @return iterable<string, array{?string, array<string, scalar|null>}>
     */
    public static function invalidProtectedUsers(): iterable
    {
        yield 'Admin einer Stadt' => ['tenant', ['role' => 'admin', 'protected' => 1]];
        yield 'Benutzer einer Stadt' => ['tenant', ['protected' => 1]];
        yield 'gesperrter Super-Admin' => [null, ['role' => 'superadmin', 'protected' => 1, 'locked_at' => self::CREATED_AT]];
        yield 'Wert 2' => [null, ['role' => 'superadmin', 'protected' => 2]];
    }

    /**
     * @param array<string, scalar|null> $overrides
     */
    #[DataProvider('invalidProtectedUsers')]
    public function testDatabaseRejectsInvalidProtectedUser(?string $tenant, array $overrides): void
    {
        $tenantId = $tenant === null ? null : $this->createTenant();

        $this->assertRejected(self::ER_CONSTRAINT_FAILED, fn () => $this->createUser($tenantId, $overrides));
        self::assertSame(0, $this->countRows('fe_users'));
    }

    public function testDatabaseRejectsLockingProtectedSuperadmin(): void
    {
        $id = $this->createUser(null, ['role' => 'superadmin', 'protected' => 1]);

        $this->assertRejected(
            self::ER_CONSTRAINT_FAILED,
            fn () => $this->pdo()->exec('UPDATE fe_users SET locked_at = UTC_TIMESTAMP() WHERE id = ' . $id),
        );
    }

    public function testDeviceLogNotificationIsAcceptedForAdminsAndSuperadmins(): void
    {
        $tenantId = $this->createTenant();
        $admin = $this->createUser($tenantId, ['role' => 'admin', 'notify_device_log' => 1]);
        $superadmin = $this->createUser(null, ['role' => 'superadmin', 'notify_device_log' => 1]);
        $user = $this->createUser($tenantId);

        self::assertSame(
            [[$admin, 1], [$superadmin, 1], [$user, 0]],
            $this->pdo()->query('SELECT id, notify_device_log FROM fe_users ORDER BY id')->fetchAll(\PDO::FETCH_NUM),
        );
    }

    /**
     * @return iterable<string, array{array<string, scalar|null>}>
     */
    public static function invalidDeviceLogNotifications(): iterable
    {
        yield 'Benutzer' => [['notify_device_log' => 1]];
        yield 'gelöschter Admin' => [['role' => 'admin', 'notify_device_log' => 1] + self::anonymized()];
        yield 'Wert 2' => [['role' => 'admin', 'notify_device_log' => 2]];
    }

    /**
     * @param array<string, scalar|null> $overrides
     */
    #[DataProvider('invalidDeviceLogNotifications')]
    public function testDatabaseRejectsInvalidDeviceLogNotification(array $overrides): void
    {
        $tenantId = $this->createTenant();

        $this->assertRejected(self::ER_CONSTRAINT_FAILED, fn () => $this->createUser($tenantId, $overrides));
        self::assertSame(0, $this->countRows('fe_users'));
    }

    public function testDatabaseRejectsDemotingAdminWithDeviceLogNotification(): void
    {
        $id = $this->createUser($this->createTenant(), ['role' => 'admin', 'notify_device_log' => 1]);

        $this->assertRejected(
            self::ER_CONSTRAINT_FAILED,
            fn () => $this->pdo()->exec("UPDATE fe_users SET role = 'user' WHERE id = " . $id),
        );
    }

    /**
     * @return array<string, scalar|null>
     */
    private static function anonymized(): array
    {
        return [
            'status' => 'deleted',
            'email' => null,
            'email_verified_at' => null,
            'password_hash' => null,
            'name' => null,
            'display_name' => null,
            'deleted_at' => self::CREATED_AT,
        ];
    }
}
