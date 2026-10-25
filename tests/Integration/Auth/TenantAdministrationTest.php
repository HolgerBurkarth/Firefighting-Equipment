<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Auth;

use FirefightingEquipment\Api\V1\Tenants\AdminInvitationHandler;
use FirefightingEquipment\Api\V1\Tenants\TenantAction;
use FirefightingEquipment\Api\V1\Tenants\TenantActionHandler;
use FirefightingEquipment\Api\V1\Tenants\TenantCreateHandler;
use FirefightingEquipment\Api\V1\Tenants\TenantFields;
use FirefightingEquipment\Api\V1\Tenants\TenantJson;
use FirefightingEquipment\Api\V1\Tenants\TenantListHandler;
use FirefightingEquipment\Api\V1\Tenants\TenantUserListHandler;
use FirefightingEquipment\Auth\OneTimeToken;
use FirefightingEquipment\Auth\TokenPurpose;
use FirefightingEquipment\Auth\UserTokenRepository;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Mail\AdminInvitationMail;
use FirefightingEquipment\Mail\MailMessage;
use FirefightingEquipment\Tenant\TenantAdministration;
use FirefightingEquipment\Tests\Support\FakeMailer;
use FirefightingEquipment\User\EmailAddress;
use FirefightingEquipment\User\UserRepository;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Städteverwaltung durch den Super-Admin über die API: auflisten, anlegen (mit Einladung des ersten
 * Admins), umbenennen, sperren/entsperren, Admin einladen, Einladung erneut senden, Konten ansehen.
 * Rechte prüft SuperAdminRoutesAccessTest.
 */
