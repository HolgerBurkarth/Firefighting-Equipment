<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Claude;

use FirefightingEquipment\Api\V1\Tenants\TenantUserClaudeHandler;
use FirefightingEquipment\Api\V1\Users\UserActionHandler;
use FirefightingEquipment\Api\V1\Users\UserJson;
use FirefightingEquipment\Claude\ClaudeAccess;
use FirefightingEquipment\Claude\ClaudeAvailability;
use FirefightingEquipment\Claude\ClaudeConnectionRepository;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Permission\Action;
use FirefightingEquipment\Permission\AreaLevel;
use FirefightingEquipment\Role\RoleDraft;
use FirefightingEquipment\Role\RoleRepository;
use FirefightingEquipment\Tenant\TenantAdministration;
use FirefightingEquipment\Tenant\TenantRepository;
use FirefightingEquipment\Tests\Integration\Auth\AuthTestCase;
use FirefightingEquipment\User\UserAdministration;
use FirefightingEquipment\User\UserRepository;
use FirefightingEquipment\User\UserRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Claude-Zugang im Backend (docs/PLAN-MCP.md, Schritt M1; docs/MCP-KONZEPT.md, Abschnitt 2): Zugang
 * setzen und Verbindungen trennen (F12), nur in der Test-Stadt, und dass jeder Weg, der die Sitzungen
 * eines Kontos beendet, auch seine Claude-Verbindungen und Codes löscht.
 */
#[CoversClass(ClaudeAccess::class)]
#[CoversClass(ClaudeAvailability::class)]
#[CoversClass(ClaudeConnectionRepository::class)]
#[CoversClass(UserActionHandler::class)]
#[CoversClass(TenantUserClaudeHandler::class)]
#[CoversClass(UserAdministration::class)]
#[CoversClass(TenantAdministration::class)]
#[CoversClass(UserRepository::class)]
#[CoversClass(TenantRepository::class)]
#[CoversClass(UserJson::class)]
final class ClaudeAccessTest extends AuthTestCase
{
    private const string CITY = 'test';

