<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Auth;

use FirefightingEquipment\Api\V1\Auth\InvitationAcceptHandler;
use FirefightingEquipment\Auth\InvitationAcceptThrottle;
use FirefightingEquipment\Auth\LoginThrottle;
use FirefightingEquipment\Auth\TokenPurpose;
use FirefightingEquipment\Auth\UserTokenRepository;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Tests\Support\FakeMailer;
use FirefightingEquipment\User\AdminInvitation;
use FirefightingEquipment\User\EmailAddress;
use FirefightingEquipment\User\UserRepository;
use FirefightingEquipment\User\UserStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Einladung als Admin annehmen (POST auth/invitation/accept): Konto aktiv, Link verbraucht, keine
 * Sitzung, Hinweis an die Super-Admins; ungültige Links in allen Varianten, Bremse.
 */
#[CoversClass(InvitationAcceptHandler::class)]
#[CoversClass(InvitationAcceptThrottle::class)]
final class InvitationAcceptTest extends AuthTestCase
{
    private const string NEW_PASSWORD = 'Schlauch-Leiter-Wasser-7';

    public function testAcceptActivatesAccountWithoutSession(): void
    {
        $invitation = $this->invite('immendingen', 'kommandant@example.org');

        $response = $this->accept('immendingen', $invitation->token->value());

        self::assertSame(200, $response->statusCode(), $response->body());
        self::assertSame(InvitationAcceptHandler::MESSAGE, self::json($response)['message']);
        self::assertArrayNotHasKey('Set-Cookie', $response->headers(), 'keine automatische Anmeldung');
        $user = new UserRepository($this->pdo())->findById($invitation->user->id);
        self::assertSame(UserStatus::Active, $user?->status);
        self::assertSame('Erika Musterfrau', $user->name?->value);
        self::assertSame('Erika Musterfrau', $user->displayName?->value);
        self::assertNotNull($user->emailVerifiedAt);
        self::assertSame(0, $this->countRows('fe_user_tokens'));
        self::assertSame(0, $this->sessionCountOf($invitation->user->id));

        self::assertSame(200, $this->login('immendingen', 'kommandant@example.org', self::NEW_PASSWORD)->statusCode());
    }

    public function testSuperAdminsAreNotified(): void
    {
        $this->account(null, 'chef@example.org');
        $this->account(null, 'gesperrt@example.org', ['locked_at' => self::CREATED_AT]);
        $this->account(null, 'vertretung@example.org');
        $this->account('immendingen', 'admin@example.org', ['role' => 'admin']);
        $invitation = $this->invite('immendingen', 'kommandant@example.org');

        self::assertSame(200, $this->accept('immendingen', $invitation->token->value())->statusCode());

        self::assertSame(['chef@example.org', 'vertretung@example.org'], array_map(static fn ($m): string => $m->to->value, $this->mailer->sent));
        $mail = $this->mailer->sent[0];
        self::assertSame('Einladung angenommen – Feuerwehr immendingen', $mail->subject);
        self::assertStringContainsString("\nName: Erika Musterfrau\nE-Mail: kommandant@example.org\n", $mail->text);
        self::assertStringContainsString("\n" . self::BASE_URL . "/firefighting/service/\n", $mail->text);
    }

    public function testMailFailureDoesNotChangeResponse(): void
    {
        $this->account(null, 'chef@example.org');
        $invitation = $this->invite('immendingen', 'kommandant@example.org');
        $this->mailer = new FakeMailer(fail: true);

        self::assertSame(200, $this->accept('immendingen', $invitation->token->value())->statusCode());
        self::assertStringContainsString('mail_failed (invitation_accepted, Konto #', $this->log[0]);
    }

    public function testUsedLinkIsRejected(): void
    {
        $invitation = $this->invite('immendingen', 'kommandant@example.org');
        self::assertSame(200, $this->accept('immendingen', $invitation->token->value())->statusCode());

        self::assertError(400, 'invalid_token', $this->accept('immendingen', $invitation->token->value(), password: 'Andere-Leiter-Pumpe-8'));
        self::assertSame(200, $this->login('immendingen', 'kommandant@example.org', self::NEW_PASSWORD)->statusCode());
    }

