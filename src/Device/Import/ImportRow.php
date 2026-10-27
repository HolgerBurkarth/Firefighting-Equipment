<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Import;

use FirefightingEquipment\Csv\CsvFormat;
use FirefightingEquipment\Device\DeviceDate;
use FirefightingEquipment\Device\DeviceException;
use FirefightingEquipment\Device\DeviceIdentifier;
use FirefightingEquipment\Device\DeviceStatus;
use FirefightingEquipment\Device\DeviceText;
use FirefightingEquipment\Device\ScanCode;

/**
 * Eine Zeile der Import-Datei mit geprüften Werten (noch ohne Datenbank). Fehlt eine Spalte, bleibt
 * das Feld unverändert; eine leere Zelle leert ein optionales Feld. Gerätetyp, Standort und Status
 * sind Pflichtfelder: leer = nicht angegeben (bestehendes Gerät: unverändert; neues: Fehler bzw.
 * Status einsatzbereit). Status „ausgesondert“ markiert die Zeile als übersprungen.
 */
final readonly class ImportRow
{
    /** Spalten mit optionalem Wert (null = leeren) */
    private const array OPTIONAL = [
        ImportColumn::ScanCode, ImportColumn::NextInspectionDue, ImportColumn::SerialNumber, ImportColumn::Manufacturer,
        ImportColumn::Model, ImportColumn::ProcuredOn, ImportColumn::Note,
    ];

    /**
     * @param array<string, string|DeviceDate|ScanCode|null> $values Spalte (ImportColumn-Wert) => Wert
     *                                                               der vorhandenen optionalen Spalten
     * @param list<string>                                   $errors Meldungen zu ungültigen Werten
     */
    private function __construct(
        public int $line,
        /** Kennung wie in der Datei (ohne Formelschutz, getrimmt), für Meldungen */
        public string $identifierText,
        public ?DeviceIdentifier $identifier,
        /** Status „ausgesondert“: Zeile wird übersprungen */
        public bool $retired,
        /** Name wie in der Datei; null = nicht angegeben */
        public ?string $deviceType,
        /** Pfad wie in der Datei; null = nicht angegeben */
        public ?string $location,
        /** null = nicht angegeben */
        public ?DeviceStatus $status,
        private array $values,
        public array $errors,
    ) {
    }

    /**
     * @param array<string, string> $cells Spalte (ImportColumn-Wert) => Zelle, nur vorhandene Spalten
     */
    public static function fromCells(int $line, array $cells): self
    {
        $errors = [];
        $cell = static fn (ImportColumn $c): ?string => isset($cells[$c->value]) ? CsvFormat::removeFormulaGuard($cells[$c->value]) : null;

        $identifierText = trim($cell(ImportColumn::Identifier) ?? '');
        $identifier = null;
        if ($identifierText === '') {
            $errors[] = 'Die Kennung fehlt.';
        } else {
            try {
                $identifier = DeviceIdentifier::fromString($identifierText);
            } catch (DeviceException $e) {
                $errors[] = $e->getMessage();
            }
        }

        $status = null;
        $retired = false;
        $statusText = trim($cell(ImportColumn::Status) ?? '');
        if ($statusText !== '') {
            $status = self::status($statusText);
            if ($status === DeviceStatus::Retired) {
                $retired = true;
                $status = null;
            } elseif ($status === null) {
                $errors[] = sprintf('Unbekannter Status „%s“ (möglich: einsatzbereit, in Wartung, defekt).', $statusText);
            }
        }

        $values = [];
        foreach (self::OPTIONAL as $column) {
            $value = $cell($column);
            if ($value === null) {
                continue;
            }
            try {
                $values[$column->value] = self::optional($column, $value);
            } catch (DeviceException $e) {
                $errors[] = $e->getMessage();
            }
        }

        return new self(
            $line,
            $identifierText,
            $identifier,
            $retired,
            self::name($cell(ImportColumn::DeviceType)),
            self::name($cell(ImportColumn::Location)),
            $status,
            $values,
            $errors,
        );
    }

    /**
     * Zeile mit falscher Anzahl Felder: nur der Fehler, keine Werte.
     */
    public static function invalid(int $line, string $identifierText, string $error): self
    {
        return new self($line, trim(CsvFormat::removeFormulaGuard($identifierText)), null, false, null, null, null, [], [$error]);
    }

    /**
     * Ob die optionale Spalte in der Datei vorkommt (dann wird der Wert übernommen, auch leer).
     */
    public function has(ImportColumn $column): bool
    {
        return array_key_exists($column->value, $this->values);
    }

    public function text(ImportColumn $column): ?string
    {
        $value = $this->values[$column->value] ?? null;

        return is_string($value) ? $value : null;
    }

    public function date(ImportColumn $column): ?DeviceDate
    {
        $value = $this->values[$column->value] ?? null;

        return $value instanceof DeviceDate ? $value : null;
    }

    public function scanCode(): ?ScanCode
    {
        $value = $this->values[ImportColumn::ScanCode->value] ?? null;

        return $value instanceof ScanCode ? $value : null;
    }

    private static function status(string $text): ?DeviceStatus
    {
        $text = mb_strtolower($text);
        foreach (DeviceStatus::cases() as $status) {
            if (mb_strtolower($status->label()) === $text) {
                return $status;
            }
        }

        return null;
    }

    /**
     * @throws DeviceException
     */
    private static function optional(ImportColumn $column, string $value): string|DeviceDate|ScanCode|null
    {
        return match ($column) {
            ImportColumn::ScanCode => ScanCode::fromString($value),
            ImportColumn::NextInspectionDue => trim($value) === '' ? null : ImportDate::parse(trim($value), 'Das Datum der nächsten Prüfung'),
            ImportColumn::ProcuredOn => trim($value) === '' ? null : ImportDate::parse(trim($value), 'Das Beschaffungsdatum'),
            default => DeviceText::field($value, $column->deviceField() ?? throw new \LogicException('Kein Textfeld.')),
        };
    }

    private static function name(?string $value): ?string
    {
        $value = trim($value ?? '');

        return $value === '' ? null : $value;
    }
}
