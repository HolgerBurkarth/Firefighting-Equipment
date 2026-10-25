<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http;

use FirefightingEquipment\Api\V1\Auth\LoginHandler;
use FirefightingEquipment\Api\V1\Auth\LogoutHandler;
use FirefightingEquipment\Api\V1\Auth\LogoutOthersHandler;
use FirefightingEquipment\Api\V1\Auth\MeHandler;
use FirefightingEquipment\Api\V1\Auth\PasswordChangeHandler;
use FirefightingEquipment\Api\V1\Auth\PasswordResetConfirmHandler;
use FirefightingEquipment\Api\V1\Auth\PasswordResetRequestHandler;
use FirefightingEquipment\Api\V1\Auth\SessionDeleteHandler;
use FirefightingEquipment\Api\V1\Auth\SessionListHandler;
use FirefightingEquipment\Api\V1\HealthHandler;
use FirefightingEquipment\Api\V1\TenantHandler;

/**
 * Alle Routen der Anwendung. Neue Endpunkte werden hier eingetragen, jeweils mit Zugriffsstufe.
 */
final class Routes
{
    /**
     * Routen unter /firefighting/service/ (Super-Admin, keine Stadt).
     *
     * @param MinimumDuration|null $resetDuration Mindestdauer von auth/password-reset/request
     *                                            (null = Standard; andere nur für Tests)
     */
    public static function service(?MinimumDuration $resetDuration = null): Router
    {
        return self::auth(Router::forService(), $resetDuration)
            ->add('GET', 'api/v1/health', Access::Public, new HealthHandler());
    }

    /**
     * Routen unter /firefighting/<kürzel>/ (nur aktive Städte).
     *
     * @param MinimumDuration|null $resetDuration wie bei service()
     */
    public static function tenant(?MinimumDuration $resetDuration = null): Router
    {
        return self::auth(Router::forTenant(), $resetDuration)
            ->add('GET', 'api/v1/health', Access::Public, new HealthHandler())
            ->add('GET', 'api/v1/tenant', Access::Public, new TenantHandler());
    }

    /** Anmeldung, Sitzungen und Passwort; unter service und unter jeder Stadt gleich. */
    private static function auth(Router $router, ?MinimumDuration $resetDuration): Router
    {
        $resetRequest = $resetDuration === null
            ? new PasswordResetRequestHandler()
            : new PasswordResetRequestHandler($resetDuration);

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
}
