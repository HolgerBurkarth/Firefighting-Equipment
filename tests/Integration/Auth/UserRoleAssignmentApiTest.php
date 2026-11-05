<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Auth;

use FirefightingEquipment\Api\V1\Invitation\InvitationCodeCreateHandler;
use FirefightingEquipment\Api\V1\Users\AssignableRoleListHandler;
use FirefightingEquipment\Api\V1\Users\UserActionHandler;
use FirefightingEquipment\Api\V1\Users\UserBulkRoleHandler;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Permission\Action;
use FirefightingEquipment\Permission\AreaLevel;
use FirefightingEquipment\Role\RoleDraft;
use FirefightingEquipment\Role\RoleRepository;
use FirefightingEquipment\User\EmailAddress;
use FirefightingEquipment\User\UserAdministration;
use FirefightingEquipment\User\UserRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Rolle zuweisen durch den Admin (Plan Phase 1, Schritt 1.6): GET roles, POST users/{id}/role und
 * approve mit role_id, POST users/bulk/role, Einladungscodes mit Rolle, Registrierung mit der Rolle
 * des Codes; Schutzregeln F5 mit einer Rolle, die Benutzer verwalten darf, ohne Admin zu sein; F1
 * (Sitzungen enden nur beim Wechsel zu oder von Admin).
 */
