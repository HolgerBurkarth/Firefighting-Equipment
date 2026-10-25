<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\User;

use FirefightingEquipment\Auth\OneTimeToken;
use FirefightingEquipment\Auth\TokenPurpose;
use FirefightingEquipment\Auth\UserTokenRepository;
use FirefightingEquipment\Device\DefaultDeviceTypes;
use FirefightingEquipment\Tenant\DuplicateTenantSlugException;
use FirefightingEquipment\Tenant\TenantName;
use FirefightingEquipment\Tenant\TenantRepository;
use FirefightingEquipment\Tenant\TenantSlug;
use FirefightingEquipment\Tenant\TenantSummary;
use FirefightingEquipment\Tests\Integration\Auth\AuthTestCase;
use FirefightingEquipment\Tests\Support\TestDatabase;
use FirefightingEquipment\User\AdminInvitation;
use FirefightingEquipment\User\DuplicateUserEmailException;
use FirefightingEquipment\User\EmailAddress;
use FirefightingEquipment\User\PersonName;
use FirefightingEquipment\User\User;
use FirefightingEquipment\User\UserRepository;
use FirefightingEquipment\User\UserRole;
use FirefightingEquipment\User\UserRuleViolationException;
use FirefightingEquipment\User\UserStatus;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Einladung als Admin im Repository (Stadt + Konto + Link atomar, erneuern, annehmen),
 * Übersicht der Städte mit Kontenzahlen und Sperren einer Stadt (beendet ihre Sitzungen).
 */
#[CoversClass(UserRepository::class)]
#[CoversClass(AdminInvitation::class)]
#[CoversClass(TenantRepository::class)]
#[CoversClass(TenantSummary::class)]
final class AdminInvitationRepositoryTest extends AuthTestCase
{
    private const int ER_LOCK_WAIT_TIMEOUT = 1205;

