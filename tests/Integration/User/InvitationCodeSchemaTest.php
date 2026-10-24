<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\User;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * fe_invitation_codes: nur Hashes, höchstens 90 Tage gültig, Ersteller aus derselben Stadt.
 */
#[CoversNothing]
final class InvitationCodeSchemaTest extends AuthSchemaTestCase
{
    private int $tenantId;
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = $this->createTenant();
        $this->adminId = $this->createUser($this->tenantId, ['role' => 'admin']);
    }

    public function testValidCodeIsAcceptedWithDefaults(): void
    {
        $id = $this->createInvitationCode($this->tenantId, $this->adminId, ['label' => 'Löschzug Nord']);

        $row = $this->pdo()->query('SELECT use_count, revoked_at FROM fe_invitation_codes WHERE id = ' . $id)->fetch();

        self::assertSame(0, $row['use_count']);
        self::assertNull($row['revoked_at']);
    }

    public function testNinetyDaysValidityIsAccepted(): void
    {
        $this->createInvitationCode($this->tenantId, $this->adminId, ['expires_at' => '2026-04-01 12:00:00']);

        self::assertSame(1, $this->countRows('fe_invitation_codes'));
    }

    public function testExhaustedAndRevokedCodeIsAccepted(): void
    {
        $this->createInvitationCode($this->tenantId, $this->adminId, [
            'max_uses' => 3,
            'use_count' => 3,
            'revoked_at' => self::CREATED_AT,
        ]);

        self::assertSame(1, $this->countRows('fe_invitation_codes'));
    }

    /**
     * @return iterable<string, array{array<string, scalar|null>}>
     */
    public static function invalidCodes(): iterable
    {
        yield 'länger als 90 Tage' => [['expires_at' => '2026-04-01 12:00:01']];
        yield 'Ablauf gleich Anlegen' => [['expires_at' => self::CREATED_AT]];
        yield 'Ablauf vor Anlegen' => [['expires_at' => '2025-12-31 12:00:00']];
        yield 'Hash zu kurz' => [['code_hash' => str_repeat("\x01", 31)]];
        yield 'Hash leer' => [['code_hash' => '']];
        yield 'max_uses 0' => [['max_uses' => 0]];
        yield 'use_count über max_uses' => [['max_uses' => 2, 'use_count' => 3]];
        yield 'leere Bezeichnung' => [['label' => '  ']];
        yield 'Widerruf vor Anlegen' => [['revoked_at' => '2026-01-01 11:59:59']];
    }

    /**
     * @param array<string, scalar|null> $overrides
     */
    #[DataProvider('invalidCodes')]
    public function testDatabaseRejectsInvalidCode(array $overrides): void
    {
        $this->assertRejected(
            self::ER_CONSTRAINT_FAILED,
            fn () => $this->createInvitationCode($this->tenantId, $this->adminId, $overrides),
        );
        self::assertSame(0, $this->countRows('fe_invitation_codes'));
    }

    public function testDatabaseRejectsTooLongHash(): void
    {
        $this->assertRejected(
            self::ER_DATA_TOO_LONG,
            fn () => $this->createInvitationCode($this->tenantId, $this->adminId, ['code_hash' => str_repeat("\x01", 33)]),
        );
    }

    public function testDatabaseRejectsDuplicateHash(): void
    {
        $this->createInvitationCode($this->tenantId, $this->adminId, ['code_hash' => self::hash('ABCD')]);

        $this->assertRejected(
            self::ER_DUP_ENTRY,
            fn () => $this->createInvitationCode($this->tenantId, $this->adminId, ['code_hash' => self::hash('ABCD')]),
        );
    }

    public function testDatabaseRejectsCreatorOfOtherTenant(): void
    {
        $otherTenantId = $this->createTenant('tuttlingen');

        $this->assertRejected(
            self::ER_NO_REFERENCED_ROW,
            fn () => $this->createInvitationCode($otherTenantId, $this->adminId),
        );
    }

    public function testDatabaseRejectsSuperadminAsCreator(): void
    {
        $superadminId = $this->createSuperadmin();

        $this->assertRejected(
            self::ER_NO_REFERENCED_ROW,
            fn () => $this->createInvitationCode($this->tenantId, $superadminId),
        );
    }

    public function testDatabaseRejectsUnknownTenant(): void
    {
        $this->assertRejected(
            self::ER_NO_REFERENCED_ROW,
            fn () => $this->createInvitationCode(999, $this->adminId),
        );
    }

    public function testDatabaseRejectsDeletingCreatorOrUsedCode(): void
    {
        $codeId = $this->createInvitationCode($this->tenantId, $this->adminId);
        $this->createUser($this->tenantId, [
            'status' => 'pending',
            'display_name' => null,
            'invitation_code_id' => $codeId,
        ]);

        $this->assertRejected(
            self::ER_ROW_IS_REFERENCED,
            fn () => $this->pdo()->exec('DELETE FROM fe_users WHERE id = ' . $this->adminId),
        );
        $this->assertRejected(
            self::ER_ROW_IS_REFERENCED,
            fn () => $this->pdo()->exec('DELETE FROM fe_invitation_codes WHERE id = ' . $codeId),
        );
    }
}
