<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http;

use FirefightingEquipment\Api\V1\Auth\EmailResendHandler;
use FirefightingEquipment\Api\V1\Auth\EmailVerifyHandler;
use FirefightingEquipment\Api\V1\Auth\LoginHandler;
use FirefightingEquipment\Api\V1\Auth\LogoutHandler;
use FirefightingEquipment\Api\V1\Auth\LogoutOthersHandler;
use FirefightingEquipment\Api\V1\Auth\MeHandler;
use FirefightingEquipment\Api\V1\Auth\PasswordChangeHandler;
use FirefightingEquipment\Api\V1\Auth\PasswordResetConfirmHandler;
use FirefightingEquipment\Api\V1\Auth\PasswordResetRequestHandler;
use FirefightingEquipment\Api\V1\Auth\RegisterHandler;
use FirefightingEquipment\Api\V1\Auth\SessionDeleteHandler;
use FirefightingEquipment\Api\V1\Auth\SessionListHandler;
use FirefightingEquipment\Api\V1\HealthHandler;
use FirefightingEquipment\Api\V1\Invitation\InvitationCodeCreateHandler;
use FirefightingEquipment\Api\V1\Invitation\InvitationCodeListHandler;
use FirefightingEquipment\Api\V1\Invitation\InvitationCodeRevokeHandler;
use FirefightingEquipment\Api\V1\TenantHandler;
use FirefightingEquipment\Api\V1\Users\UserAction;
use FirefightingEquipment\Api\V1\Users\UserActionHandler;
use FirefightingEquipment\Api\V1\Users\UserListHandler;

/**
 * Alle Routen der Anwendung. Neue Endpunkte werden hier eingetragen, jeweils mit Zugriffsstufe.
 */
final class Routes
{
    /**
     * Routen unter /firefighting/service/ (Super-Admin, keine Stadt).
     *
     * @param MinimumDuration|null $neutralDuration Mindestdauer der Antworten, die nicht verraten
     *                                              dürfen, ob ein Konto existiert
     *                                              (password-reset/request, register,
     *                                              email/resend; null =
     *                                              Standard der Handler, andere nur für Tests)
     */
    public static function service(?MinimumDuration $neutralDuration = null): Router
    {
        return self::auth(Router::forService(), $neutralDuration)
            ->add('GET', 'api/v1/health', Access::Public, new HealthHandler());
    }

    /**
     * Routen unter /firefighting/<kürzel>/ (nur aktive Städte).
     *
     * @param MinimumDuration|null $neutralDuration wie bei service()
     */
    public static function tenant(?MinimumDuration $neutralDuration = null): Router
    {
        return self::auth(Router::forTenant(), $neutralDuration)
            ->add('GET', 'api/v1/health', Access::Public, new HealthHandler())
            ->add('GET', 'api/v1/tenant', Access::Public, new TenantHandler())
            ->add('POST', 'api/v1/auth/register', Access::Public, self::withDuration(RegisterHandler::class, $neutralDuration))
            ->add('POST', 'api/v1/auth/email/verify', Access::Public, new EmailVerifyHandler())
            ->add('POST', 'api/v1/auth/email/resend', Access::Public, self::withDuration(EmailResendHandler::class, $neutralDuration))
            ->add('GET', 'api/v1/invitation-codes', Access::Admin, new InvitationCodeListHandler())
            ->add('POST', 'api/v1/invitation-codes', Access::Admin, new InvitationCodeCreateHandler())
            ->add('POST', 'api/v1/invitation-codes/{id}/revoke', Access::Admin, new InvitationCodeRevokeHandler())
            ->add('GET', 'api/v1/users', Access::Admin, new UserListHandler())
            ->add('GET', 'api/v1/users/{id}', Access::Admin, new UserActionHandler(UserAction::Show))
            ->add('DELETE', 'api/v1/users/{id}', Access::Admin, new UserActionHandler(UserAction::Delete))
            ->add('POST', 'api/v1/users/{id}/approve', Access::Admin, new UserActionHandler(UserAction::Approve))
            ->add('POST', 'api/v1/users/{id}/reject', Access::Admin, new UserActionHandler(UserAction::Reject))
            ->add('POST', 'api/v1/users/{id}/lock', Access::Admin, new UserActionHandler(UserAction::Lock))
            ->add('POST', 'api/v1/users/{id}/unlock', Access::Admin, new UserActionHandler(UserAction::Unlock))
            ->add('POST', 'api/v1/users/{id}/role', Access::Admin, new UserActionHandler(UserAction::ChangeRole))
            ->add('POST', 'api/v1/users/{id}/display-name', Access::Admin, new UserActionHandler(UserAction::ChangeDisplayName));
    }

    /** Anmeldung, Sitzungen und Passwort; unter service und unter jeder Stadt gleich. */
    private static function auth(Router $router, ?MinimumDuration $neutralDuration): Router
    {
        $resetRequest = self::withDuration(PasswordResetRequestHandler::class, $neutralDuration);

        return $router
            ->add('POST', 'api/v1/auth/login', Access::Public, new LoginHandler())
            ->add('POST', 'api/v1/auth/logout', Access::Public, new LogoutHandler())
            ->add('GET', 'api/v1/auth/me', Access::Authenticated, new MeHandler())
            ->add('GET', 'api/v1/auth/sessions', Access::Authenticated, new SessionListHandler())
            ->add('DELETE', 'api/v1/auth/sessions/{id}', Access::Authenticated, new SessionDeleteHandler())
            ->add('POST', 'api/v1/auth/sessions/logout-others', Access::Authenticated, new LogoutOthersHandler())
            ->add('POST', 'api/v1/auth/password', Access::Authenticated, new PasswordChangeHandler())
            ->add('POST', 'api/v1/auth/password-reset/request', Access::Public, $resetRequest)
            ->add('POST', 'api/v1/auth/password-reset/confirm', Access::Public, new PasswordResetConfirmHandler());
    }

    /**
     * Handler mit Mindestdauer: ohne Angabe sein Standard, sonst die übergebene (nur Tests).
     *
     * @template T of RequestHandler
     *
     * @param class-string<T> $handler Konstruktor mit einem optionalen MinimumDuration-Parameter
     *
     * @return T
     */
    private static function withDuration(string $handler, ?MinimumDuration $duration): RequestHandler
    {
        return $duration === null ? new $handler() : new $handler($duration);
    }
}