#[CoversClass(TenantListHandler::class)]
#[CoversClass(TenantCreateHandler::class)]
#[CoversClass(TenantActionHandler::class)]
#[CoversClass(TenantAction::class)]
#[CoversClass(AdminInvitationHandler::class)]
#[CoversClass(TenantUserListHandler::class)]
#[CoversClass(TenantFields::class)]
#[CoversClass(TenantJson::class)]
#[CoversClass(TenantAdministration::class)]
#[CoversClass(AdminInvitationMail::class)]
final class TenantAdministrationTest extends AuthTestCase
{
    private string $cookie;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account(null, 'chef@example.org');
        $this->cookie = $this->loginCookie(null, 'chef@example.org');
    }

    // ---- Auflisten und anzeigen --------------------------------------------------------------

    public function testListShowsTenantsWithCounts(): void
    {
        $this->account('immendingen', 'admin@example.org', ['role' => 'admin']);
        $this->account('immendingen', 'max@example.org');
        $this->account('immendingen', 'gesperrt@example.org', ['locked_at' => self::CREATED_AT]);
        $this->invite('immendingen', 'neu@example.org');
        $this->pdo()->exec("UPDATE fe_tenants SET active = 0 WHERE slug = 'tuttlingen'");

        $response = $this->get('tenants');

        self::assertSame(200, $response->statusCode(), $response->body());
        $tenants = self::json($response)['tenants'];
        self::assertSame(['immendingen', 'tuttlingen'], array_column($tenants, 'slug'));
        self::assertSame([
            'id' => $this->tenant('immendingen')->id,
            'slug' => 'immendingen',
            'name' => 'Feuerwehr immendingen',
            'active' => true,
            'created_at' => $tenants[0]['created_at'],
            'updated_at' => $tenants[0]['updated_at'],
            'counts' => ['users_active' => 2, 'admins_active' => 1, 'admins_invited' => 1],
        ], $tenants[0]);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $tenants[0]['created_at']);
        self::assertFalse($tenants[1]['active']);
        self::assertSame(['users_active' => 0, 'admins_active' => 0, 'admins_invited' => 0], $tenants[1]['counts']);
    }

    public function testShowAndUnknownTenant(): void
    {
        $id = $this->tenant('tuttlingen')->id;

        self::assertSame('tuttlingen', self::json($this->get('tenants/' . $id))['tenant']['slug']);
        self::assertError(404, 'not_found', $this->get('tenants/999999'));
    }

    // ---- Anlegen -----------------------------------------------------------------------------

    public function testCreateTenantInvitesFirstAdmin(): void
    {
        $response = $this->post('tenants', ['slug' => 'geisingen', 'name' => ' Feuerwehr Geisingen ', 'admin_email' => 'Kommandant@Example.org']);

        self::assertSame(201, $response->statusCode(), $response->body());
        $json = self::json($response);
        self::assertSame('geisingen', $json['tenant']['slug']);
        self::assertSame('Feuerwehr Geisingen', $json['tenant']['name']);
        self::assertTrue($json['tenant']['active']);
        self::assertSame(['users_active' => 0, 'admins_active' => 0, 'admins_invited' => 1], $json['tenant']['counts']);
        self::assertSame('kommandant@example.org', $json['user']['email']);
        self::assertSame('admin', $json['user']['role']);
        self::assertSame('invited', $json['user']['status']);
        self::assertTrue($json['mail_sent']);

        $mail = $this->mailer->only();
        self::assertSame('kommandant@example.org', $mail->to->value);
        self::assertSame('Einladung als Admin – Feuerwehr Geisingen', $mail->subject);
        $token = self::invitationToken($mail, 'geisingen');
        self::assertStringNotContainsString($token->value(), $response->body(), 'Token nie in der Antwort');
        self::assertSame($json['user']['id'], new UserTokenRepository($this->pdo())->findUserId(TokenPurpose::SetPassword, $token));

        // Die Stadt ist sofort erreichbar; der eingeladene Admin kann sich noch nicht anmelden.
        self::assertSame(200, $this->send('GET', self::url('geisingen', 'tenant'))->statusCode());
        self::assertError(401, 'invalid_credentials', $this->login('geisingen', 'kommandant@example.org'));
    }

    public function testCreateRejectsInvalidFieldsWithoutChanges(): void
    {
        $valid = ['slug' => 'geisingen', 'name' => 'Feuerwehr Geisingen', 'admin_email' => 'admin@example.org'];

        $upper = $this->post('tenants', ['slug' => 'Geisingen'] + $valid);
        self::assertError(400, 'invalid_request', $upper);
        self::assertStringContainsString('geisingen', self::json($upper)['message'], 'Vorschlag in Kleinbuchstaben');
        self::assertError(400, 'invalid_request', $this->post('tenants', ['slug' => 'service'] + $valid));
        self::assertError(400, 'invalid_request', $this->post('tenants', ['name' => '  '] + $valid));
        self::assertError(400, 'invalid_request', $this->post('tenants', ['admin_email' => 'keine-adresse'] + $valid));
        self::assertError(400, 'invalid_request', $this->post('tenants', ['slug' => 'geisingen', 'name' => 'Feuerwehr Geisingen']));

        self::assertSame(2, $this->countRows('fe_tenants'));
        self::assertSame([], $this->mailer->sent);
    }

    public function testCreateWithTakenSlugIsConflict(): void
    {
        $users = $this->countRows('fe_users');

        $response = $this->post('tenants', ['slug' => 'immendingen', 'name' => 'Noch einmal', 'admin_email' => 'admin@example.org']);

        self::assertError(409, 'slug_taken', $response);
        self::assertSame('Das Kürzel „immendingen“ ist bereits vergeben.', self::json($response)['message']);
        self::assertSame($users, $this->countRows('fe_users'));
        self::assertSame(0, $this->countRows('fe_user_tokens'));
        self::assertSame('Feuerwehr immendingen', $this->tenant('immendingen')->name->value);
        self::assertSame([], $this->mailer->sent);
    }

    public function testFailedMailIsReportedButTenantStays(): void
    {
        $this->mailer = new FakeMailer(fail: true);

        $response = $this->post('tenants', ['slug' => 'geisingen', 'name' => 'Feuerwehr Geisingen', 'admin_email' => 'admin@example.org']);

        self::assertSame(201, $response->statusCode(), $response->body());
        $json = self::json($response);
        self::assertFalse($json['mail_sent']);
        self::assertCount(1, $this->log);
        self::assertStringContainsString(sprintf('mail_failed (admin_invitation, Konto #%d)', $json['user']['id']), $this->log[0]);
        self::assertStringNotContainsString('admin@example.org', $this->log[0]);
        self::assertSame(1, $this->countRows('fe_user_tokens'));
    }

    // ---- Umbenennen, sperren, entsperren -----------------------------------------------------

    public function testRenameChangesNameOnly(): void
    {
        $id = $this->tenant('immendingen')->id;

        $response = $this->post('tenants/' . $id . '/rename', ['name' => 'Freiwillige Feuerwehr Immendingen']);

        self::assertSame(200, $response->statusCode(), $response->body());
        self::assertSame('Freiwillige Feuerwehr Immendingen', self::json($response)['tenant']['name']);
        self::assertSame('immendingen', self::json($response)['tenant']['slug']);
        self::assertSame('Freiwillige Feuerwehr Immendingen', $this->tenant('immendingen')->name->value);

        self::assertError(400, 'invalid_request', $this->post('tenants/' . $id . '/rename', ['name' => '']));
        self::assertError(400, 'invalid_request', $this->post('tenants/' . $id . '/rename', ['slug' => 'neu']));
        self::assertError(404, 'not_found', $this->post('tenants/999999/rename', ['name' => 'Irgendwo']));
    }

    public function testLockEndsSessionsAndUnlockDoesNotRevive(): void
    {
        $user = $this->account('immendingen', 'max@example.org');
        $other = $this->account('tuttlingen', 'max@example.org');
        $userCookie = $this->loginCookie('immendingen', 'max@example.org');
        $otherCookie = $this->loginCookie('tuttlingen', 'max@example.org');
        $id = $this->tenant('immendingen')->id;

        $response = $this->post('tenants/' . $id . '/lock', []);

        self::assertSame(200, $response->statusCode(), $response->body());
        self::assertFalse(self::json($response)['tenant']['active']);
        self::assertSame(0, $this->sessionCountOf($user));
        self::assertSame(1, $this->sessionCountOf($other), 'andere Stadt unberührt');
        self::assertError(403, 'tenant_inactive', $this->send('GET', self::url('immendingen', 'auth/me'), null, $userCookie));
        self::assertSame(200, $this->post('tenants/' . $id . '/lock', [])->statusCode(), 'wiederholbar');

        $response = $this->post('tenants/' . $id . '/unlock', []);
        self::assertSame(200, $response->statusCode(), $response->body());
        self::assertTrue(self::json($response)['tenant']['active']);
        self::assertError(401, 'not_authenticated', $this->send('GET', self::url('immendingen', 'auth/me'), null, $userCookie));
        self::assertSame(200, $this->send('GET', self::url('tuttlingen', 'auth/me'), null, $otherCookie)->statusCode());
        self::assertSame(200, $this->post('tenants/' . $id . '/unlock', [])->statusCode(), 'wiederholbar');
        self::assertError(404, 'not_found', $this->post('tenants/999999/lock', []));
    }

    // ---- Admin einladen und Einladung erneut senden -----------------------------------------

    public function testInviteAdminIntoExistingTenant(): void
    {
        $tenant = $this->tenant('immendingen');

        $response = $this->post('tenants/' . $tenant->id . '/admins', ['email' => 'zweiter@example.org']);

        self::assertSame(201, $response->statusCode(), $response->body());
        $json = self::json($response);
        self::assertSame('invited', $json['user']['status']);
        self::assertSame('admin', $json['user']['role']);
        self::assertTrue($json['mail_sent']);
        $token = self::invitationToken($this->mailer->only(), 'immendingen');
        self::assertSame($json['user']['id'], new UserTokenRepository($this->pdo())->findUserId(TokenPurpose::SetPassword, $token));
    }

    public function testInviteWithTakenEmailIsUnderstandableConflict(): void
    {
        $id = $this->tenant('immendingen')->id;
        $this->account('immendingen', 'max@example.org');
        $this->invite('immendingen', 'neu@example.org');
        $users = $this->countRows('fe_users');

        $taken = $this->post('tenants/' . $id . '/admins', ['email' => 'max@example.org']);
        self::assertError(409, 'email_taken', $taken);
        self::assertStringContainsString('„max@example.org“', self::json($taken)['message']);
        self::assertError(409, 'email_taken', $this->post('tenants/' . $id . '/admins', ['email' => 'neu@example.org']));
        self::assertSame($users, $this->countRows('fe_users'));
        self::assertSame([], $this->mailer->sent);

        // In einer anderen Stadt ist dieselbe Adresse ein eigenes Konto.
        self::assertSame(201, $this->post('tenants/' . $this->tenant('tuttlingen')->id . '/admins', ['email' => 'max@example.org'])->statusCode());
    }

    public function testInviteRejectsInvalidEmailUnknownAndLockedTenant(): void
    {
        $id = $this->tenant('immendingen')->id;

        self::assertError(400, 'invalid_request', $this->post('tenants/' . $id . '/admins', ['email' => 'keine-adresse']));
        self::assertError(404, 'not_found', $this->post('tenants/999999/admins', ['email' => 'neu@example.org']));
        $this->pdo()->exec("UPDATE fe_tenants SET active = 0 WHERE slug = 'immendingen'");
        self::assertError(409, 'rule_violation', $this->post('tenants/' . $id . '/admins', ['email' => 'neu@example.org']));
        self::assertSame(0, $this->countRows('fe_user_tokens'));
        self::assertSame([], $this->mailer->sent);
    }

    public function testResendInvitationReplacesLink(): void
    {
        $tenant = $this->tenant('immendingen');
        $old = new UserRepository($this->pdo())->inviteAdmin($tenant, EmailAddress::fromString('neu@example.org'));

        $response = $this->post(sprintf('tenants/%d/users/%d/resend-invitation', $tenant->id, $old->user->id), []);

        self::assertSame(200, $response->statusCode(), $response->body());
        self::assertTrue(self::json($response)['mail_sent']);
        $tokens = new UserTokenRepository($this->pdo());
        self::assertNull($tokens->findUserId(TokenPurpose::SetPassword, $old->token), 'alter Link ungültig');
        $token = self::invitationToken($this->mailer->only(), 'immendingen');
        self::assertSame($old->user->id, $tokens->findUserId(TokenPurpose::SetPassword, $token));
    }

    public function testResendOnlyForOpenInvitationOfThatTenant(): void
    {
        $tenant = $this->tenant('immendingen');
        $active = $this->account('immendingen', 'max@example.org');
        $foreign = $this->invite('tuttlingen', 'neu@example.org');
        $locked = $this->invite('immendingen', 'gesperrt@example.org');
        new UserRepository($this->pdo())->lock($locked);
        $resend = fn (int $tenantId, int $userId): JsonResponse => $this->post(sprintf('tenants/%d/users/%d/resend-invitation', $tenantId, $userId), []);

        self::assertError(409, 'rule_violation', $resend($tenant->id, $active));
        self::assertError(409, 'rule_violation', $resend($tenant->id, $locked));
        self::assertError(404, 'not_found', $resend($tenant->id, $foreign), 'Konto einer anderen Stadt');
        self::assertError(404, 'not_found', $resend($tenant->id, 999_999));
        self::assertError(404, 'not_found', $resend(999_999, $foreign));
        $this->pdo()->exec("UPDATE fe_tenants SET active = 0 WHERE slug = 'tuttlingen'");
        self::assertError(409, 'rule_violation', $resend($this->tenant('tuttlingen')->id, $foreign), 'gesperrte Stadt');
        self::assertSame([], $this->mailer->sent);
    }

    // ---- Konten ansehen ----------------------------------------------------------------------

    public function testUsersOfTenantReadOnly(): void
    {
        $tenant = $this->tenant('immendingen');
        $admin = $this->account('immendingen', 'admin@example.org', ['role' => 'admin']);
        $invited = $this->invite('immendingen', 'neu@example.org');
        $deleted = $this->account('immendingen', 'weg@example.org');
        new UserRepository($this->pdo())->anonymize($deleted);
        $stale = $this->registeredAccount('immendingen', 'alt@example.org', 'unverified');
        $this->pdo()->exec('UPDATE fe_users SET created_at = created_at - INTERVAL 30 DAY WHERE id = ' . $stale);
        $this->account('tuttlingen', 'fremd@example.org');

        $response = $this->get('tenants/' . $tenant->id . '/users');

        self::assertSame(200, $response->statusCode(), $response->body());
        $ids = array_column(self::json($response)['users'], 'id');
        self::assertContains($admin, $ids);
        self::assertContains($invited, $ids);
        self::assertContains($stale, $ids, 'nur lesend: kein Aufräumen');
        self::assertNotContains($deleted, $ids);
        self::assertStringNotContainsString('fremd@example.org', $response->body());
        self::assertStringNotContainsString('argon2', $response->body());

        self::assertSame([$invited], array_column(self::json($this->get('tenants/' . $tenant->id . '/users?status=invited'))['users'], 'id'));
        self::assertError(400, 'invalid_request', $this->get('tenants/' . $tenant->id . '/users?status=unbekannt'));
        self::assertError(404, 'not_found', $this->get('tenants/999999/users'));
    }

    // ---- Hilfen ------------------------------------------------------------------------------

    private function get(string $path): JsonResponse
    {
        return $this->send('GET', self::url(null, $path), null, $this->cookie);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $path, array $body): JsonResponse
    {
        return $this->send('POST', self::url(null, $path), $body, $this->cookie);
    }

    private function invite(string $slug, string $email): int
    {
        return new UserRepository($this->pdo())->inviteAdmin($this->tenant($slug), EmailAddress::fromString($email))->user->id;
    }

    private static function invitationToken(MailMessage $mail, string $slug): OneTimeToken
    {
        self::assertSame(1, preg_match(
            '~^' . preg_quote(self::BASE_URL, '~') . '/firefighting/' . $slug . '/einladung-annehmen#token=([A-Za-z0-9_-]{43})$~m',
            $mail->text,
            $m,
        ), $mail->text);

        return OneTimeToken::fromString($m[1]) ?? self::fail('Ungültiges Token im Link.');
    }
}
