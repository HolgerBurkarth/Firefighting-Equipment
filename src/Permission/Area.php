<?php

declare(strict_types=1);

namespace FirefightingEquipment\Permission;

/**
 * Bereiche des Rechte-Katalogs (Plan Phase 1, Abschnitt 2): je Rolle kein Zugriff, Ansehen oder
 * Ändern (fe_role_areas). Werte wie in der Datenbank; neue Bereiche kommen mit ihrer Phase, nie
 * ohne geprüfte Route.
 */
enum Area: string
{
    case Dashboard = 'dashboard';
    case Search = 'search';
    case Devices = 'devices';
    case Locations = 'locations';
    case DeviceTypes = 'device_types';
    case Due = 'due';
    case Log = 'log';
    case Users = 'users';
    case Settings = 'settings';

    public function label(): string
    {
        return match ($this) {
            self::Dashboard => 'Dashboard',
            self::Search => 'Suchen und Ausrüstung ansehen',
            self::Devices => 'Ausrüstungsliste',
            self::Locations => 'Standorte',
            self::DeviceTypes => 'Ausrüstungstypen',
            self::Due => 'Termine',
            self::Log => 'Protokoll und Hinweise',
            self::Users => 'Benutzer',
            self::Settings => 'Einstellungen der Stadt',
        };
    }

    /** Bereiche ohne Stufe „Ändern“ (Mockup „Rolle bearbeiten“). */
    public function maxLevel(): AreaLevel
    {
        return match ($this) {
            self::Dashboard, self::Search => AreaLevel::Read,
            default => AreaLevel::Write,
        };
    }
}
