<?php

declare(strict_types=1);

namespace FirefightingEquipment\Permission;

/**
 * Aktionen des Rechte-Katalogs (Plan Phase 1, Abschnitt 2; fe_role_actions). Jede Aktion nennt
 * den Bereich, den sie braucht; fehlt er, wirkt sie nicht. Neue Aktionen kommen mit ihrer Phase,
 * nie ohne geprüfte Route (CSV-Export seit Schritt 1.7a, Plan, F2).
 */
enum Action: string
{
    case BookMove = 'book.move';
    case BookStatus = 'book.status';
    case DeviceCreate = 'device.create';
    case DeviceEdit = 'device.edit';
    case DeviceRetire = 'device.retire';
    case DeviceCode = 'device.code';
    case DevicesBulk = 'devices.bulk';
    case CsvImport = 'csv.import';
    case CsvExport = 'csv.export';
    case MailLog = 'mail.log';
    case DeviceUsed = 'device.used';

    public function label(): string
    {
        return match ($this) {
            self::BookMove => 'Umbuchen',
            self::BookStatus => 'Status setzen',
            self::DeviceCreate => 'Ausrüstung anlegen',
            self::DeviceEdit => 'Stammdaten bearbeiten',
            self::DeviceRetire => 'Aussondern und wieder aufnehmen',
            self::DeviceCode => 'Code zuordnen',
            self::DevicesBulk => 'Sammelaktionen',
            self::CsvImport => 'CSV-Import',
            self::CsvExport => 'CSV-Export',
            self::MailLog => 'Log-Mails empfangen',
            self::DeviceUsed => 'Benutzt melden',
        };
    }

    public function group(): ActionGroup
    {
        return match ($this) {
            self::BookMove, self::BookStatus, self::DeviceUsed => ActionGroup::Booking,
            self::DeviceCreate, self::DeviceEdit, self::DeviceRetire, self::DeviceCode => ActionGroup::Devices,
            self::DevicesBulk, self::CsvImport, self::CsvExport => ActionGroup::Bulk,
            self::MailLog => ActionGroup::Notifications,
        };
    }

    /**
     * Bereich und Stufe, ohne die die Aktion nicht wirkt; null = keiner.
     *
     * @return array{Area, AreaLevel}|null
     */
    public function requires(): ?array
    {
        return match ($this) {
            self::BookMove, self::BookStatus, self::DeviceUsed => [Area::Search, AreaLevel::Read],
            self::DeviceCreate, self::DeviceEdit, self::DeviceRetire, self::DeviceCode,
            self::DevicesBulk, self::CsvImport => [Area::Devices, AreaLevel::Write],
            self::CsvExport => [Area::Devices, AreaLevel::Read],
            self::MailLog => null,
        };
    }
}
