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
    case Maintenance = 'maintenance';
    case Loans = 'loans';

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
            self::Maintenance => 'Wartung',
            self::Loans => 'Verleih und Rückgaben',
        };
    }

    /** Erklärung im Editor der Rollen (Mockup „Rolle bearbeiten“, nur was es in dieser Phase gibt). */
    public function hint(): string
    {
        return match ($this) {
            self::Dashboard => 'Kacheln und letzte Aktivitäten',
            self::Search => 'Handy-Seiten, Scan, Umbuchen-Ansicht',
            self::Devices => 'Konsole: Liste, Detailseite, Verlauf',
            self::Locations => 'Fahrzeuge, Fächer, Räume',
            self::DeviceTypes => 'Typen der Ausrüstung',
            self::Due => 'Fällige und überfällige Ausrüstung',
            self::Log => 'Hinweise erledigen, Verlauf exportieren',
            self::Users => 'Konten, Rollen zuweisen, Einladungscodes, Aushang',
            self::Settings => 'Farbe und Logo',
            self::Maintenance => 'Sammelstelle und Werkstatt: Ausrüstung zur Wartung bringen und zurückholen',
            self::Loans => 'Ausrüstung verleihen, Verliehenes ansehen, Rückgaben bearbeiten',
        };
    }

    /**
     * Aufgaben von Standorten (Phase 2), von denen mindestens eine für die Stadt freigeschaltet sein
     * muss, damit es den Bereich gibt (sonst bleibt alles wie vor Phase 2). Leer = immer.
     *
     * @return list<\FirefightingEquipment\Device\LocationTask>
     */
    public function requiredTasks(): array
    {
        return match ($this) {
            self::Maintenance => [\FirefightingEquipment\Device\LocationTask::Collection, \FirefightingEquipment\Device\LocationTask::Workshop],
            self::Loans => [\FirefightingEquipment\Device\LocationTask::Borrower, \FirefightingEquipment\Device\LocationTask::Returns],
            default => [],
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