    public function testExpiredLinkIsRejectedAndDeleted(): void
    {
        $invitation = $this->invite('immendingen', 'kommandant@example.org');
        $this->pdo()->exec('UPDATE fe_user_tokens SET created_at = created_at - INTERVAL 86401 SECOND, expires_at = expires_at - INTERVAL 86401 SECOND');

        self::assertError(400, 'invalid_token', $this->accept('immendingen', $invitation->token->value()));
        self::assertSame(0, $this->countRows('fe_user_tokens'), 'beim Aufruf gelöscht');
        self::assertSame(UserStatus::Invited, new UserRepository($this->pdo())->findById($invitation->user->id)?->status);
    }

    public function testRenewedLinkReplacesOldOne(): void
    {
        $invitation = $this->invite('immendingen', 'kommandant@example.org');
        $renewed = new UserRepository($this->pdo())->renewInvitation($invitation->user->id);

        self::assertError(400, 'invalid_token', $this->accept('immendingen', $invitation->token->value()));
        self::assertSame(200, $this->accept('immendingen', $renewed->value())->statusCode());
    }

    public function testLinkForOtherPurposeIsRejected(): void
    {
        $invitation = $this->invite('immendingen', 'kommandant@example.org');
        $other = new UserTokenRepository($this->pdo())->replace($invitation->user->id, TokenPurpose::VerifyEmail);

        self::assertError(400, 'invalid_token', $this->accept('immendingen', $other->value()));
        self::assertSame(UserStatus::Invited, new UserRepository($this->pdo())->findById($invitation->user->id)?->status);
    }

    public function testSetPasswordLinkOfNonInvitedAccountIsRejected(): void
    {
        $id = $this->account('immendingen', 'max@example.org');
        $token = new UserTokenRepository($this->pdo())->replace($id, TokenPurpose::SetPassword);

        self::assertError(400, 'invalid_token', $this->accept('immendingen', $token->value()));
        self::assertSame(1, $this->countRows('fe_user_tokens'));
    }

    public function testLinkOfOtherTenantIsRejected(): void
    {
        $invitation = $this->invite('tuttlingen', 'kommandant@example.org');

        self::assertError(400, 'invalid_token', $this->accept('immendingen', $invitation->token->value()));
        self::assertError(404, 'not_found', $this->accept(null, $invitation->token->value()), 'nicht unter service');
        self::assertSame(200, $this->accept('tuttlingen', $invitation->token->value())->statusCode());
    }

    public function testLockedTenantIsRejectedBeforeTheLink(): void
    {
        $invitation = $this->invite('immendingen', 'kommandant@example.org');
        $this->pdo()->exec("UPDATE fe_tenants SET active = 0 WHERE slug = 'immendingen'");

        self::assertError(403, 'tenant_inactive', $this->accept('immendingen', $invitation->token->value()));
        self::assertSame(0, $this->countRows('fe_auth_attempts'), 'nicht gezählt');

        $this->pdo()->exec("UPDATE fe_tenants SET active = 1 WHERE slug = 'immendingen'");
        self::assertSame(200, $this->accept('immendingen', $invitation->token->value())->statusCode(), 'nach dem Entsperren gültig');
    }

