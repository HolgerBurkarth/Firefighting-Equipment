<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Auth;

use FirefightingEquipment\Api\V1\Users\UserAction;
use FirefightingEquipment\Api\V1\Users\UserActionHandler;
use FirefightingEquipment\Api\V1\Users\UserJson;
use FirefightingEquipment\Api\V1\Users\UserListHandler;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\User\UserAdministration;
use FirefightingEquipment\User\UserRepository;
use FirefightingEquipment\User\UserStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(UserListHandler::class)]
#[CoversClass(UserActionHandler::class)]
#[CoversClass(UserAction::class)]
#[CoversClass(UserJson::class)]
#[CoversClass(UserAdministration::class)]
final class UserAdministrationTest extends AuthTestCase
{
    private int $admin;
    private string $cookie;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->account('immendingen', 'admin@example.org', ['role' => 'admin', 'display_name' => 'Wehrführer']);
        $this->cookie = $this->loginCookie('immendingen', 'admin@example.org');
    }

    // ---- Liste und Anzeige -----------------------------------------------------------------

    public function testListShowsOwnTenantWithoutDeletedAccounts(): void
    {
        $user = $this->account('immendingen', 'user@example.org', ['locked_at' => self::CREATED_AT]);
        $pending = $this->registeredAccount('immendingen', 'wartet@example.org', 'pending');
        $deleted = $this->account('immendingen', 'weg@example.org');
        new UserRepository($this->pdo())->anonymize($deleted);
        $this->account('tuttlingen', 'fremd@example.org');

        $response = $this->get('users');

        self::assertSame(200, $response->statusCode(), $response->body());
        $users = self::json($response)['users'];
        $ids = array_column($users, 'id');
        self::assertContains($this->admin, $ids);
        self::assertContains($user, $ids);
        self::assertContains($pending, $ids);
        self::assertNotContains($deleted, $ids);
        $sorted = $ids;
        sort($sorted);
        self::assertSame($sorted, $ids, 'nach ID');
        self::assertStringNotContainsString('fremd@example.org', $response->body());
        self::assertStringNotContainsString('argon2', $response->body());

        $locked = array_values(array_filter($users, static fn (array $u): bool => $u['id'] === $user))[0];
        self::assertSame([
            'id' => $user,
            'label' => 'Erika',
            'email' => 'user@example.org',
            'name' => 'Erika Musterfrau',
            'display_name' => 'Erika',
            'role' => 'user',
            'role_label' => 'Benutzer',
            'status' => 'active',
            'status_label' => 'aktiv',
            'locked' => true,
            'email_verified_at' => '2026-01-01T12:00:00Z',
            'created_at' => $locked['created_at'],
        ], $locked);
    }

    public function testListFilteredByStatus(): void
    {
        $pending = $this->registeredAccount('immendingen', 'wartet@example.org', 'pending');
        $this->registeredAccount('immendingen', 'neu@example.org', 'unverified');
        $deleted = $this->account('immendingen', 'weg@example.org');
        new UserRepository($this->pdo())->anonymize($deleted);

        self::assertSame([$pending], array_column(self::json($this->get('users?status=pending'))['users'], 'id'));
        $gone = self::json($this->get('users?status=deleted'))['users'];
        self::assertSame([$deleted], array_column($gone, 'id'));
        self::assertSame(sprintf('Gelöschter Benutzer #%d', $deleted), $gone[0]['label']);
        self::assertNull($gone[0]['email']);

        $invalid = $this->get('users?status=Pending');
        self::assertError(400, 'invalid_request', $invalid);
        self::assertSame('Unbekannter Status. Erlaubt: invited, unverified, pending, active, deleted.', self::json($invalid)['message']);
    }

    public function testListRemovesStaleRegistrations(): void
    {
        $stale = $this->registeredAccount('immendingen', 'alt@example.org', 'unverified');
        $this->pdo()->exec('UPDATE fe_users SET created_at = created_at - INTERVAL 8 DAY WHERE id = ' . $stale);

        self::assertNotContains($stale, array_column(self::json($this->get('users'))['users'], 'id'));
        self::assertSame(UserStatus::Deleted, $this->users()->findById($stale)?->status);
    }

    public function testShow(): void
    {
        $user = $this->account('immendingen', 'user@example.org');
        $foreign = $this->account('tuttlingen', 'user@example.org');

        self::assertSame('user@example.org', self::json($this->get('users/' . $user))['user']['email']);
        self::assertError(404, 'not_found', $this->get('users/' . $foreign));
        self::assertError(404, 'not_found', $this->get('users/999999'));
    }

    // ---- Freischalten und Ablehnen ---------------------------------------------------------

    public function testApproveActivatesAndSendsMail(): void
    {
        $id = $this->registeredAccount('immendingen', 'max@example.org', 'pending');

        $response = $this->post($id, 'approve', ['display_name' => '  Max ', 'role' => 'admin']);

        self::assertSame(200, $response->statusCode(), $response->body());
        $json = self::json($response)['user'];
        self::assertSame('active', $json['status']);
        self::assertSame('admin', $json['role']);
        self::assertSame('Max', $json['display_name']);
        $mail = $this->mailer->only();
        self::assertSame('max@example.org', $mail->to->value);
        self::assertSame('Ihr Konto wurde freigeschaltet', $mail->subject);
        self::assertStringContainsString('Ihre Rolle: Admin.', $mail->text);
        self::assertStringContainsString("\n" . self::BASE_URL . "/firefighting/immendingen/\n", $mail->text);

        self::assertSame(200, $this->login('immendingen', 'max@example.org')->statusCode());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notPending(): iterable
    {
        yield 'unbestätigt' => ['unverified'];
        yield 'aktiv' => ['active'];
        yield 'gelöscht' => ['deleted'];
    }

    #[DataProvider('notPending')]
    public function testApproveOnlyPending(string $status): void
    {
        $id = match ($status) {
            'unverified' => $this->registeredAccount('immendingen', 'max@example.org', 'unverified'),
            default => $this->account('immendingen', 'max@example.org'),
        };
        if ($status === 'deleted') {
            $this->users()->anonymize($id);
        }

        $response = $this->post($id, 'approve', ['display_name' => 'Max', 'role' => 'user']);

        self::assertError(409, 'rule_violation', $response);
        self::assertStringContainsString('wartet nicht auf Freischaltung', self::json($response)['message']);
        self::assertSame([], $this->mailer->sent);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidApprovals(): iterable
    {
        yield 'Super-Admin' => [['display_name' => 'Max', 'role' => 'superadmin'], 'Das Feld "role" muss "user" oder "admin" sein.'];
        yield 'Rolle groß' => [['display_name' => 'Max', 'role' => 'Admin'], 'Das Feld "role" muss "user" oder "admin" sein.'];
        yield 'ohne Rolle' => [['display_name' => 'Max'], 'Das Feld "role" fehlt oder ist kein Text.'];
        yield 'leerer Anzeigename' => [['display_name' => ' ', 'role' => 'user'], 'Der Name darf nicht leer sein.'];
        yield 'ohne Anzeigename' => [['role' => 'user'], 'Das Feld "display_name" fehlt oder ist kein Text.'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidApprovals')]
    public function testApproveValidatesFields(array $body, string $message): void
    {
        $id = $this->registeredAccount('immendingen', 'max@example.org', 'pending');

        $response = $this->post($id, 'approve', $body);

        self::assertError(400, 'invalid_request', $response);
        self::assertSame($message, self::json($response)['message']);
        self::assertSame(UserStatus::Pending, $this->users()->findById($id)?->status);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function openRegistrations(): iterable
    {
        yield 'wartet' => ['pending'];
        yield 'unbestätigt' => ['unverified'];
    }

    #[DataProvider('openRegistrations')]
    public function testRejectSendsMailAndAnonymizes(string $status): void
    {
        $id = $this->registeredAccount('immendingen', 'max@example.org', $status);
        $this->insert('fe_user_tokens', ['user_id' => $id, 'purpose' => 'verify_email', 'token_hash' => self::hash('t'), 'created_at' => '2098-12-31 12:00:00', 'expires_at' => '2099-01-01 00:00:00']);

        $response = $this->post($id, 'reject');

        self::assertSame(200, $response->statusCode(), $response->body());
        $json = self::json($response)['user'];
        self::assertSame('deleted', $json['status']);
        self::assertSame(sprintf('Gelöschter Benutzer #%d', $id), $json['label']);
        self::assertNull($json['email']);
        self::assertSame(0, $this->countRows('fe_user_tokens'));
        $mail = $this->mailer->only();
        self::assertSame('max@example.org', $mail->to->value);
        self::assertSame('Ihre Registrierung wurde nicht freigegeben', $mail->subject);
    }

    public function testRejectOnlyOpenRegistrations(): void
    {
        $id = $this->account('immendingen', 'user@example.org');

        self::assertError(409, 'rule_violation', $this->post($id, 'reject'));
        self::assertSame(UserStatus::Active, $this->users()->findById($id)?->status);
        self::assertSame([], $this->mailer->sent);
    }

    // ---- Sperren, Rolle, Anzeigename, Löschen ----------------------------------------------

    public function testLockEndsSessionsAndUnlockAllowsLoginAgain(): void
    {
        $id = $this->account('immendingen', 'user@example.org');
        $userCookie = $this->loginCookie('immendingen', 'user@example.org');

        $locked = $this->post($id, 'lock');

        self::assertSame(200, $locked->statusCode(), $locked->body());
        self::assertTrue(self::json($locked)['user']['locked']);
        self::assertSame(0, $this->sessionCountOf($id));
        self::assertError(401, 'not_authenticated', $this->send('GET', self::url('immendingen', 'auth/me'), cookie: $userCookie));
        self::assertSame(200, $this->post($id, 'lock')->statusCode(), 'wiederholbar');

        $unlocked = $this->post($id, 'unlock');
        self::assertFalse(self::json($unlocked)['user']['locked']);
        self::assertSame(200, $this->login('immendingen', 'user@example.org')->statusCode());
    }

    public function testChangeRoleEndsSessions(): void
    {
        $id = $this->account('immendingen', 'user@example.org');
        $this->loginCookie('immendingen', 'user@example.org');

        $response = $this->post($id, 'role', ['role' => 'admin']);

        self::assertSame('admin', self::json($response)['user']['role']);
        self::assertSame(0, $this->sessionCountOf($id));

        self::assertSame('user', self::json($this->post($id, 'role', ['role' => 'user']))['user']['role'], 'anderen Admin herabstufen');
        self::assertError(400, 'invalid_request', $this->post($id, 'role', ['role' => 'superadmin']));
    }

    public function testChangeDisplayNameKeepsSessions(): void
    {
        $id = $this->account('immendingen', 'user@example.org');
        $this->loginCookie('immendingen', 'user@example.org');

        $response = $this->post($id, 'display-name', ['display_name' => 'Erika M.']);

        self::assertSame('Erika M.', self::json($response)['user']['display_name']);
        self::assertSame(1, $this->sessionCountOf($id));
        self::assertError(400, 'invalid_request', $this->post($id, 'display-name', ['display_name' => str_repeat('x', 101)]));
    }

    public function testDeleteAnonymizesAndEndsSessions(): void
    {
        $id = $this->account('immendingen', 'user@example.org');
        $this->loginCookie('immendingen', 'user@example.org');

        $response = $this->send('DELETE', self::url('immendingen', 'users/' . $id), [], $this->cookie);

        self::assertSame(200, $response->statusCode(), $response->body());
        self::assertSame('deleted', self::json($response)['user']['status']);
        self::assertSame(0, $this->sessionCountOf($id));
        self::assertNull($this->users()->findById($id)?->email);
        self::assertError(409, 'rule_violation', $this->post($id, 'display-name', ['display_name' => 'X']), 'gelöscht bleibt gelöscht');
    }

    /**
     * @return iterable<string, array{string, string, array<string, mixed>, string}>
     */
    public static function selfActions(): iterable
    {
        yield 'sich selbst sperren' => ['POST', 'lock', [], 'Sie können Ihr eigenes Konto nicht sperren.'];
        yield 'sich selbst herabstufen' => ['POST', 'role', ['role' => 'user'], 'Sie können Ihrem eigenen Konto die Admin-Rolle nicht entziehen.'];
        yield 'sich selbst löschen' => ['DELETE', '', [], 'Sie können Ihr eigenes Konto nicht löschen.'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('selfActions')]
    public function testAdminCannotLockDemoteOrDeleteSelf(string $method, string $action, array $body, string $message): void
    {
        // Ein zweiter Admin: die Regel gilt unabhängig von „letzter Admin“.
        $this->account('immendingen', 'admin2@example.org', ['role' => 'admin']);

        $response = $this->send($method, self::url('immendingen', 'users/' . $this->admin . ($action === '' ? '' : '/' . $action)), $body, $this->cookie);

        self::assertError(409, 'rule_violation', $response);
        self::assertSame($message, self::json($response)['message']);
        $me = $this->users()->findById($this->admin);
        self::assertSame(UserStatus::Active, $me?->status);
        self::assertFalse($me->isLocked());
        self::assertSame(200, $this->get('users')->statusCode(), 'noch angemeldet');
    }

    public function testOwnRoleUnchangedAndOwnDisplayNameAreAllowed(): void
    {
        self::assertSame(200, $this->post($this->admin, 'role', ['role' => 'admin'])->statusCode());
        self::assertSame('Chef', self::json($this->post($this->admin, 'display-name', ['display_name' => 'Chef']))['user']['display_name']);
    }

    /**
     * Der handelnde Admin ist selbst aktiv; andere Admins sind daher nie „der letzte“. Ein weiterer
     * Admin lässt sich sperren, herabstufen und löschen.
     */
    public function testOtherAdminCanBeLockedDemotedAndDeleted(): void
    {
        $other = $this->account('immendingen', 'admin2@example.org', ['role' => 'admin']);

        self::assertSame(200, $this->post($other, 'lock')->statusCode());
        self::assertSame(200, $this->post($other, 'role', ['role' => 'user'])->statusCode());
        self::assertSame(200, $this->send('DELETE', self::url('immendingen', 'users/' . $other), [], $this->cookie)->statusCode());
        self::assertSame([$this->admin], array_map(static fn ($u): int => $u->id, $this->users()->listActiveAdmins($this->tenant('immendingen'))));
    }

    /**
     * @return iterable<string, array{string, string, array<string, mixed>}>
     */
    public static function foreignActions(): iterable
    {
        yield 'freischalten' => ['POST', 'approve', ['display_name' => 'Max', 'role' => 'user']];
        yield 'ablehnen' => ['POST', 'reject', []];
        yield 'sperren' => ['POST', 'lock', []];
        yield 'entsperren' => ['POST', 'unlock', []];
        yield 'Rolle' => ['POST', 'role', ['role' => 'admin']];
        yield 'Anzeigename' => ['POST', 'display-name', ['display_name' => 'X']];
        yield 'löschen' => ['DELETE', '', []];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('foreignActions')]
    public function testAccountsOfOtherTenantsAreNotFound(string $method, string $action, array $body): void
    {
        $foreign = $this->registeredAccount('tuttlingen', 'max@example.org', 'pending');
        $before = $this->pdo()->query('SELECT * FROM fe_users WHERE id = ' . $foreign)->fetch();

        $response = $this->send($method, self::url('immendingen', 'users/' . $foreign . ($action === '' ? '' : '/' . $action)), $body, $this->cookie);

        self::assertError(404, 'not_found', $response);
        self::assertSame($before, $this->pdo()->query('SELECT * FROM fe_users WHERE id = ' . $foreign)->fetch());
        self::assertSame([], $this->mailer->sent);
    }

    private function users(): UserRepository
    {
        return new UserRepository($this->pdo());
    }

    private function get(string $path): JsonResponse
    {
        return $this->send('GET', self::url('immendingen', $path), cookie: $this->cookie);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(int $id, string $action, array $body = []): JsonResponse
    {
        return $this->send('POST', self::url('immendingen', 'users/' . $id . '/' . $action), $body, $this->cookie);
    }
}
