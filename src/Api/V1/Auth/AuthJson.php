<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Auth;

use FirefightingEquipment\Auth\AuthSession;
use FirefightingEquipment\Auth\Session;
use FirefightingEquipment\Permission\Action;
use FirefightingEquipment\Permission\AreaLevel;
use FirefightingEquipment\Permission\Permissions;
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
            'permissions' => $auth->permissions === null ? null : self::permissions($auth->permissions),
        ];
    }

    /**
     * Wirksame Rechte der Rolle (Plan Phase 1, Abschnitt 5): Bereiche mit Stufe, Aktionen (nur die,
     * deren Bereich vorhanden ist; mit Datenfilter ohne Verwaltung, F3), Einschränkungen (erst ab
     * Phase 2, bis dahin leer), Datenfilter und eine Kennung des Stands. `version` ändert sich mit
     * der Rolle des Kontos, jeder Änderung der Rolle (fe_roles.version), den Rechten selbst (auch
     * einem erweiterten Katalog) und den Standorten bzw. Gerätetypen des Datenfilters (auch einem
     * neuen Fach eines gewählten Standorts).
     *
     * @return array{areas: \stdClass, actions: list<string>, restrictions: list<string>, filtered: bool, version: string}
     */
    public static function permissions(Permissions $permissions): array
    {
        $json = [
            // Als Objekt, auch ohne Bereiche ({} statt []).
            'areas' => (object) array_map(static fn (AreaLevel $level): string => $level->value, $permissions->areas()),
            'actions' => array_map(static fn (Action $action): string => $action->value, $permissions->actions()),
            'restrictions' => [],
            'filtered' => $permissions->isFiltered(),
        ];
        $json['version'] = hash('sha256', json_encode([$permissions->revision(), $permissions->visibility()->toArray(), $json], JSON_THROW_ON_ERROR));

        return $json;
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
