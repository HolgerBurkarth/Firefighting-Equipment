<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Import;

use FirefightingEquipment\Device\DeviceStatus;
use FirefightingEquipment\Device\DeviceType;
use FirefightingEquipment\Device\Location;
use FirefightingEquipment\Time\GermanFormat;
use FirefightingEquipment\Time\LocalDate;

/**
 * Hilfen zum Erfassen per CSV (KONZEPT.md, "Admin-Konsole – Entscheidungen", 32):
 *
 * - Vorlage: die Spalten des Imports (ImportColumn, wie der Export) und zwei Beispielzeilen mit den
 *   Kennungen EXAMPLE_IDENTIFIERS, mit Gerätetypen und Standorten der Stadt, damit sie gültig
 *   aussehen. Die Vorschau warnt, solange sie noch in der Datei stehen (isExample).
 * - Gültige Werte: aktive Gerätetypen, aktive Standorte (Pfad wie im Import), die Status und die
 *   Formate. Eine eigene Datei, denn in der Vorlage würden diese Zeilen den Import stören.
 */
final class DeviceImportTemplate
{
    public const array EXAMPLE_IDENTIFIERS = ['BEISPIEL-1', 'BEISPIEL-2'];

    public const array VALUES_HEADER = ['Spalte', 'Gültiger Wert'];

    private const string EXAMPLE_NOTE = 'Beispielzeile – vor dem Import löschen';

    private function __construct()
    {
    }

    /**
     * Überschriften der Vorlage (= DeviceCsv::DEVICE_HEADER, per Test).
     *
     * @return list<string>
     */
    public static function header(): array
    {
        return array_map(static fn (ImportColumn $c): string => $c->label(), ImportColumn::cases());
    }

    /**
     * Die beiden Beispielzeilen.
     *
     * @param list<DeviceType> $types aktive Gerätetypen
     * @param list<Location>   $tree  aktive Standorte als Baum
     * @param string           $today JJJJ-MM-TT (deutsche Zeit)
     *
     * @return list<list<string|null>>
     */
    public static function exampleRows(array $types, array $tree, string $today): array
    {
        $paths = self::paths($tree);
        $topLevel = array_values(array_filter($paths, static fn (array $p): bool => $p[1] === null));
        $children = array_values(array_filter($paths, static fn (array $p): bool => $p[1] !== null));

        return [
            self::row([
                ImportColumn::Identifier->value => self::EXAMPLE_IDENTIFIERS[0],
                ImportColumn::DeviceType->value => ($types[0] ?? null)?->name->value,
                ImportColumn::Location->value => $topLevel[0][0] ?? $paths[0][0] ?? null,
                ImportColumn::Status->value => DeviceStatus::Ready->label(),
                ImportColumn::NextInspectionDue->value => GermanFormat::date(LocalDate::addDays($today, 365)),
                ImportColumn::SerialNumber->value => 'SN-12345',
                ImportColumn::Manufacturer->value => 'Beispiel GmbH',
                ImportColumn::Model->value => 'Modell A',
                ImportColumn::ProcuredOn->value => GermanFormat::date($today),
                ImportColumn::Note->value => self::EXAMPLE_NOTE,
            ]),
            self::row([
                ImportColumn::Identifier->value => self::EXAMPLE_IDENTIFIERS[1],
                ImportColumn::DeviceType->value => ($types[1] ?? $types[0] ?? null)?->name->value,
                ImportColumn::Location->value => $children[0][0] ?? $topLevel[1][0] ?? $paths[0][0] ?? null,
                ImportColumn::Status->value => DeviceStatus::Maintenance->label(),
                ImportColumn::Note->value => self::EXAMPLE_NOTE,
            ]),
        ];
    }

    /**
     * Eine Kennung der Beispielzeilen (ohne Groß- und Kleinschreibung)?
     */
    public static function isExample(string $identifier): bool
    {
        $identifier = mb_strtolower(trim($identifier));
        foreach (self::EXAMPLE_IDENTIFIERS as $example) {
            if (mb_strtolower($example) === $identifier) {
                return true;
            }
        }

        return false;
    }

    /**
     * Zeilen der Datei „Gültige Werte“ (Spalten VALUES_HEADER).
     *
     * @param list<DeviceType> $types aktive Gerätetypen
     * @param list<Location>   $tree  aktive Standorte als Baum
     *
     * @return list<list<string>>
     */
    public static function validValues(array $types, array $tree): array
    {
        $rows = [[ImportColumn::Identifier->label(), 'Pflicht, höchstens 30 Zeichen: Buchstaben, Ziffern, Leerzeichen und - / .']];
        foreach ($types as $type) {
            $rows[] = [ImportColumn::DeviceType->label(), $type->name->value];
        }
        foreach (self::paths($tree) as [$path]) {
            $rows[] = [ImportColumn::Location->label(), $path];
        }
        foreach ([DeviceStatus::Ready, DeviceStatus::Maintenance, DeviceStatus::Defective] as $status) {
            $rows[] = [ImportColumn::Status->label(), $status->label()];
        }
        foreach ([ImportColumn::NextInspectionDue, ImportColumn::ProcuredOn] as $column) {
            $rows[] = [$column->label(), 'Datum TT.MM.JJJJ, z. B. 01.03.2027'];
        }

        return $rows;
    }

    /**
     * Pfade aller Standorte des Baums wie im Import („HLF 20“, „HLF 20 / G1“), je Unterstandort mit
     * seinem eigenen Namen.
     *
     * @param list<Location> $tree
     *
     * @return list<array{string, ?string}>
     */
    public static function paths(array $tree): array
    {
        $paths = [];
        foreach ($tree as $location) {
            $paths[] = [$location->name->value, null];
            foreach ($location->children as $child) {
                $paths[] = [$location->name->value . ' / ' . $child->name->value, $child->name->value];
            }
        }

        return $paths;
    }

    /**
     * @param array<string, string|null> $values ImportColumn-Wert => Inhalt
     *
     * @return list<string|null>
     */
    private static function row(array $values): array
    {
        return array_map(static fn (ImportColumn $c): ?string => $values[$c->value] ?? null, ImportColumn::cases());
    }
}
