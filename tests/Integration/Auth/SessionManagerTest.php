<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Auth;

use FirefightingEquipment\Auth\AuthSession;
use FirefightingEquipment\Auth\Session;
use FirefightingEquipment\Auth\SessionClass;
use FirefightingEquipment\Auth\SessionLifetime;
use FirefightingEquipment\Auth\SessionManager;
use FirefightingEquipment\Auth\SessionRepository;
use FirefightingEquipment\Auth\SessionToken;
use FirefightingEquipment\Auth\StartedSession;
use FirefightingEquipment\Permission\Area;
use FirefightingEquipment\Permission\AreaLevel;
use FirefightingEquipment\Permission\PermissionRepository;
use FirefightingEquipment\Permission\Permissions;
use FirefightingEquipment\Tenant\Tenant;
use FirefightingEquipment\User\User;
use FirefightingEquipment\User\UserRepository;
use FirefightingEquipment\User\UserRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(SessionManager::class)]
#[CoversClass(SessionRepository::class)]
#[UsesClass(AuthSession::class)]
#[UsesClass(Session::class)]
#[UsesClass(SessionClass::class)]
#[UsesClass(PermissionRepository::class)]
#[UsesClass(Permissions::class)]
#[UsesClass(SessionLifetime::class)]
#[UsesClass(SessionToken::class)]
#[UsesClass(StartedSession::class)]
final class SessionManagerTest extends AuthTestCase
{
    private SessionManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = new SessionManager($this->pdo());
    }

    public function testStartStoresOnlyHashAndMaxDurationOfRole(): void
    {
        $user = $this->user($this->account('immendingen', 'admin@example.org', ['role' => 'admin']));

        $started = $this->manager->start($user, 'Firefox auf Windows');

        $row = $this->pdo()->query('SELECT * FROM fe_sessions')->fetch();
        self::assertSame($started->auth->session->id, (int) $row['id']);
        self::assertSame($started->token->hash(), $row['token_hash']);
        self::assertNotSame($started->token->cookieValue(), $row['token_hash']);
        self::assertSame('Firefox auf Windows', $row['device_label']);
        $duration = strtotime($row['expires_at'] . ' UTC') - strtotime($row['created_at'] . ' UTC');
        self::assertSame(30 * 86400, $duration, 'Rolle Admin: 30 Tage');
        self::assertSame($row['created_at'], $row['last_seen_at']);
        self::assertSame($user, $started->auth->user);
    }

    public function testResumeReturnsUserAndSession(): void
    {
        $id = $this->account('immendingen', 'user@example.org');
        $started = $this->manager->start($this->user($id), 'curl');

        $auth = $this->manager->resume($started->token, $this->tenant('immendingen'));

        self::assertNotNull($auth);
        self::assertSame($id, $auth->user->id);
        self::assertSame($started->auth->session->id, $auth->session->id);
        self::assertFalse($auth->permissions?->isAll());
        self::assertTrue($auth->permissions?->allows(Area::Search, AreaLevel::Read));
    }

    public function testRightsOfTheRoleComeWithTheSession(): void
    {
        $admin = $this->manager->start($this->user($this->account('immendingen', 'admin@example.org', ['role' => 'admin'])), 'curl');
        $superAdmin = $this->manager->start($this->user($this->account(null, 'chef@example.org')), 'curl');

        self::assertTrue($admin->auth->permissions?->isAll());
        self::assertTrue($this->manager->resume($admin->token, $this->tenant('immendingen'))?->permissions?->isAll());
        self::assertNull($superAdmin->auth->permissions);
        self::assertNull($this->manager->resume($superAdmin->token, null)?->permissions);
    }

    /**
     * Vorgabe Schritt 1.3b: Die Standard-Rollen behalten ihre Sitzungsdauer (Inaktivität /
     * Höchstdauer), Admin 7 / 30 Tage, Benutzer 30 / 90 Tage.
     *
     * @return iterable<string, array{string, int, int}>
     */
    public static function standardRoles(): iterable
    {
        yield 'Admin' => ['admin', 7, 30];
        yield 'Benutzer' => ['user', 30, 90];
    }

    #[DataProvider('standardRoles')]
    public function testStandardRolesKeepTheirDurations(string $role, int $idleDays, int $maxDays): void
    {
        $tenant = $this->tenant('immendingen');
        $started = $this->manager->start($this->user($this->account('immendingen', $role . '@example.org', ['role' => $role])), 'curl');
        $session = $started->auth->session;

        self::assertSame($maxDays * 86400, $session->expiresAt->getTimestamp() - $session->createdAt->getTimestamp());
        $this->ageSession($session->id, $idleDays * 86400 - 60);
        self::assertNotNull($this->manager->resume($started->token, $tenant));
        $this->ageSession($session->id, $idleDays * 86400 + 1);
        self::assertNull($this->manager->resume($started->token, $tenant), 'Inaktivität: ' . $idleDays . ' Tage');

        $started = $this->manager->start($this->user($this->account('immendingen', $role . '2@example.org', ['role' => $role])), 'curl');
        $this->ageSession($started->auth->session->id, $maxDays * 86400 - 60, keepLastSeen: true);
        self::assertNotNull($this->manager->resume($started->token, $tenant));
        $this->ageSession($started->auth->session->id, 61, keepLastSeen: true);
        self::assertNull($this->manager->resume($started->token, $tenant), 'Höchstdauer: ' . $maxDays . ' Tage');
    }

    public function testOwnRoleGetsTheClassOfItsRightsAndAStricterRoleAppliesImmediately(): void
    {
        $tenant = $this->tenant('immendingen');
        $roleId = $this->insert('fe_roles', ['tenant_id' => $tenant->id, 'name' => 'Geräteträger']);
        $this->insert('fe_role_areas', ['role_id' => $roleId, 'tenant_id' => $tenant->id, 'area' => 'search', 'level' => 'read']);
        $this->insert('fe_role_actions', ['role_id' => $roleId, 'tenant_id' => $tenant->id, 'action' => 'book.move']);
        $started = $this->manager->start($this->user($this->account('immendingen', 'traeger@example.org', ['role_id' => $roleId])), 'curl');
        $session = $started->auth->session;

        self::assertSame(SessionClass::Long, $started->auth->sessionClass());
        self::assertSame(90 * 86400, $session->expiresAt->getTimestamp() - $session->createdAt->getTimestamp());
        $this->ageSession($session->id, 31 * 86400, keepLastSeen: true);
        self::assertNotNull($this->manager->resume($started->token, $tenant), 'lang: nach 31 Tagen noch gültig');

        // Die Rolle darf nun Stammdaten ändern: kurze Sitzungen, die Höchstdauer gilt sofort.
        $this->insert('fe_role_areas', ['role_id' => $roleId, 'tenant_id' => $tenant->id, 'area' => 'devices', 'level' => 'write']);
        self::assertNull($this->manager->resume($started->token, $tenant), 'kurz: nach 31 Tagen abgelaufen');
        self::assertSame(0, $this->countRows('fe_sessions'));
    }

    public function testUnknownTokenIsRejected(): void
    {
        self::assertNull($this->manager->resume(SessionToken::generate(), $this->tenant('immendingen')));
    }

    public function testLastSeenIsWrittenAtMostEveryFiveMinutes(): void
    {
        $id = $this->account('immendingen', 'user@example.org');
        $started = $this->manager->start($this->user($id), 'curl');
        $sessionId = $started->auth->session->id;

        $this->ageSession($sessionId, SessionLifetime::TOUCH_INTERVAL_SECONDS - 10);
        $before = $this->lastSeen($sessionId);
        self::assertNotNull($this->manager->resume($started->token, $this->tenant('immendingen')));
        self::assertSame($before, $this->lastSeen($sessionId), 'nach weniger als 5 Minuten nicht schreiben');

        $this->ageSession($sessionId, 20);
        $auth = $this->manager->resume($started->token, $this->tenant('immendingen'));
        self::assertNotNull($auth);
        $now = (string) $this->pdo()->query('SELECT UTC_TIMESTAMP()')->fetchColumn();
        self::assertLessThanOrEqual(2, abs(strtotime($now) - strtotime($this->lastSeen($sessionId))));
        self::assertSame($this->lastSeen($sessionId), $auth->session->lastSeenAt->format('Y-m-d H:i:s'));
    }

    /**
     * Kontoart mit Standard-Rolle und Klasse ihrer Sitzungen.
     *
     * @return iterable<string, array{UserRole, SessionClass}>
     */
    public static function roles(): iterable
    {
        yield 'user' => [UserRole::User, SessionClass::Long];
        yield 'admin' => [UserRole::Admin, SessionClass::Short];
        yield 'superadmin' => [UserRole::SuperAdmin, SessionClass::SuperAdmin];
    }

    #[DataProvider('roles')]
    public function testInactivityLimitPerRole(UserRole $role, SessionClass $class): void
    {
        [$token, $sessionId, $tenant] = $this->sessionFor($role);

        $this->ageSession($sessionId, SessionLifetime::idleSeconds($class) - 60);
        self::assertNotNull($this->manager->resume($token, $tenant), 'kurz vor der Grenze gültig');

        // resume hat last_seen_at erneuert; erneut über die Grenze schieben.
        $this->ageSession($sessionId, SessionLifetime::idleSeconds($class) + 1);
        self::assertNull($this->manager->resume($token, $tenant));
        self::assertSame(0, $this->countRows('fe_sessions'), 'abgelaufene Sitzung wird gelöscht');
    }

    #[DataProvider('roles')]
    public function testMaximumDurationPerRole(UserRole $role, SessionClass $class): void
    {
        [$token, $sessionId, $tenant] = $this->sessionFor($role);

        $this->ageSession($sessionId, SessionLifetime::maxSeconds($class) - 60, keepLastSeen: true);
        self::assertNotNull($this->manager->resume($token, $tenant), 'kurz vor der Höchstdauer gültig');

        $this->ageSession($sessionId, 61, keepLastSeen: true);
        self::assertNull($this->manager->resume($token, $tenant), 'trotz Aktivität abgelaufen');
        self::assertSame(0, $this->countRows('fe_sessions'));
    }

    public function testSuperAdminSessionIsOnlyValidUnderService(): void
    {
        $started = $this->manager->start($this->user($this->account(null, 'chef@example.org')), 'curl');

        self::assertNull($this->manager->resume($started->token, $this->tenant('immendingen')));
        self::assertNotNull($this->manager->resume($started->token, null));
    }

    public function testTenantSessionIsOnlyValidUnderOwnTenant(): void
    {
        $started = $this->manager->start($this->user($this->account('immendingen', 'admin@example.org', ['role' => 'admin'])), 'curl');

        self::assertNull($this->manager->resume($started->token, $this->tenant('tuttlingen')));
        self::assertNull($this->manager->resume($started->token, null));
        self::assertNotNull($this->manager->resume($started->token, $this->tenant('immendingen')), 'nicht gelöscht');
    }

    public function testLockedOrInactiveAccountHasNoValidSession(): void
    {
        $id = $this->account('immendingen', 'user@example.org');
        $started = $this->manager->start($this->user($id), 'curl');

        $this->pdo()->exec("UPDATE fe_users SET locked_at = '2026-01-01 00:00:00' WHERE id = " . $id);

        self::assertNull($this->manager->resume($started->token, $this->tenant('immendingen')));
    }

    public function testEndDeletesSession(): void
    {
        $started = $this->manager->start($this->user($this->account('immendingen', 'user@example.org')), 'curl');

        $this->manager->end($started->token);

        self::assertSame(0, $this->countRows('fe_sessions'));
    }

    public function testDeleteExpiredUsesLimitsPerRole(): void
    {
        $repository = new SessionRepository($this->pdo());
        [, $superFresh] = $this->sessionFor(UserRole::SuperAdmin);
        [, $superIdle] = $this->sessionFor(UserRole::SuperAdmin);
        [, $adminIdle] = $this->sessionFor(UserRole::Admin);
        [, $userTwoHours] = $this->sessionFor(UserRole::User);
        [, $userOld] = $this->sessionFor(UserRole::User);

        $this->ageSession($superIdle, 3601);
        $this->ageSession($adminIdle, 7 * 86400 + 1);
        $this->ageSession($userTwoHours, 7200);
        $this->ageSession($userOld, 90 * 86400 + 1, keepLastSeen: true);

        self::assertSame(3, $repository->deleteExpired());
        $remaining = array_map('intval', $this->pdo()->query('SELECT id FROM fe_sessions ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
        self::assertSame([$superFresh, $userTwoHours], $remaining);
    }

    public function testListValidByUserSkipsExpiredAndSortsByLastSeen(): void
    {
        $repository = new SessionRepository($this->pdo());
        $user = $this->user($this->account('immendingen', 'admin@example.org', ['role' => 'admin']));
        $old = $this->manager->start($user, 'A')->auth->session->id;
        $new = $this->manager->start($user, 'B')->auth->session->id;
        $expired = $this->manager->start($user, 'C')->auth->session->id;
        $this->ageSession($old, 600);
        $this->ageSession($expired, 7 * 86400);

        $ids = array_map(static fn (Session $s): int => $s->id, $repository->listValidByUser($user->id, SessionClass::Short));

        self::assertSame([$new, $old], $ids);
    }

    /**
     * @return array{SessionToken, int, ?Tenant}
     */
    private function sessionFor(UserRole $role): array
    {
        static $counter = 0;
        $email = sprintf('konto%d@example.org', ++$counter);
        $id = $role === UserRole::SuperAdmin
            ? $this->account(null, $email)
            : $this->account('immendingen', $email, ['role' => $role->value]);
        $started = $this->manager->start($this->user($id), 'curl');

        return [
            $started->token,
            $started->auth->session->id,
            $role === UserRole::SuperAdmin ? null : $this->tenant('immendingen'),
        ];
    }

    private function user(int $id): User
    {
        return new UserRepository($this->pdo())->findById($id) ?? throw new \LogicException('Konto fehlt.');
    }

    private function lastSeen(int $sessionId): string
    {
        return (string) $this->pdo()->query('SELECT last_seen_at FROM fe_sessions WHERE id = ' . $sessionId)->fetchColumn();
    }
}
