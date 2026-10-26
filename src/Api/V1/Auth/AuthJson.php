<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Auth;

use FirefightingEquipment\Auth\AuthSession;
use FirefightingEquipment\Auth\Session;
use FirefightingEquipment\Tenant\Tenant;

/**
 * JSON-Darstellung von Konto und Sitzung. Zeiten in UTC als "2026-10-24T08:15:00Z".
 */
final class AuthJson
{
    /**
     * Antwort von auth/login und auth/me.
     *
     * @return array<string, mixed>
     */
    public static function me(AuthSession $auth, ?Tenant $tenant): array
    {
        $user = $auth->user;

        return [
            'user' => [
                'id' => $user->id,
                'email' => $user->email?->value,
                'name' => $user->name?->value,
                'display_name' => $user->displayName?->value,
                'role' => $user->role->value,
                'protected' => $user->protected,
                'notifications' => NotificationSettingsHandler::json($user)['notifications'],
            ],
            'tenant' => $tenant === null ? null : ['slug' => $tenant->slug->value, 'name' => $tenant->name->value],
            'session' => self::session($auth->session, $auth->session->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function session(Session $session, int $currentId): array
    {
        return [
            'id' => $session->id,
            'device_label' => $session->deviceLabel,
            'created_at' => self::time($session->createdAt),
            'last_seen_at' => self::time($session->lastSeenAt),
            'expires_at' => self::time($session->expiresAt),
            'current' => $session->id === $currentId,
        ];
    }

    public static function time(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