    public function testLockedAccountIsRejected(): void
    {
        $invitation = $this->invite('immendingen', 'kommandant@example.org');
        new UserRepository($this->pdo())->lock($invitation->user->id);

        self::assertError(400, 'invalid_token', $this->accept('immendingen', $invitation->token->value()));
        self::assertSame(1, $this->countRows('fe_user_tokens'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badTokens(): iterable
    {
        yield 'leer' => [''];
        yield 'zu kurz' => ['abc'];
        yield 'unbekannt' => [str_repeat('A', 43)];
    }

    #[DataProvider('badTokens')]
    public function testMalformedOrUnknownTokenIsRejectedAndCounted(string $token): void
    {
        self::assertError(400, 'invalid_token', $this->accept('immendingen', $token));
        self::assertSame(1, (int) $this->pdo()->query("SELECT COUNT(*) FROM fe_auth_attempts WHERE action = 'invitation_accept' AND succeeded = 0 AND email_hash IS NULL")->fetchColumn());
    }

    public function testWeakPasswordOrInvalidNameKeepsLinkValid(): void
    {
        $invitation = $this->invite('immendingen', 'kommandant@example.org');

        self::assertError(422, 'weak_password', $this->accept('immendingen', $invitation->token->value(), password: 'kurz'));
        self::assertError(422, 'weak_password', $this->accept('immendingen', $invitation->token->value(), password: 'Musterfrau-Erika-1'), 'Name im Passwort');
        self::assertError(422, 'weak_password', $this->accept('immendingen', $invitation->token->value(), password: 'kommandant-geraete'), 'E-Mail im Passwort');
        self::assertError(422, 'weak_password', $this->accept('immendingen', $invitation->token->value(), password: 'immendingen-2026!'), 'Stadt im Passwort');
        self::assertError(400, 'invalid_request', $this->accept('immendingen', $invitation->token->value(), name: '   '));

        self::assertSame(1, $this->countRows('fe_user_tokens'));
        self::assertSame(0, $this->countRows('fe_auth_attempts'), 'nicht als Fehlversuch gezählt');
        self::assertSame(200, $this->accept('immendingen', $invitation->token->value())->statusCode());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'ohne Token' => [['name' => 'Erika', 'password' => self::NEW_PASSWORD]];
        yield 'ohne Name' => [['token' => str_repeat('A', 43), 'password' => self::NEW_PASSWORD]];
        yield 'ohne Passwort' => [['token' => str_repeat('A', 43), 'name' => 'Erika']];
        yield 'Name keine Zeichenkette' => [['token' => str_repeat('A', 43), 'name' => 5, 'password' => self::NEW_PASSWORD]];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidBodies')]
    public function testInvalidBodyIs400(array $body): void
    {
        self::assertError(400, 'invalid_request', $this->send('POST', self::url('immendingen', 'auth/invitation/accept'), $body));
        self::assertSame(0, $this->countRows('fe_auth_attempts'));
    }

    public function testFailuresAreThrottledPerIp(): void
    {
        $invitation = $this->invite('immendingen', 'kommandant@example.org');
        for ($i = 0; $i < InvitationAcceptThrottle::MAX_FAILURES_PER_IP; $i++) {
            self::assertError(400, 'invalid_token', $this->accept($i % 2 === 0 ? 'tuttlingen' : 'immendingen', str_repeat('A', 43)));
        }

        $response = $this->accept('immendingen', $invitation->token->value());

        self::assertError(429, 'too_many_attempts', $response);
        $retryAfter = (int) $response->headers()['Retry-After'];
        self::assertGreaterThan(800, $retryAfter);
        self::assertLessThanOrEqual(900, $retryAfter);
        self::assertSame(1, $this->countRows('fe_user_tokens'), 'gültiger Link bleibt erhalten');
        self::assertSame(InvitationAcceptThrottle::MAX_FAILURES_PER_IP, $this->countRows('fe_auth_attempts'), 'Abgewiesenes nicht gespeichert');

        $this->pdo()->exec("UPDATE fe_auth_attempts SET attempted_at = attempted_at - INTERVAL 901 SECOND WHERE action = 'invitation_accept'");
        self::assertSame(200, $this->accept('immendingen', $invitation->token->value())->statusCode());
    }

    public function testAcceptLiftsLoginThrottleOfAccount(): void
    {
        $invitation = $this->invite('immendingen', 'kommandant@example.org');
        for ($i = 0; $i < LoginThrottle::MAX_FAILURES_PER_ACCOUNT; $i++) {
            $this->login('immendingen', 'kommandant@example.org', 'Falsches-Passwort-99');
        }
        self::assertError(429, 'too_many_attempts', $this->login('immendingen', 'kommandant@example.org', self::NEW_PASSWORD));

        self::assertSame(200, $this->accept('immendingen', $invitation->token->value())->statusCode());

        self::assertSame(200, $this->login('immendingen', 'kommandant@example.org', self::NEW_PASSWORD)->statusCode());
    }

    private function invite(string $slug, string $email): AdminInvitation
    {
        return new UserRepository($this->pdo())->inviteAdmin($this->tenant($slug), EmailAddress::fromString($email));
    }

    /**
     * @param string|null $slug null = service
     */
    private function accept(?string $slug, string $token, string $name = 'Erika Musterfrau', string $password = self::NEW_PASSWORD): JsonResponse
    {
        return $this->send('POST', self::url($slug, 'auth/invitation/accept'), ['token' => $token, 'name' => $name, 'password' => $password]);
    }
}
