<?php

declare(strict_types=1);

namespace FirefightingEquipment\Auth;

use FirefightingEquipment\Http\AppPath;
use FirefightingEquipment\Http\Request;
use FirefightingEquipment\Tenant\Tenant;

/**
 * Sitzungs-Cookie ff_session: HttpOnly, Secure, SameSite=Strict, Pfad /firefighting/<kürzel>/
 * bzw. /firefighting/service/. So wird es nur an den eigenen Bereich gesendet.
 */
final class SessionCookie
{
    public const string NAME = 'ff_session';

    /**
     * @param Tenant|null $tenant null = Bereich service
     */
    public static function path(?Tenant $tenant): string
    {
        return AppPath::PREFIX . '/' . ($tenant?->slug->value ?? AppPath::SERVICE_SCOPE) . '/';
    }

    /**
     * Wert für den Header Set-Cookie.
     */
    public static function issue(?Tenant $tenant, SessionToken $token, int $maxAgeSeconds): string
    {
        return self::header($tenant, $token->cookieValue(), $maxAgeSeconds);
    }

    /**
     * Wert für den Header Set-Cookie, der das Cookie im Browser löscht.
     */
    public static function clear(?Tenant $tenant): string
    {
        return self::header($tenant, '', 0);
    }

    /**
     * @return SessionToken|null null ohne Cookie oder bei ungültigem Format
     */
    public static function tokenFrom(Request $request): ?SessionToken
    {
        $value = $request->cookies[self::NAME] ?? null;

        return $value === null ? null : SessionToken::fromCookie($value);
    }

    public static function isPresent(Request $request): bool
    {
        return isset($request->cookies[self::NAME]);
    }

    private static function header(?Tenant $tenant, string $value, int $maxAgeSeconds): string
    {
        return sprintf(
            '%s=%s; Path=%s; Max-Age=%d; HttpOnly; Secure; SameSite=Strict',
            self::NAME,
            $value,
            self::path($tenant),
            $maxAgeSeconds,
        );
    }
}