    private int $admin;
    private int $user;
    private string $cookie;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTenant(self::CITY);
        $this->admin = $this->account(self::CITY, 'admin@example.org', ['role' => 'admin']);
        $this->user = $this->account(self::CITY, 'erika@example.org');
        $this->cookie = $this->loginCookie(self::CITY, 'admin@example.org');
    }

    // ---- Admin der Stadt -------------------------------------------------------------------------

    public function testAdminSetsAccessForUserAndOffEndsConnections(): void
    {
        $response = $this->post($this->user, 'claude-access', ['access' => 'write']);
        self::assertSame(200, $response->statusCode(), $response->body());
        $user = self::json($response)['user'];
        self::assertSame('write', $user['claude_access']);
        self::assertSame('Ändern', $user['claude_access_label']);
        self::assertSame(['count' => 0, 'last_used_at' => null], $user['claude_connections']);

        $this->connect($this->user);
        $this->connect($this->user);
        $listed = array_values(array_filter(
            self::json($this->send('GET', self::url(self::CITY, 'users'), cookie: $this->cookie))['users'],
            fn (array $u): bool => $u['id'] === $this->user,
        ))[0];
        self::assertSame(2, $listed['claude_connections']['count']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $listed['claude_connections']['last_used_at']);

        // Ändern → Lesen: Verbindungen bleiben (wirken ab dem nächsten Aufruf nur lesend).
        self::assertSame('read', self::json($this->post($this->user, 'claude-access', ['access' => 'read']))['user']['claude_access']);
        self::assertSame(2, $this->connectionCount($this->user));
        self::assertSame(2, $this->codeCount($this->user));

        // Aus: alle Verbindungen und Codes weg.
        $off = self::json($this->post($this->user, 'claude-access', ['access' => 'off']))['user'];
        self::assertSame('off', $off['claude_access']);
        self::assertSame(['count' => 0, 'last_used_at' => null], $off['claude_connections']);
        self::assertSame(0, $this->connectionCount($this->user));
        self::assertSame(0, $this->codeCount($this->user));
        self::assertSame(1, $this->sessionCountOf($this->admin), 'Sitzungen bleiben');
    }

    public function testEndConnectionsKeepsAccess(): void
    {
        $this->post($this->user, 'claude-access', ['access' => 'read']);
        $this->connect($this->user);
        $other = $this->account(self::CITY, 'max@example.org');
        $this->setAccess($other, 'read');
        $this->connect($other);

        $response = $this->post($this->user, 'claude-connections/end');
        self::assertSame(200, $response->statusCode(), $response->body());
        self::assertSame('read', self::json($response)['user']['claude_access']);
        self::assertSame(0, $this->connectionCount($this->user));
        self::assertSame(1, $this->connectionCount($other), 'andere Konten unberührt');

        self::assertSame(200, $this->post($this->user, 'claude-connections/end')->statusCode(), 'wiederholbar');
    }

    public function testAccessForAdminsOwnAccountAndSuperAdminIsRejected(): void
    {
        $otherAdmin = $this->account(self::CITY, 'zweite@example.org', ['role' => 'admin']);

        foreach ([$otherAdmin, $this->admin] as $id) {
            $response = $this->post($id, 'claude-access', ['access' => 'read']);
            self::assertSame(409, $response->statusCode(), $response->body());
            self::assertSame('rule_violation', self::json($response)['error']);
        }
        self::assertStringContainsString('Super-Admin', self::json($this->post($otherAdmin, 'claude-access', ['access' => 'read']))['message']);
        self::assertStringContainsString('eigenen', self::json($this->post($this->admin, 'claude-access', ['access' => 'read']))['message']);
        self::assertSame(409, $this->post($otherAdmin, 'claude-connections/end')->statusCode());
        self::assertSame('off', $this->accessOf($otherAdmin));

        $super = $this->account(null, 'super@example.org');
        self::assertSame(404, $this->post($super, 'claude-access', ['access' => 'read'])->statusCode(), 'kein Konto der Stadt');
    }

    public function testOnlyTheAdminRoleSetsAccess(): void
    {
        $tenant = $this->tenant(self::CITY);
        $personal = new RoleRepository($this->pdo())->create($tenant, RoleDraft::of(
            'Personal',
            null,
            ['search' => AreaLevel::Read, 'users' => AreaLevel::Write],
            [Action::BookMove],
            null,
            null,
        ), $this->admin)->id;
        $this->account(self::CITY, 'personal@example.org', ['role_id' => $personal]);
        $cookie = $this->loginCookie(self::CITY, 'personal@example.org');

        foreach (['claude-access' => ['access' => 'read'], 'claude-connections/end' => []] as $action => $body) {
            $response = $this->send('POST', self::url(self::CITY, 'users/' . $this->user . '/' . $action), $body, $cookie);
            self::assertSame(409, $response->statusCode(), $response->body());
            self::assertSame('Den Claude-Zugang setzt nur ein Konto mit der Rolle Admin.', self::json($response)['message']);
        }
        self::assertSame('off', $this->accessOf($this->user));
    }

    public function testOnlyActiveAccountsGetAccess(): void
    {
        $pending = $this->registeredAccount(self::CITY, 'wartet@example.org', 'pending');

        $response = $this->post($pending, 'claude-access', ['access' => 'read']);
        self::assertSame(409, $response->statusCode(), $response->body());
        self::assertStringContainsString('nur für aktive Konten', self::json($response)['message']);
        self::assertSame(200, $this->post($pending, 'claude-access', ['access' => 'off'])->statusCode(), '„Aus“ geht immer');

        // Gesperrt: der Wert bleibt setzbar und erhalten (gilt als „kein Zugang“, solange gesperrt).
        $locked = $this->account(self::CITY, 'gesperrt@example.org', ['locked_at' => self::CREATED_AT]);
        self::assertSame('read', self::json($this->post($locked, 'claude-access', ['access' => 'read']))['user']['claude_access']);
    }

    public function testInvalidAccessIs400BeforeLookup(): void
    {
        foreach ([['access' => 'admin'], ['access' => 'READ'], ['access' => true], []] as $body) {
            $response = $this->post(999999, 'claude-access', $body);
            self::assertSame(400, $response->statusCode(), $response->body());
        }
        self::assertSame(404, $this->post(999999, 'claude-access', ['access' => 'read'])->statusCode());
    }

    public function testOnlyInTheTestCity(): void
    {
        $this->account('immendingen', 'chef@example.org', ['role' => 'admin']);
        $user = $this->account('immendingen', 'erika@example.org');
        $cookie = $this->loginCookie('immendingen', 'chef@example.org');

        foreach (['claude-access' => ['access' => 'read'], 'claude-connections/end' => []] as $action => $body) {
            $response = $this->send('POST', self::url('immendingen', 'users/' . $user . '/' . $action), $body, $cookie);
            self::assertSame(409, $response->statusCode(), $response->body());
            self::assertSame('Den Claude-Zugang gibt es bisher nur in der Test-Stadt.', self::json($response)['message']);
        }
        self::assertSame('off', $this->accessOf($user));

        self::assertTrue(self::json($this->send('GET', self::url(self::CITY, 'tenant')))['claude_available']);
        self::assertFalse(self::json($this->send('GET', self::url('immendingen', 'tenant')))['claude_available']);
    }

    // ---- Super-Admin -----------------------------------------------------------------------------

    public function testSuperAdminSetsAccessOnlyForAdminsAndEndsAnyConnections(): void
    {
        $this->account(null, 'super@example.org');
        $cookie = $this->loginCookie(null, 'super@example.org');
        $tenantId = $this->tenant(self::CITY)->id;
        $path = fn (int $user, string $action): string => self::url(null, sprintf('tenants/%d/users/%d/%s', $tenantId, $user, $action));

        $response = $this->send('POST', $path($this->admin, 'claude-access'), ['access' => 'read'], $cookie);
        self::assertSame(200, $response->statusCode(), $response->body());
        self::assertSame('read', self::json($response)['user']['claude_access']);

        $response = $this->send('POST', $path($this->user, 'claude-access'), ['access' => 'read'], $cookie);
        self::assertSame(409, $response->statusCode(), $response->body());
        self::assertSame('off', $this->accessOf($this->user));

        $this->setAccess($this->user, 'write');
        $this->connect($this->user);
        $this->connect($this->admin);
        foreach ([$this->user, $this->admin] as $id) {
            $response = $this->send('POST', $path($id, 'claude-connections/end'), [], $cookie);
            self::assertSame(200, $response->statusCode(), $response->body());
            self::assertSame(0, $this->connectionCount($id));
        }

        $other = $this->account('tuttlingen', 'chef@example.org', ['role' => 'admin']);
        self::assertSame(404, $this->send('POST', $path($other, 'claude-access'), ['access' => 'read'], $cookie)->statusCode(), 'Konto einer anderen Stadt');
        $immendingen = $this->account('immendingen', 'chef@example.org', ['role' => 'admin']);
        $response = $this->send('POST', self::url(null, sprintf('tenants/%d/users/%d/claude-access', $this->tenant('immendingen')->id, $immendingen)), ['access' => 'read'], $cookie);
        self::assertSame(409, $response->statusCode(), 'nur in der Test-Stadt');
        self::assertSame(400, $this->send('POST', $path($this->admin, 'claude-access'), ['access' => 'x'], $cookie)->statusCode());
    }

    // ---- Verbindungen enden mit den Sitzungen ----------------------------------------------------

    /**
     * @return iterable<string, array{\Closure(UserRepository, int, self): mixed, bool}>
     */
    public static function endingPaths(): iterable
    {
        yield 'sperren' => [static fn (UserRepository $r, int $id): bool => $r->lock($id), true];
        yield 'Passwort setzen' => [static fn (UserRepository $r, int $id): mixed => $r->setPassword($id, self::passwordHash()), true];
        yield 'anonymisieren' => [static fn (UserRepository $r, int $id): bool => $r->anonymize($id), true];
        yield 'Wechsel zu Admin' => [static fn (UserRepository $r, int $id): bool => $r->changeRole($id, UserRole::Admin), true];
        yield 'entsperren' => [static fn (UserRepository $r, int $id): bool => $r->unlock($id), false];
        yield 'Anzeigename' => [static fn (UserRepository $r, int $id): bool => $r->changeDisplayName($id, \FirefightingEquipment\User\PersonName::fromString('Neu')), false];
    }

    /**
     * @param \Closure(UserRepository, int, self): mixed $change
     */
    #[DataProvider('endingPaths')]
    public function testConnectionsEndWithSessions(\Closure $change, bool $ends): void
    {
        $this->setAccess($this->user, 'write');
        $this->connect($this->user);
        $other = $this->account(self::CITY, 'max@example.org');
        $this->setAccess($other, 'write');
        $this->connect($other);

        $change(new UserRepository($this->pdo()), $this->user, $this);

        self::assertSame($ends ? 0 : 1, $this->connectionCount($this->user));
        self::assertSame($ends ? 0 : 1, $this->codeCount($this->user));
        self::assertSame(1, $this->connectionCount($other), 'andere Konten unberührt');
    }

    public function testRoleChangeToOrFromAdminTurnsAccessOffOtherChangesKeepIt(): void
    {
        $repository = new UserRepository($this->pdo());
        $tenant = $this->tenant(self::CITY);
        $wart = new RoleRepository($this->pdo())->create($tenant, RoleDraft::of(
            'Gerätewart',
            null,
            ['search' => AreaLevel::Read, 'devices' => AreaLevel::Write],
            [Action::BookMove],
            null,
            null,
        ), $this->admin)->id;

        $this->setAccess($this->user, 'write');
        $this->connect($this->user);
        $repository->assignRole($this->user, $wart);
        self::assertSame('write', $this->accessOf($this->user), 'Benutzer → Gerätewart: Zugang bleibt');
        self::assertSame(1, $this->connectionCount($this->user));

        $repository->changeRole($this->user, UserRole::Admin);
        self::assertSame('off', $this->accessOf($this->user), 'zu Admin: aus');
        self::assertSame(0, $this->connectionCount($this->user));

        $repository->setClaudeAccess($this->user, ClaudeAccess::Read);
        $this->connect($this->user);
        $repository->changeRole($this->user, UserRole::User);
        self::assertSame('off', $this->accessOf($this->user), 'von Admin: aus');
        self::assertSame(0, $this->connectionCount($this->user));
    }

    public function testAnonymizedAccountLosesAccess(): void
    {
        $this->setAccess($this->user, 'read');
        new UserRepository($this->pdo())->anonymize($this->user);

        self::assertSame('off', $this->accessOf($this->user));
    }

    public function testLockingTheTenantEndsAllItsConnections(): void
    {
        $this->setAccess($this->user, 'write');
        $this->connect($this->user);
        $this->connect($this->admin);
        $this->createTenant('nachbar');
        $neighbour = $this->account('nachbar', 'nachbar@example.org');
        $this->connect($neighbour, 'nachbar');

        new TenantRepository($this->pdo())->setActive($this->tenant(self::CITY)->slug, false);

        self::assertSame(0, $this->connectionCount($this->user));
        self::assertSame(0, $this->connectionCount($this->admin));
        self::assertSame(0, $this->codeCount($this->user));
        self::assertSame(1, $this->connectionCount($neighbour), 'andere Stadt unberührt');
        self::assertSame('write', $this->accessOf($this->user), 'der Zugang bleibt');
    }

    // ---- Datenbank -------------------------------------------------------------------------------

    public function testDatabaseRejectsAccessForSuperAdminsAndDeletedAccounts(): void
    {
        $super = $this->account(null, 'super@example.org');
        $this->expectViolation(fn () => $this->setAccess($super, 'read'));

        $this->setAccess($this->user, 'read');
        $this->expectViolation(fn () => $this->pdo()->prepare("UPDATE fe_users SET status = 'deleted' WHERE id = ?")->execute([$this->user]));
        $this->expectViolation(fn () => $this->setAccess($this->user, 'all'));
        self::assertSame('read', $this->accessOf($this->user));
    }

    public function testRepositoryRejectsSuperAdmins(): void
    {
        $super = $this->account(null, 'super@example.org');

        $this->expectException(\FirefightingEquipment\User\UserRuleViolationException::class);
        new UserRepository($this->pdo())->setClaudeAccess($super, ClaudeAccess::Read);
    }

    // ---- Hilfen ----------------------------------------------------------------------------------

    /**
     * Eine Verbindung samt Registrierung und einem offenen Code (wie später M3 sie anlegt).
     */
    private function connect(int $userId, string $city = self::CITY): void
    {
        $tenantId = $this->tenant($city)->id;
        $clientId = $this->insert('fe_oauth_clients', [
            'tenant_id' => $tenantId,
            'client_id' => substr(rtrim(strtr(base64_encode(random_bytes(33)), '+/', '-_'), '='), 0, 43),
            'client_name' => 'Claude',
            'redirect_uris' => '["https://claude.ai/api/mcp/auth_callback"]',
        ]);
        $this->pdo()->prepare(
            'INSERT INTO fe_claude_connections (tenant_id, user_id, oauth_client_id, access, account_kind, refresh_hash,'
            . " created_at, last_seen_at, expires_at) VALUES (?, ?, ?, 'read', 'user', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(),"
            . ' UTC_TIMESTAMP() + INTERVAL 30 DAY)',
        )->execute([$tenantId, $userId, $clientId, random_bytes(32)]);
        $this->pdo()->prepare(
            'INSERT INTO fe_oauth_codes (tenant_id, user_id, oauth_client_id, code_hash, redirect_uri, code_challenge,'
            . " access, created_at, expires_at) VALUES (?, ?, ?, ?, 'https://claude.ai/api/mcp/auth_callback', ?, 'read',"
            . ' UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL 5 MINUTE)',
        )->execute([$tenantId, $userId, $clientId, random_bytes(32), str_repeat('A', 43)]);
    }

    private function setAccess(int $userId, string $access): void
    {
        $this->pdo()->prepare('UPDATE fe_users SET claude_access = ? WHERE id = ?')->execute([$access, $userId]);
    }

    private function accessOf(int $userId): string
    {
        $stmt = $this->pdo()->prepare('SELECT claude_access FROM fe_users WHERE id = ?');
        $stmt->execute([$userId]);

        return (string) $stmt->fetchColumn();
    }

    private function connectionCount(int $userId): int
    {
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM fe_claude_connections WHERE user_id = ?');
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }

    private function codeCount(int $userId): int
    {
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM fe_oauth_codes WHERE user_id = ?');
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }

    private function expectViolation(\Closure $work): void
    {
        try {
            $work();
            self::fail('Die Datenbank hätte ablehnen müssen.');
        } catch (\PDOException $e) {
            self::assertSame('23000', $e->getCode(), $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(int $id, string $action, array $body = []): JsonResponse
    {
        return $this->send('POST', self::url(self::CITY, 'users/' . $id . '/' . $action), $body, $this->cookie);
    }
}
