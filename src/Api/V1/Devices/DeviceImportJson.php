<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Devices;

use FirefightingEquipment\Device\Import\DeviceImportFile;
use FirefightingEquipment\Device\Import\DeviceImportPlan;
use FirefightingEquipment\Device\Import\ImportChange;
use FirefightingEquipment\Device\Import\ImportColumn;
use FirefightingEquipment\Device\Import\ImportResult;
use FirefightingEquipment\Device\Import\ImportRowPlan;
use FirefightingEquipment\Device\Import\UnknownImportValue;

/**
 * JSON des CSV-Imports. Werte der Änderungen in der Schreibweise der Datei (Namen, Pfade, deutsche
 * Statusbezeichnungen, Datum TT.MM.JJJJ). "unknown" nennt unbekannte Gerätetypen und Standorte,
 * "suggestions" zu denen mit ähnlichen gültigen Werten die Vorschläge („Meinten Sie …?“).
 */
final class DeviceImportJson
{
    private function __construct()
    {
    }

    /**
     * @return array<string, mixed>
     */
    public static function preview(DeviceImportFile $file, DeviceImportPlan $plan): array
    {
        return [
            'token' => $plan->token,
            'encoding' => $file->encoding,
            'delimiter' => $file->delimiter,
            'delimiter_label' => match ($file->delimiter) {
                ';' => 'Semikolon',
                ',' => 'Komma',
                default => 'Tabulator',
            },
            'columns' => array_map(static fn (ImportColumn $c): string => $c->label(), $file->columns),
            'summary' => self::summary($plan),
            'unknown' => [
                'device_types' => self::values($plan->unknownDeviceTypes),
                'locations' => self::values($plan->unknownLocations),
            ],
            'suggestions' => [
                'device_types' => self::suggestions($plan->unknownDeviceTypes),
                'locations' => self::suggestions($plan->unknownLocations),
            ],
            'rows' => array_map(self::row(...), $plan->rows),
        ];
    }

    /**
     * @return array<string, int>
     */
    public static function summary(DeviceImportPlan $plan): array
    {
        return [
            'rows' => count($plan->rows),
            'new' => $plan->count(ImportResult::New),
            'changed' => $plan->count(ImportResult::Changed),
            'unchanged' => $plan->count(ImportResult::Unchanged),
            'skipped' => $plan->count(ImportResult::Skipped),
            'errors' => $plan->count(ImportResult::Error),
            'warnings' => $plan->warningCount(),
        ];
    }

    /**
     * @param list<UnknownImportValue> $unknown
     *
     * @return list<string>
     */
    private static function values(array $unknown): array
    {
        return array_map(static fn (UnknownImportValue $u): string => $u->value, $unknown);
    }

    /**
     * Nur unbekannte Werte mit Vorschlag („Meinten Sie …?“), in derselben Reihenfolge.
     *
     * @param list<UnknownImportValue> $unknown
     *
     * @return list<array{value: string, suggestions: list<string>}>
     */
    private static function suggestions(array $unknown): array
    {
        return array_values(array_map(
            static fn (UnknownImportValue $u): array => ['value' => $u->value, 'suggestions' => $u->suggestions],
            array_filter($unknown, static fn (UnknownImportValue $u): bool => $u->suggestions !== []),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(ImportRowPlan $row): array
    {
        return [
            'line' => $row->line,
            'identifier' => $row->identifier,
            'result' => $row->result->value,
            'result_label' => $row->result->label(),
            'device' => $row->device === null ? null : ['id' => $row->device->id, 'version' => $row->device->version],
            'changes' => array_map(static fn (ImportChange $c): array => [
                'field' => $c->column->value,
                'label' => $c->column->label(),
                'old' => $c->old,
                'new' => $c->new,
            ], $row->changes),
            'messages' => $row->messages,
            'warnings' => $row->warnings,
        ];
    }
}