    private UserRepository $users;
    private UserTokenRepository $tokens;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->pdo());
        $this->tokens = new UserTokenRepository($this->pdo());
    }

    public function testCreateTenantWithInvitedAdmin(): void
    {
        $invitation = $this->users->createTenantWithInvitedAdmin(
            TenantSlug::fromString('geisingen'),
            TenantName::fromString('Feuerwehr Geisingen'),
            EmailAddress::fromString('Kommandant@Example.org'),
        );

        self::assertSame('geisingen', $invitation->tenant->slug->value);
        self::assertTrue($invitation->tenant->active);
        self::assertSame($invitation->tenant->id, $invitation->user->tenantId);
        self::assertSame(UserRole::Admin, $invitation->user->role);
        self::assertSame(UserStatus::Invited, $invitation->user->status);
        self::assertSame('kommandant@example.org', $invitation->user->email?->value);
        self::assertNull($invitation->user->name);
        self::assertSame($invitation->user->id, $this->tokens->findUserId(TokenPurpose::SetPassword, $invitation->token));
        self::assertSame(24, $this->tokenLifetimeHours($invitation->user->id), 'Link 24 h gültig');
        self::assertSame(
            count(DefaultDeviceTypes::NAMES),
            (int) $this->pdo()->query('SELECT COUNT(*) FROM fe_device_types WHERE tenant_id = ' . $invitation->tenant->id)->fetchColumn(),
            'Start-Gerätetypen',
        );
    }

    public function testDuplicateSlugCreatesNothing(): void
    {
        $users = $this->countRows('fe_users');

        try {
            $this->users->createTenantWithInvitedAdmin(
                TenantSlug::fromString('immendingen'),
                TenantName::fromString('Noch einmal'),
                EmailAddress::fromString('admin@example.org'),
            );
            self::fail('Doppeltes Kürzel wurde angenommen.');
        } catch (DuplicateTenantSlugException) {
        }

        self::assertSame(2, $this->countRows('fe_tenants'));
        self::assertSame($users, $this->countRows('fe_users'));
        self::assertSame(0, $this->countRows('fe_user_tokens'));
        self::assertFalse($this->pdo()->inTransaction());
    }

    public function testFailingAccountRollsBackTenant(): void
    {
        // Der letzte Schritt (Link) scheitert: Eine zweite Verbindung sperrt fe_user_tokens ganz,
        // das Anlegen des Links läuft nach 1 s in einen Lock-Wait-Timeout.
        $deviceTypes = $this->countRows('fe_device_types');
        $concurrent = TestDatabase::connect();
        $concurrent->beginTransaction();
        $concurrent->query('SELECT id FROM fe_user_tokens FOR UPDATE')->fetchAll();
        $this->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $this->users->createTenantWithInvitedAdmin(
                TenantSlug::fromString('geisingen'),
                TenantName::fromString('Feuerwehr Geisingen'),
                EmailAddress::fromString('admin@example.org'),
            );
            self::fail('Fehler beim Link wurde nicht gemeldet.');
        } catch (\PDOException $e) {
            self::assertSame(self::ER_LOCK_WAIT_TIMEOUT, $e->errorInfo[1] ?? null, $e->getMessage());
        } finally {
            $concurrent->rollBack();
            $this->pdo()->exec('SET SESSION innodb_lock_wait_timeout = DEFAULT');
        }
        self::assertFalse($this->pdo()->inTransaction());

        self::assertNull(new TenantRepository($this->pdo())->findBySlug(TenantSlug::fromString('geisingen')));
        self::assertSame($deviceTypes, $this->countRows('fe_device_types'), 'Start-Gerätetypen zurückgenommen');
        self::assertSame(0, (int) $this->pdo()->query("SELECT COUNT(*) FROM fe_users WHERE email = 'admin@example.org'")->fetchColumn());
    }

    public function testInviteAdminIntoExistingTenant(): void
    {
        $invitation = $this->users->inviteAdmin($this->tenant('immendingen'), EmailAddress::fromString('neu@example.org'));

        self::assertSame(UserStatus::Invited, $invitation->user->status);
        self::assertSame($invitation->user->id, $this->tokens->findUserId(TokenPurpose::SetPassword, $invitation->token));
    }

    public function testInviteAdminWithExistingEmailCreatesNothing(): void
    {
        $this->account('immendingen', 'max@example.org');
        $users = $this->countRows('fe_users');

        $this->expectException(DuplicateUserEmailException::class);
        try {
            $this->users->inviteAdmin($this->tenant('immendingen'), EmailAddress::fromString('max@example.org'));
        } finally {
            self::assertSame($users, $this->countRows('fe_users'));
            self::assertSame(0, $this->countRows('fe_user_tokens'));
        }
    }

    public function testRenewInvitationReplacesLink(): void
    {
        $invitation = $this->users->inviteAdmin($this->tenant('immendingen'), EmailAddress::fromString('neu@example.org'));

        $renewed = $this->users->renewInvitation($invitation->user->id);

        self::assertNull($this->tokens->findUserId(TokenPurpose::SetPassword, $invitation->token), 'alter Link ungültig');
        self::assertSame($invitation->user->id, $this->tokens->findUserId(TokenPurpose::SetPassword, $renewed));
        self::assertSame(1, $this->countRows('fe_user_tokens'));
    }

    public function testRenewInvitationOnlyForOpenUnlockedInvitation(): void
    {
        $active = $this->account('immendingen', 'max@example.org');
        $locked = $this->users->inviteAdmin($this->tenant('immendingen'), EmailAddress::fromString('neu@example.org'))->user->id;
        $this->users->lock($locked);

        foreach ([$active, $locked] as $id) {
            try {
                $this->users->renewInvitation($id);
                self::fail('Erneuern wurde angenommen.');
            } catch (UserRuleViolationException) {
            }
        }
        self::assertSame(1, $this->countRows('fe_user_tokens'), 'nur der ursprüngliche Link');
    }

    public function testAcceptInvitationActivatesAccountOnce(): void
    {
        $invitation = $this->users->inviteAdmin($this->tenant('immendingen'), EmailAddress::fromString('neu@example.org'));
        $name = PersonName::fromString('Erika Musterfrau');

        self::assertFalse($this->users->acceptInvitation($invitation->user->id, $name, self::passwordHash(), OneTimeToken::generate()->hash()), 'falscher Link');
        self::assertTrue($this->users->acceptInvitation($invitation->user->id, $name, self::passwordHash(), $invitation->token->hash()));
        self::assertFalse($this->users->acceptInvitation($invitation->user->id, $name, self::passwordHash(), $invitation->token->hash()), 'nur einmal');

        $user = $this->users->findById($invitation->user->id);
        self::assertSame(UserStatus::Active, $user?->status);
        self::assertSame(UserRole::Admin, $user->role);
        self::assertSame('Erika Musterfrau', $user->name?->value);
        self::assertSame('Erika Musterfrau', $user->displayName?->value);
        self::assertNotNull($user->emailVerifiedAt);
        self::assertSame(self::passwordHash(), $this->users->passwordHash($user->id));
        self::assertSame(0, $this->countRows('fe_user_tokens'));
    }

    public function testAcceptInvitationRefusesExpiredLinkAndLockedAccount(): void
    {
        $name = PersonName::fromString('Erika Musterfrau');
        $expired = $this->users->inviteAdmin($this->tenant('immendingen'), EmailAddress::fromString('alt@example.org'));
        $this->pdo()->prepare(
            'UPDATE fe_user_tokens SET created_at = created_at - INTERVAL 25 HOUR, expires_at = expires_at - INTERVAL 25 HOUR WHERE user_id = ?',
        )->execute([$expired->user->id]);
        $locked = $this->users->inviteAdmin($this->tenant('immendingen'), EmailAddress::fromString('gesperrt@example.org'));
        $this->users->lock($locked->user->id);

        self::assertFalse($this->users->acceptInvitation($expired->user->id, $name, self::passwordHash(), $expired->token->hash()));
        self::assertFalse($this->users->acceptInvitation($locked->user->id, $name, self::passwordHash(), $locked->token->hash()));
        self::assertSame(UserStatus::Invited, $this->users->findById($expired->user->id)?->status);
        self::assertSame(UserStatus::Invited, $this->users->findById($locked->user->id)?->status);
        self::assertSame(2, $this->countRows('fe_user_tokens'), 'Links bleiben unverändert');
    }

    public function testListActiveSuperAdmins(): void
    {
        $first = $this->account(null, 'chef@example.org');
        $this->account(null, 'gesperrt@example.org', ['locked_at' => self::CREATED_AT]);
        $second = $this->account(null, 'vertretung@example.org');
        $this->account('immendingen', 'admin@example.org', ['role' => 'admin']);

        self::assertSame(
            [$first, $second],
            array_map(static fn (User $u): int => $u->id, $this->users->listActiveSuperAdmins()),
        );
    }

    public function testSummariesCountAccounts(): void
    {
        $this->account('immendingen', 'admin@example.org', ['role' => 'admin']);
        $this->account('immendingen', 'admin-gesperrt@example.org', ['role' => 'admin', 'locked_at' => self::CREATED_AT]);
        $this->account('immendingen', 'max@example.org');
        $this->registeredAccount('immendingen', 'wartet@example.org', 'pending');
        $this->users->inviteAdmin($this->tenant('immendingen'), EmailAddress::fromString('neu@example.org'));
        $this->account(null, 'chef@example.org');

        $repository = new TenantRepository($this->pdo());
        $summaries = $repository->summaries();

        self::assertSame(['immendingen', 'tuttlingen'], array_map(static fn (TenantSummary $s): string => $s->tenant->slug->value, $summaries));
        // aktiv: admin, max, einlader-pending-wartet (Admin aus registeredAccount)
        self::assertSame([3, 2, 1], [$summaries[0]->activeUsers, $summaries[0]->activeAdmins, $summaries[0]->invitedAdmins]);
        self::assertSame([0, 0, 0], [$summaries[1]->activeUsers, $summaries[1]->activeAdmins, $summaries[1]->invitedAdmins]);
        self::assertSame(3, $repository->summary($this->tenant('immendingen')->id)?->activeUsers);
        self::assertNull($repository->summary(999_999));
    }

    public function testDeactivatingTenantEndsSessionsOfItsAccountsOnly(): void
    {
        $repository = new TenantRepository($this->pdo());
        $admin = $this->account('immendingen', 'admin@example.org', ['role' => 'admin']);
        $user = $this->account('immendingen', 'max@example.org');
        $other = $this->account('tuttlingen', 'max@example.org');
        $superAdmin = $this->account(null, 'chef@example.org');
        foreach ([$admin, $user, $user, $other, $superAdmin] as $id) {
            $this->createSession($id);
        }

        self::assertTrue($repository->setActive(TenantSlug::fromString('immendingen'), false));

        self::assertSame(0, $this->sessionCountOf($admin));
        self::assertSame(0, $this->sessionCountOf($user));
        self::assertSame(1, $this->sessionCountOf($other), 'andere Stadt unberührt');
        self::assertSame(1, $this->sessionCountOf($superAdmin));

        // Auch wenn die Stadt schon gesperrt ist (z. B. Sitzung kurz vor dem Sperren entstanden).
        $this->createSession($user);
        self::assertFalse($repository->setActive(TenantSlug::fromString('immendingen'), false));
        self::assertSame(0, $this->sessionCountOf($user));

        // Entsperren lässt Sitzungen unberührt.
        $this->createSession($user);
        self::assertTrue($repository->setActive(TenantSlug::fromString('immendingen'), true));
        self::assertSame(1, $this->sessionCountOf($user));
    }

    public function testSetActiveRefusesOpenTransaction(): void
    {
        $this->pdo()->beginTransaction();
        try {
            $this->expectException(\LogicException::class);
            new TenantRepository($this->pdo())->setActive(TenantSlug::fromString('immendingen'), false);
        } finally {
            $this->pdo()->rollBack();
        }
    }

    private function createSession(int $userId): void
    {
        $this->insert('fe_sessions', [
            'user_id' => $userId,
            'token_hash' => random_bytes(32),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        ]);
    }

    private function tokenLifetimeHours(int $userId): int
    {
        $stmt = $this->pdo()->prepare('SELECT TIMESTAMPDIFF(HOUR, created_at, expires_at) FROM fe_user_tokens WHERE user_id = ?');
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }
}