#[CoversClass(AssignableRoleListHandler::class)]
#[CoversClass(UserActionHandler::class)]
#[CoversClass(UserBulkRoleHandler::class)]
#[CoversClass(InvitationCodeCreateHandler::class)]
#[CoversClass(UserAdministration::class)]
#[CoversClass(UserRepository::class)]
final class UserRoleAssignmentApiTest extends AuthTestCase
{
    private int $admin;
    private string $cookie;
    private int $chef;
    private int $adminRole;
    private int $userRole;
    /** Darf mehr als Benutzer (Ausrüstung ändern), aber keine Benutzer verwalten. */
    private int $wart;
    /** Verwaltet Benutzer und hat die Rechte von Benutzer, ist aber nicht Admin. */
    private int $personal;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = $this->tenant('immendingen');
        $this->chef = $this->account(null, 'chef@example.org');
        $this->admin = $this->account('immendingen', 'admin@example.org', ['role' => 'admin', 'display_name' => 'Wehrführer']);
        $this->cookie = $this->loginCookie('immendingen', 'admin@example.org');
        $this->adminRole = (int) $this->systemRoleId($tenant->id, 'admin');
        $this->userRole = (int) $this->systemRoleId($tenant->id, 'user');
        $roles = new RoleRepository($this->pdo());
        $this->wart = $roles->create($tenant, RoleDraft::of(
            'Gerätewart',
            'Pflegt die Ausrüstung',
            ['search' => AreaLevel::Read, 'devices' => AreaLevel::Write, 'due' => AreaLevel::Write],
            [Action::BookMove, Action::BookStatus, Action::DeviceEdit, Action::MailLog],
            null,
            null,
        ), $this->chef)->id;
        $this->personal = $roles->create($tenant, RoleDraft::of(
            'Personal',
            null,
            ['search' => AreaLevel::Read, 'due' => AreaLevel::Read, 'users' => AreaLevel::Write],
            [Action::BookMove, Action::BookStatus],
            null,
            null,
        ), $this->chef)->id;
    }

    // ---- GET roles -----------------------------------------------------------------------------

    public function testRolesForSelectionWithCatalog(): void
    {
        $this->account('immendingen', 'wart@example.org', ['role_id' => $this->wart]);

        $response = $this->send('GET', self::url('immendingen', 'roles'), cookie: $this->cookie);

        self::assertSame(200, $response->statusCode(), $response->body());
        $json = self::json($response);
        self::assertSame(['Admin', 'Benutzer', 'Gerätewart', 'Personal'], array_column($json['roles'], 'name'));
        self::assertSame([true, true, true, true], array_column($json['roles'], 'assignable'));
        self::assertSame([null, null, null, null], array_column($json['roles'], 'assign_blocker'));
        $wart = $json['roles'][2];
        self::assertSame(['search' => 'read', 'devices' => 'write', 'due' => 'write'], $wart['areas']);
        self::assertSame(['accounts' => 1, 'invitation_codes' => 0], $wart['usage']);
        self::assertTrue($json['roles'][0]['all_rights']);
        self::assertNotEmpty($json['catalog']['areas']);
        self::assertStringNotContainsString('tuttlingen', $response->body());
    }

    public function testRolesShowWhatAManagerWithoutAdminMayAssign(): void
    {
        $roles = self::json($this->send('GET', self::url('immendingen', 'roles'), cookie: $this->managerCookie()))['roles'];

        self::assertSame([false, true, false, true], array_column($roles, 'assignable'));
        self::assertSame('Die Rolle „Admin“ vergeben und entziehen nur Admins.', $roles[0]['assign_blocker']);
        self::assertSame(
            'Die Rolle „Gerätewart“ hat Rechte, die Ihre eigene Rolle nicht hat; Sie können sie daher nicht vergeben.',
            $roles[2]['assign_blocker'],
        );
    }

    // ---- Einzeln zuweisen ----------------------------------------------------------------------

    /** F1: Ein Wechsel zwischen Rollen ohne Admin lässt die Sitzungen bestehen und wirkt sofort. */
    public function testOtherRoleKeepsSessionsAndAppliesOnNextRequest(): void
    {
        $id = $this->account('immendingen', 'user@example.org');
        $userCookie = $this->loginCookie('immendingen', 'user@example.org');
        $before = self::json($this->send('GET', self::url('immendingen', 'auth/me'), cookie: $userCookie))['permissions']['version'];

        $response = $this->post($id, 'role', ['role_id' => $this->wart]);

        self::assertSame(200, $response->statusCode(), $response->body());
        $user = self::json($response)['user'];
        self::assertSame([$this->wart, 'Gerätewart', 'user'], [$user['role_id'], $user['role_name'], $user['role']]);
        self::assertSame(1, $this->sessionCountOf($id), 'F1: Sitzung bleibt');
        $me = self::json($this->send('GET', self::url('immendingen', 'auth/me'), cookie: $userCookie))['permissions'];
        self::assertNotSame($before, $me['version']);
        self::assertSame(['search' => 'read', 'devices' => 'write', 'due' => 'write'], $me['areas']);
        self::assertSame(200, $this->post($id, 'role', ['role_id' => $this->wart])->statusCode(), 'wiederholbar');
    }

    /** F1: Kommt Admin dazu oder fällt weg, enden die Sitzungen; die Kontoart folgt der Rolle. */
    public function testAdminRoleChangesAccountKindAndEndsSessions(): void
    {
        $id = $this->account('immendingen', 'user@example.org', ['role_id' => $this->wart]);
        $this->loginCookie('immendingen', 'user@example.org');

        $toAdmin = self::json($this->post($id, 'role', ['role_id' => $this->adminRole]))['user'];
        self::assertSame(['admin', 'Admin'], [$toAdmin['role'], $toAdmin['role_name']]);
        self::assertSame(0, $this->sessionCountOf($id));

        $this->loginCookie('immendingen', 'user@example.org');
        $back = self::json($this->post($id, 'role', ['role_id' => $this->wart]))['user'];
        self::assertSame(['user', $this->wart], [$back['role'], $back['role_id']]);
        self::assertSame(0, $this->sessionCountOf($id));
    }

    /** Ohne mail.log in der neuen Rolle geht die Benachrichtigung „Log“ aus, mit ihr bleibt sie. */
    public function testLogNotificationFollowsMailLogOfNewRole(): void
    {
        $id = $this->account('immendingen', 'admin2@example.org', ['role' => 'admin', 'notify_device_log' => 1]);
        $notify = fn (): bool => (bool) new UserRepository($this->pdo())->findById($id)?->notifyDeviceLog;

        $this->post($id, 'role', ['role_id' => $this->wart]);
        self::assertTrue($notify(), 'Gerätewart hat mail.log');

        $this->post($id, 'role', ['role_id' => $this->personal]);
        self::assertFalse($notify());
    }

    public function testOwnRoleCannotBeChanged(): void
    {
        $this->account('immendingen', 'admin2@example.org', ['role' => 'admin']);

        $response = $this->post($this->admin, 'role', ['role_id' => $this->wart]);

        self::assertError(409, 'rule_violation', $response);
        self::assertSame('Sie können Ihre eigene Rolle nicht ändern.', self::json($response)['message']);
        self::assertSame(200, $this->post($this->admin, 'role', ['role_id' => $this->adminRole])->statusCode(), 'unverändert');
    }

    public function testUnknownOrForeignRoleIs400(): void
    {
        $id = $this->account('immendingen', 'user@example.org');
        $foreign = (int) $this->systemRoleId($this->tenant('tuttlingen')->id, 'user');

        foreach ([999999, $foreign] as $roleId) {
            $response = $this->post($id, 'role', ['role_id' => $roleId]);
            self::assertError(400, 'invalid_request', $response);
            self::assertSame(sprintf('Die Rolle #%d gibt es nicht. (Feld "role_id")', $roleId), self::json($response)['message']);
        }
        self::assertSame($this->userRole, new UserRepository($this->pdo())->findById($id)?->roleId);
    }

    public function testApproveWithRoleOfCode(): void
    {
        $id = $this->registeredAccount('immendingen', 'neu@example.org', 'pending');

        $response = $this->post($id, 'approve', ['display_name' => 'Neu', 'role_id' => $this->wart]);

        self::assertSame(200, $response->statusCode(), $response->body());
        $user = self::json($response)['user'];
        self::assertSame(['active', 'user', $this->wart], [$user['status'], $user['role'], $user['role_id']]);
        self::assertSame('admin', self::json($this->post(
            $this->registeredAccount('immendingen', 'zwei@example.org', 'pending'),
            'approve',
            ['display_name' => 'Zwei', 'role_id' => $this->adminRole],
        ))['user']['role']);
    }

    // ---- Schutzregeln F5 -----------------------------------------------------------------------

    /**
     * @return iterable<string, array{string, string, array<string, mixed>, string}>
     */
    public static function managerViolations(): iterable
    {
        $adminOnly = 'Konten mit der Rolle „Admin“ ändern, sperren und löschen nur Admins.';
        yield 'Admin vergeben' => ['user', 'role', ['role_id' => '{admin-role}'], 'Die Rolle „Admin“ vergeben und entziehen nur Admins.'];
        yield 'Admin entziehen' => ['admin', 'role', ['role_id' => '{user-role}'], $adminOnly];
        yield 'Admin sperren' => ['admin', 'lock', [], $adminOnly];
        yield 'Admin entsperren' => ['admin', 'unlock', [], $adminOnly];
        yield 'Anzeigename eines Admins' => ['admin', 'display-name', ['display_name' => 'X'], $adminOnly];
        yield 'Admin löschen' => ['admin', 'delete', [], $adminOnly];
        yield 'Rolle mit mehr Rechten vergeben' => ['user', 'role', ['role_id' => '{wart}'],
            'Die Rolle „Gerätewart“ hat Rechte, die Ihre eigene Rolle nicht hat; Sie können sie daher nicht vergeben.'];
        yield 'Konto mit mehr Rechten herabstufen' => ['wart', 'role', ['role_id' => '{user-role}'],
            'Das Konto hat die Rolle „Gerätewart“ mit Rechten, die Ihre eigene Rolle nicht hat; Sie können es daher nicht ändern.'];
        yield 'Konto mit mehr Rechten sperren' => ['wart', 'lock', [],
            'Das Konto hat die Rolle „Gerätewart“ mit Rechten, die Ihre eigene Rolle nicht hat; Sie können es daher nicht ändern.'];
        yield 'als Admin freischalten' => ['pending', 'approve', ['display_name' => 'Neu', 'role_id' => '{admin-role}'],
            'Die Rolle „Admin“ vergeben und entziehen nur Admins.'];
        yield 'sich selbst eine Rolle geben' => ['self', 'role', ['role_id' => '{user-role}'], 'Sie können Ihre eigene Rolle nicht ändern.'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('managerViolations')]
    public function testManagerWithoutAdminRoleIsLimited(string $target, string $action, array $body, string $message): void
    {
        $cookie = $this->managerCookie();
        $id = match ($target) {
            'user' => $this->account('immendingen', 'user@example.org'),
            'admin' => $this->account('immendingen', 'admin2@example.org', ['role' => 'admin', 'locked_at' => $action === 'unlock' ? self::CREATED_AT : null]),
            'wart' => $this->account('immendingen', 'wart@example.org', ['role_id' => $this->wart]),
            'pending' => $this->registeredAccount('immendingen', 'neu@example.org', 'pending'),
            'self' => $this->userIdOf('personal@example.org'),
        };
        $body = array_map(fn (mixed $value): mixed => match ($value) {
            '{admin-role}' => $this->adminRole,
            '{user-role}' => $this->userRole,
            '{wart}' => $this->wart,
            default => $value,
        }, $body);
        $before = $this->pdo()->query('SELECT * FROM fe_users ORDER BY id')->fetchAll();

        $response = $action === 'delete'
            ? $this->send('DELETE', self::url('immendingen', 'users/' . $id), [], $cookie)
            : $this->send('POST', self::url('immendingen', 'users/' . $id . '/' . $action), $body, $cookie);

        self::assertError(409, 'rule_violation', $response);
        self::assertSame($message, self::json($response)['message']);
        self::assertSame($before, $this->pdo()->query('SELECT * FROM fe_users ORDER BY id')->fetchAll(), 'nichts geändert');
    }

    public function testManagerMayManageAccountsWithinOwnRights(): void
    {
        $cookie = $this->managerCookie();
        $id = $this->account('immendingen', 'user@example.org');
        $pending = $this->registeredAccount('immendingen', 'neu@example.org', 'pending');

        self::assertSame($this->personal, self::json($this->send('POST', self::url('immendingen', 'users/' . $id . '/role'), ['role_id' => $this->personal], $cookie))['user']['role_id']);
        self::assertTrue(self::json($this->send('POST', self::url('immendingen', 'users/' . $id . '/lock'), [], $cookie))['user']['locked']);
        self::assertSame('active', self::json($this->send('POST', self::url('immendingen', 'users/' . $pending . '/approve'), ['display_name' => 'Neu', 'role_id' => $this->userRole], $cookie))['user']['status']);
        $code = $this->send('POST', self::url('immendingen', 'invitation-codes'), ['valid_days' => 7, 'max_uses' => 5, 'role_id' => $this->userRole], $cookie);
        self::assertSame(201, $code->statusCode(), $code->body());
        self::assertError(409, 'rule_violation', $this->send('POST', self::url('immendingen', 'invitation-codes'), ['valid_days' => 7, 'max_uses' => 5, 'role_id' => $this->wart], $cookie));
    }

    // ---- Mehrere Konten ------------------------------------------------------------------------

    public function testBulkAssignsEachAccountWithItsOwnResult(): void
    {
        $user = $this->account('immendingen', 'user@example.org');
        $already = $this->account('immendingen', 'wart@example.org', ['role_id' => $this->wart]);
        $deleted = $this->account('immendingen', 'weg@example.org');
        new UserRepository($this->pdo())->anonymize($deleted);
        $foreign = $this->account('tuttlingen', 'fremd@example.org');
        $this->loginCookie('immendingen', 'user@example.org');

        $response = $this->bulk(['user_ids' => [$user, $already, $this->admin, $deleted, $foreign, 999999], 'role_id' => $this->wart]);

        self::assertSame(200, $response->statusCode(), $response->body());
        $json = self::json($response);
        self::assertSame(['total' => 6, 'applied' => 1, 'unchanged' => 1, 'errors' => 4], $json['summary']);
        self::assertSame([$user, $already, $this->admin, $deleted, $foreign, 999999], array_column($json['results'], 'id'));
        self::assertSame(['applied', 'unchanged', 'error', 'error', 'error', 'error'], array_column($json['results'], 'outcome'));
        self::assertSame($this->wart, $json['results'][0]['user']['role_id']);
        self::assertNull($json['results'][0]['error']);
        self::assertSame(1, $this->sessionCountOf($user), 'F1');
        self::assertSame(['error' => 'rule_violation', 'message' => 'Sie können Ihre eigene Rolle nicht ändern.'], $json['results'][2]['error']);
        self::assertSame('admin', $json['results'][2]['user']['role'], 'aktueller Stand');
        self::assertSame('rule_violation', $json['results'][3]['error']['error'], 'gelöscht');
        self::assertSame([null, null], [$json['results'][4]['user'], $json['results'][5]['user']]);
        self::assertSame(['not_found', 'not_found'], [$json['results'][4]['error']['error'], $json['results'][5]['error']['error']]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidBulkRequests(): iterable
    {
        yield 'leer' => [['user_ids' => [], 'role_id' => '{wart}'], 'Das Feld "user_ids" muss eine Liste mit 1 bis 50 Konten sein.'];
        yield 'zu viele' => [['user_ids' => range(1, 51), 'role_id' => '{wart}'], 'Das Feld "user_ids" muss eine Liste mit 1 bis 50 Konten sein.'];
        yield 'kein Objekt' => [['user_ids' => ['a' => 1], 'role_id' => '{wart}'], 'Das Feld "user_ids" muss eine Liste mit 1 bis 50 Konten sein.'];
        yield 'Text' => [['user_ids' => ['1'], 'role_id' => '{wart}'], 'Eintrag 1 in "user_ids" muss eine positive Ganzzahl sein.'];
        yield 'doppelt' => [['user_ids' => [5, 5], 'role_id' => '{wart}'], 'Ein Konto steht mehrfach in "user_ids".'];
        yield 'unbekanntes Feld' => [['user_ids' => [5], 'role_id' => '{wart}', 'role' => 'user'], 'Das Feld "role" ist hier nicht vorgesehen (möglich: user_ids, role_id).'];
        yield 'ohne Rolle' => [['user_ids' => [5]], 'Das Feld "role_id" muss eine ganze Zahl von 1 bis 9223372036854775807 sein.'];
        yield 'unbekannte Rolle' => [['user_ids' => [5], 'role_id' => 999999], 'Die Rolle #999999 gibt es nicht. (Feld "role_id")'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidBulkRequests')]
    public function testBulkRejectsInvalidRequestsBeforeAnyChange(array $body, string $message): void
    {
        $body['role_id'] = ($body['role_id'] ?? null) === '{wart}' ? $this->wart : ($body['role_id'] ?? null);
        if ($body['role_id'] === null) {
            unset($body['role_id']);
        }
        $before = $this->pdo()->query('SELECT * FROM fe_users ORDER BY id')->fetchAll();

        $response = $this->bulk($body);

        self::assertError(400, 'invalid_request', $response);
        self::assertSame($message, self::json($response)['message']);
        self::assertSame($before, $this->pdo()->query('SELECT * FROM fe_users ORDER BY id')->fetchAll());
    }

    public function testBulkWithRoleBeyondOwnRightsChangesNothing(): void
    {
        $cookie = $this->managerCookie();
        $user = $this->account('immendingen', 'user@example.org');

        $response = $this->send('POST', self::url('immendingen', 'users/bulk/role'), ['user_ids' => [$user], 'role_id' => $this->adminRole], $cookie);

        self::assertError(409, 'rule_violation', $response);
        self::assertSame('Die Rolle „Admin“ vergeben und entziehen nur Admins.', self::json($response)['message']);
        self::assertSame($this->userRole, new UserRepository($this->pdo())->findById($user)?->roleId);
    }

    // ---- Einladungscodes und Registrierung -----------------------------------------------------

    public function testInvitationCodeWithRole(): void
    {
        $withRole = $this->createCode(['label' => 'Aushang Wart', 'role_id' => $this->wart]);
        $withoutRole = $this->createCode([]);

        self::assertSame(['id' => $this->wart, 'name' => 'Gerätewart'], $withRole['invitation_code']['role']);
        self::assertSame(['id' => $this->userRole, 'name' => 'Benutzer'], $withoutRole['invitation_code']['role'], 'ohne Rolle: Benutzer');
        $list = self::json($this->send('GET', self::url('immendingen', 'invitation-codes'), cookie: $this->cookie))['invitation_codes'];
        self::assertSame(['Benutzer', 'Gerätewart'], array_map(static fn (array $c): string => $c['role']['name'], $list));
        self::assertNull($this->pdo()->query('SELECT role_id FROM fe_invitation_codes WHERE id = ' . (int) $withoutRole['invitation_code']['id'])->fetchColumn());

        $admin = $this->send('POST', self::url('immendingen', 'invitation-codes'), ['valid_days' => 7, 'max_uses' => 5, 'role_id' => $this->adminRole], $this->cookie);
        self::assertError(400, 'invalid_request', $admin);
        self::assertSame(InvitationCodeCreateHandler::ADMIN_ROLE_MESSAGE, self::json($admin)['message']);
        $foreign = (int) $this->systemRoleId($this->tenant('tuttlingen')->id, 'user');
        self::assertError(400, 'invalid_request', $this->send('POST', self::url('immendingen', 'invitation-codes'), ['valid_days' => 7, 'max_uses' => 5, 'role_id' => $foreign], $this->cookie));
        self::assertSame(2, $this->countRows('fe_invitation_codes'));
    }

    public function testRegistrationTakesRoleOfCodeAndListShowsCode(): void
    {
        $code = $this->createCode(['label' => 'Aushang Wart', 'role_id' => $this->wart]);

        $response = $this->send('POST', self::url('immendingen', 'auth/register'), [
            'code' => $code['code'], 'email' => 'neu@example.org', 'password' => self::PASSWORD, 'name' => 'Nina Neu',
        ]);

        self::assertSame(202, $response->statusCode(), $response->body());
        $users = self::json($this->send('GET', self::url('immendingen', 'users'), cookie: $this->cookie))['users'];
        $registered = array_values(array_filter($users, static fn (array $u): bool => $u['email'] === 'neu@example.org'))[0];
        self::assertSame(['unverified', 'user', $this->wart, 'Gerätewart'], [$registered['status'], $registered['role'], $registered['role_id'], $registered['role_name']]);
        self::assertSame(['id' => $code['invitation_code']['id'], 'label' => 'Aushang Wart'], $registered['invitation_code']);

        $plain = $this->createCode([]);
        $this->send('POST', self::url('immendingen', 'auth/register'), [
            'code' => $plain['code'], 'email' => 'zwei@example.org', 'password' => self::PASSWORD, 'name' => 'Zora Zwei',
        ]);
        self::assertSame($this->userRole, new UserRepository($this->pdo())->findByTenantAndEmail(
            $this->tenant('immendingen'),
            EmailAddress::fromString('zwei@example.org'),
        )?->roleId);
    }

    // ---- Hilfen --------------------------------------------------------------------------------

    /** Konto mit der Rolle Personal (Benutzer: Ändern, ohne Admin) und dessen Cookie. */
    private function managerCookie(): string
    {
        $this->account('immendingen', 'personal@example.org', ['role_id' => $this->personal]);

        return $this->loginCookie('immendingen', 'personal@example.org');
    }

    private function userIdOf(string $email): int
    {
        $stmt = $this->pdo()->prepare('SELECT id FROM fe_users WHERE email = ?');
        $stmt->execute([$email]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private function createCode(array $fields): array
    {
        $response = $this->send('POST', self::url('immendingen', 'invitation-codes'), ['valid_days' => 7, 'max_uses' => 5, ...$fields], $this->cookie);
        self::assertSame(201, $response->statusCode(), $response->body());

        return self::json($response);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function bulk(array $body): JsonResponse
    {
        return $this->send('POST', self::url('immendingen', 'users/bulk/role'), $body, $this->cookie);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(int $id, string $action, array $body = []): JsonResponse
    {
        return $this->send('POST', self::url('immendingen', 'users/' . $id . '/' . $action), $body, $this->cookie);
    }
}
