<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Invitation;

use FirefightingEquipment\Invitation\InvalidInvitationValueException;
use FirefightingEquipment\Invitation\InvitationCode;
use FirefightingEquipment\Invitation\InvitationCodeLabel;
use FirefightingEquipment\Invitation\InvitationCodeRepository;
use FirefightingEquipment\Invitation\InvitationCodeStatus;
use FirefightingEquipment\Invitation\InvitationCodeValue;
use FirefightingEquipment\Tests\Integration\Auth\AuthTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(InvitationCodeRepository::class)]
#[CoversClass(InvitationCode::class)]
#[UsesClass(InvitationCodeLabel::class)]
#[UsesClass(InvitationCodeStatus::class)]
#[UsesClass(InvitationCodeValue::class)]
#[UsesClass(InvalidInvitationValueException::class)]
final class InvitationCodeRepositoryTest extends AuthTestCase
{
    private InvitationCodeRepository $codes;
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->codes = new InvitationCodeRepository($this->pdo());
        $this->admin = $this->account('immendingen', 'admin@example.org', ['role' => 'admin']);
    }

    public function testCreateStoresOnlyHash(): void
    {
        [$code, $value] = $this->codes->create($this->tenant('immendingen'), $this->admin, InvitationCodeLabel::fromString('Dienstabend'), 14, 20);

        self::assertSame('Dienstabend', $code->label?->value);
        self::assertSame(20, $code->maxUses);
        self::assertSame(0, $code->useCount);
        self::assertSame($this->admin, $code->createdBy);
        self::assertNull($code->revokedAt);
        self::assertSame(InvitationCodeStatus::Active, $code->status);
        self::assertSame(14 * 86400, $code->expiresAt->getTimestamp() - $code->createdAt->getTimestamp());
        self::assertLessThan(60, abs(time() - $code->createdAt->getTimestamp()), 'created_at in UTC');

        $row = $this->pdo()->query('SELECT * FROM fe_invitation_codes')->fetch();
        self::assertSame($value->hash(), $row['code_hash']);
        self::assertStringNotContainsString($value->value(), implode('|', array_map('strval', $row)));
    }

    public function testCreateAllowsMaximumValues(): void
    {
        [$code] = $this->codes->create($this->tenant('immendingen'), $this->admin, null, 90, 500);

        self::assertNull($code->label);
        self::assertSame(500, $code->maxUses);
        self::assertSame(90 * 86400, $code->expiresAt->getTimestamp() - $code->createdAt->getTimestamp());
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function invalidLimits(): iterable
    {
        yield '0 Tage' => [0, 1];
        yield '91 Tage' => [91, 1];
        yield '0 Nutzungen' => [1, 0];
        yield '501 Nutzungen' => [1, 501];
    }

    #[DataProvider('invalidLimits')]
    public function testCreateRejectsLimits(int $days, int $uses): void
    {
        try {
            $this->codes->create($this->tenant('immendingen'), $this->admin, null, $days, $uses);
            self::fail('InvalidInvitationValueException erwartet.');
        } catch (InvalidInvitationValueException) {
            self::assertSame(0, $this->countRows('fe_invitation_codes'));
        }
    }

    public function testCreatorMustBelongToTenant(): void
    {
        $this->expectException(\PDOException::class);

        $this->codes->create($this->tenant('tuttlingen'), $this->admin, null, 1, 1);
    }

    public function testListIsNewestFirstAndOnlyOwnTenant(): void
    {
        $tenant = $this->tenant('immendingen');
        [$first] = $this->codes->create($tenant, $this->admin, null, 1, 1);
        [$second] = $this->codes->create($tenant, $this->admin, null, 1, 1);
        $this->codes->create($this->tenant('tuttlingen'), $this->account('tuttlingen', 'admin@example.org', ['role' => 'admin']), null, 1, 1);

        self::assertSame(
            [$second->id, $first->id],
            array_map(static fn (InvitationCode $c): int => $c->id, $this->codes->listByTenant($tenant)),
        );
        self::assertNull($this->codes->findByTenantAndId($this->tenant('tuttlingen'), $first->id));
    }

    public function testStatusIsComputed(): void
    {
        $tenantId = $this->tenant('immendingen')->id;
        // CREATED_AT liegt in der Vergangenheit: expires_at 30 Tage später ist abgelaufen.
        $expired = $this->createInvitationCode($tenantId, $this->admin);
        $exhausted = $this->createInvitationCode($tenantId, $this->admin, ['use_count' => 10, 'max_uses' => 10]);
        $revoked = $this->createInvitationCode($tenantId, $this->admin, ['revoked_at' => '2026-01-02 12:00:00']);

        $tenant = $this->tenant('immendingen');
        self::assertSame(InvitationCodeStatus::Expired, $this->codes->findByTenantAndId($tenant, $expired)?->status);
        self::assertSame(InvitationCodeStatus::Exhausted, $this->codes->findByTenantAndId($tenant, $exhausted)?->status);
        self::assertSame(InvitationCodeStatus::Revoked, $this->codes->findByTenantAndId($tenant, $revoked)?->status);
    }

    public function testRevokeIsRepeatableAndTenantScoped(): void
    {
        [$code] = $this->codes->create($this->tenant('immendingen'), $this->admin, null, 1, 1);

        self::assertNull($this->codes->revoke($this->tenant('tuttlingen'), $code->id));
        self::assertNull($this->codes->findByTenantAndId($this->tenant('immendingen'), $code->id)?->revokedAt);

        $revoked = $this->codes->revoke($this->tenant('immendingen'), $code->id);
        self::assertSame(InvitationCodeStatus::Revoked, $revoked?->status);
        $this->pdo()->exec('UPDATE fe_invitation_codes SET revoked_at = created_at');
        $again = $this->codes->revoke($this->tenant('immendingen'), $code->id);
        self::assertEquals($code->createdAt, $again?->revokedAt, 'Zeitpunkt des ersten Widerrufs bleibt');

        self::assertNull($this->codes->revoke($this->tenant('immendingen'), 999999));
    }

    public function testFindUsableId(): void
    {
        $tenant = $this->tenant('immendingen');
        [$code, $value] = $this->codes->create($tenant, $this->admin, null, 1, 2);

        self::assertSame($code->id, $this->codes->findUsableId($tenant, $value));
        self::assertNull($this->codes->findUsableId($this->tenant('tuttlingen'), $value), 'andere Stadt');
        self::assertNull($this->codes->findUsableId($tenant, InvitationCodeValue::generate()), 'unbekannt');

        $this->pdo()->exec('UPDATE fe_invitation_codes SET use_count = 2');
        self::assertNull($this->codes->findUsableId($tenant, $value), 'aufgebraucht');

        $this->pdo()->exec('UPDATE fe_invitation_codes SET use_count = 0, revoked_at = UTC_TIMESTAMP()');
        self::assertNull($this->codes->findUsableId($tenant, $value), 'widerrufen');

        $this->pdo()->exec('UPDATE fe_invitation_codes SET revoked_at = NULL, created_at = created_at - INTERVAL 2 DAY,'
            . ' expires_at = expires_at - INTERVAL 2 DAY');
        self::assertNull($this->codes->findUsableId($tenant, $value), 'abgelaufen');
    }

    public function testLockAndCountUse(): void
    {
        $tenant = $this->tenant('immendingen');
        [$code, $value] = $this->codes->create($tenant, $this->admin, null, 1, 1);

        $this->pdo()->beginTransaction();
        self::assertSame($code->id, $this->codes->lockUsableId($tenant, $value));
        $this->codes->countUse($code->id);
        $this->pdo()->commit();

        self::assertSame(InvitationCodeStatus::Exhausted, $this->codes->findByTenantAndId($tenant, $code->id)?->status);
        $this->expectException(\LogicException::class);
        $this->codes->countUse($code->id);
    }

    public function testLockNeedsTransaction(): void
    {
        $this->expectException(\LogicException::class);

        $this->codes->lockUsableId($this->tenant('immendingen'), InvitationCodeValue::generate());
    }
}
