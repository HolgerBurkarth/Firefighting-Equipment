<?php

declare(strict_types=1);

namespace FirefightingEquipment\Role;

/**
 * Standard-Rollen jeder Stadt. Neue Städte erhalten sie beim Anlegen (TenantRepository::create über
 * RoleRepository::createDefaults), bestehende hat Migration 0034 mit denselben Werten ergänzt; beide
 * Stellen immer gemeinsam ändern (per Test gleich).
 */
final class DefaultRoles
{
    public const string ADMIN_NAME = 'Admin';
    public const string ADMIN_DESCRIPTION = 'Alle Rechte, nicht änderbar';

    public const string USER_NAME = 'Benutzer';
    public const string USER_DESCRIPTION = 'Wie bisher; bekommt jede neue Stadt';

    /**
     * Rechte des bisherigen Kontos "user": Suchen und Termine ansehen, umbuchen, Status setzen. Die
     * Rolle Admin hat keine Zeilen (alle Rechte, auch künftige).
     *
     * @var array<string, 'read'|'write'>
     */
    public const array USER_AREAS = ['due' => 'read', 'search' => 'read'];

    /** @var list<string> */
    public const array USER_ACTIONS = ['book.move', 'book.status'];

    private function __construct()
    {
    }
}
