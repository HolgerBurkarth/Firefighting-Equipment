<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\User;

use FirefightingEquipment\Auth\OneTimeToken;
use FirefightingEquipment\Auth\TokenPurpose;
use FirefightingEquipment\Auth\UserTokenRepository;
use FirefightingEquipment\Invitation\InvitationCodeNotUsableException;
use FirefightingEquipment\Invitation\InvitationCodeRepository;
use FirefightingEquipment\Invitation\InvitationCodeValue;
use FirefightingEquipment\Tests\Integration\Auth\AuthTestCase;
use FirefightingEquipment\User\EmailAddress;
use FirefightingEquipment\User\PersonName;
use FirefightingEquipment\User\Registration;
use FirefightingEquipment\User\User;
use FirefightingEquipment\User\UserRepository;
use FirefightingEquipment\User\UserRole;
use FirefightingEquipment\User\UserStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * UserRepository: register (Code-Nutzung atomar, vorhandene E-Mail), verifyEmail,
 * listActiveAdmins und anonymizeStaleUnverified.
 */
#[CoversClass(UserRepository::class)]
#[CoversClass(Registration::class)]
final class UserRegistrationRepositoryTest extends AuthTestCase
{
    private UserRepository $users;
    private InvitationCodeRepository $codes;
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->pdo());
        $this->codes = new InvitationCodeRepository($this->pdo());
        $this->admin = $this->account('immendingen', 'admin@example.org', ['role' => 'admin']);
    }

    public function testRegisterCreatesUnverifiedUserAndCountsUse(): void
    {
        [$code, $value] = $this->codes->create($this->tenant('immendingen'), $this->admin, null, 7, 2);

        $registration = $this->register($value, 'max@example.org');

        self::assertTrue($registration->created);
        $user = $registration->user;
        self::assertSame($this->tenant('immendingen')->id, $user->tenantId);
        self::assertSame(UserRole::User, $user->role);
        self::assertSame(UserStatus::Unverified, $user->status);
        self::assertSame('max@example.org', $user->email?->value);
        self::assertSame('Max Mustermann', $user->name?->value);
        self::assertNull($user->displayName);
        self::assertNull($user->emailVerifiedAt);
        self::assertSame(self::PASSWORD_HASH, $this->users->passwordHash($user->id));
        self::assertSame($code->id, (int) $this->pdo()->query('SELECT invitation_code_id FROM fe_users WHERE id = ' . $user->id)->fetchColumn());
        self::assertSame(1, $this->codes->findByTenantAndId($this->tenant('immendingen'), $code->id)?->useCount);
    }

    public function testExistingEmailCreatesNothingAndDoesNotCountUse(): void
    {
        [$code, $value] = $this->codes->create($this->tenant('immendingen'), $this->admin, null, 7, 1);
        $existing = $this->account('immendingen', 'max@example.org', ['status' => 'pending', 'invitation_code_id' => $this->createInvitationCode($this->tenant('immendingen')->id, $this->admin), 'display_name' => null]);
        $users = $this->countRows('fe_users');

        $registration = $this->register($value, 'max@example.org');

        self::assertFalse($registration->created);
        self::assertSame($existing, $registration->user->id);
        self::assertSame($users, $this->countRows('fe_users'));
        self::assertSame(0, $this->codes->findByTenantAndId($this->tenant('immendingen'), $code->id)?->useCount);
    }

    public function testSameEmailInOtherTenantIsSeparateAccount(): void
    {
        $this->account('tuttlingen', 'max@example.org');
        [, $value] = $this->codes->create($this->tenant('immendingen'), $this->admin, null, 7, 1);

        self::assertTrue($this->register($value, 'max@example.org')->created);
    }

    public function testCodeIsExhaustedAfterMaxUses(): void
    {
        [, $value] = $this->codes->create($this->tenant('immendingen'), $this->admin, null, 7, 1);
        $this->register($value, 'eins@example.org');
        $users = $this->countRows('fe_users');

        try {
            $this->register($value, 'zwei@example.org');
            self::fail('InvitationCodeNotUsableException erwartet.');
        } catch (InvitationCodeNotUsableException) {
            self::assertSame($users, $this->countRows('fe_users'));
            self::assertFalse($this->pdo()->inTransaction());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableCodes(): iterable
    {
        yield 'unbekannt' => ['unbekannt'];
        yield 'andere Stadt' => ['andere Stadt'];
        yield 'abgelaufen' => ['abgelaufen'];
        yield 'widerrufen' => ['widerrufen'];
    }

    /**
     * Auch wenn die E-Mail schon vergeben ist: der Code wird zuerst geprüft.
     */
    #[DataProvider('unusableCodes')]
    public function testUnusableCodeIsRejectedEvenForExistingEmail(string $case): void
    {
        $value = InvitationCodeValue::generate();
        $hash = $value->hash();
        match ($case) {
            'unbekannt' => null,
            'andere Stadt' => $this->createInvitationCode($this->tenant('tuttlingen')->id, $this->account('tuttlingen', 'a@example.org', ['role' => 'admin']), ['code_hash' => $hash, 'expires_at' => '2099-01-01 00:00:00', 'created_at' => '2098-12-31 00:00:00']),
            'abgelaufen' => $this->createInvitationCode($this->tenant('immendingen')->id, $this->admin, ['code_hash' => $hash]),
            'widerrufen' => $this->createInvitationCode($this->tenant('immendingen')->id, $this->admin, ['code_hash' => $hash, 'expires_at' => '2099-01-01 00:00:00', 'created_at' => '2098-12-31 00:00:00', 'revoked_at' => '2098-12-31 01:00:00']),
        };

        foreach (['neu@example.org', 'admin@example.org'] as $email) {
            try {
                $this->register($value, $email);
                self::fail('InvitationCodeNotUsableException erwartet.');
            } catch (InvitationCodeNotUsableException $e) {
                self::assertSame('Der Einladungscode ist ungültig, abgelaufen, aufgebraucht oder widerrufen.', $e->getMessage());
            }
        }
        self::assertSame(1, $this->countRows('fe_users WHERE tenant_id = ' . $this->tenant('immendingen')->id));
    }

    public function testStaleUnverifiedAccountsAreAnonymized(): void
    {
        $tenant = $this->tenant('immendingen');
        [$code, $value] = $this->codes->create($tenant, $this->admin, null, 7, 10);
        $stale = $this->register($value, 'alt@example.org')->user->id;
        $fresh = $this->register($value, 'neu@example.org')->user->id;
        $pendingOld = $this->register($value, 'wartet@example.org')->user->id;
        $this->pdo()->exec("UPDATE fe_users SET status = 'pending', email_verified_at = UTC_TIMESTAMP() WHERE id = " . $pendingOld);
        $this->insert('fe_user_tokens', ['user_id' => $stale, 'purpose' => 'verify_email', 'token_hash' => self::hash('t'), 'expires_at' => '2099-01-01 00:00:00', 'created_at' => '2098-12-31 12:00:00']);
        $this->age([$stale, $pendingOld], UserRepository::UNVERIFIED_RETENTION_DAYS * 86400 + 60);
        $this->age([$fresh], UserRepository::UNVERIFIED_RETENTION_DAYS * 86400 - 60);
        $foreignAdmin = $this->account('tuttlingen', 'admin@example.org', ['role' => 'admin']);
        [, $foreignValue] = $this->codes->create($this->tenant('tuttlingen'), $foreignAdmin, null, 7, 1);
        $foreign = $this->users->register($this->tenant('tuttlingen'), $foreignValue, EmailAddress::fromString('alt@example.org'), PersonName::fromString('Max Mustermann'), self::PASSWORD_HASH)->user->id;
        $this->age([$foreign], 30 * 86400);

        self::assertSame(1, $this->users->anonymizeStaleUnverified($tenant));

        $anonymized = $this->users->findById($stale);
        self::assertSame(UserStatus::Deleted, $anonymized?->status);
        self::assertNull($anonymized->email);
        self::assertNull($anonymized->name);
        self::assertNull($this->users->passwordHash($stale));
        self::assertSame(0, $this->countRows('fe_user_tokens'));
        self::assertSame(UserStatus::Unverified, $this->users->findById($fresh)?->status);
        self::assertSame(UserStatus::Pending, $this->users->findById($pendingOld)?->status);
        self::assertSame(UserStatus::Unverified, $this->users->findById($foreign)?->status, 'andere Stadt unberührt');
        self::assertSame(3, $this->codes->findByTenantAndId($tenant, $code->id)?->useCount, 'Nutzung nicht zurückgegeben');
        self::assertSame(0, $this->users->anonymizeStaleUnverified($tenant));

        self::assertTrue($this->register($value, 'alt@example.org')->created, 'Adresse wieder frei');
    }

    public function testVerifyEmailRedeemsLinkOnce(): void
    {
        [, $value] = $this->codes->create($this->tenant('immendingen'), $this->admin, null, 7, 1);
        $id = $this->register($value, 'max@example.org')->user->id;
        $token = new UserTokenRepository($this->pdo())->replace($id, TokenPurpose::VerifyEmail);

        self::assertFalse($this->users->verifyEmail($id, OneTimeToken::generate()->hash()), 'falscher Link');
        self::assertTrue($this->users->verifyEmail($id, $token->hash()));
        self::assertFalse($this->users->verifyEmail($id, $token->hash()), 'nur einmal');

        $user = $this->users->findById($id);
        self::assertSame(UserStatus::Pending, $user?->status);
        self::assertNotNull($user->emailVerifiedAt);
        self::assertSame(0, $this->countRows('fe_user_tokens'));
    }

    public function testVerifyEmailRefusesLockedAccount(): void
    {
        [, $value] = $this->codes->create($this->tenant('immendingen'), $this->admin, null, 7, 1);
        $id = $this->register($value, 'max@example.org')->user->id;
        $token = new UserTokenRepository($this->pdo())->replace($id, TokenPurpose::VerifyEmail);
        $this->users->lock($id);

        self::assertFalse($this->users->verifyEmail($id, $token->hash()));
        self::assertSame(UserStatus::Unverified, $this->users->findById($id)?->status);
        self::assertSame(1, $this->countRows('fe_user_tokens'), 'Link bleibt unverändert');
    }

    public function testListActiveAdmins(): void
    {
        $second = $this->account('immendingen', 'admin2@example.org', ['role' => 'admin']);
        $this->account('immendingen', 'gesperrt@example.org', ['role' => 'admin', 'locked_at' => self::CREATED_AT]);
        $this->account('immendingen', 'benutzer@example.org');
        $this->account('tuttlingen', 'admin@example.org', ['role' => 'admin']);

        self::assertSame(
            [$this->admin, $second],
            array_map(static fn (User $u): int => $u->id, $this->users->listActiveAdmins($this->tenant('immendingen'))),
        );
    }

    private function register(InvitationCodeValue $code, string $email): Registration
    {
        return $this->users->register(
            $this->tenant('immendingen'),
            $code,
            EmailAddress::fromString($email),
            PersonName::fromString('Max Mustermann'),
            self::PASSWORD_HASH,
        );
    }

    /**
     * @param list<int> $ids
     */
    private function age(array $ids, int $seconds): void
    {
        $this->pdo()->prepare('UPDATE fe_users SET created_at = created_at - INTERVAL ? SECOND WHERE id IN (' . implode(',', $ids) . ')')
            ->execute([$seconds]);
    }
}
