<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Import;

use FirefightingEquipment\Csv\CsvException;
use FirefightingEquipment\Csv\CsvFormat;
use FirefightingEquipment\Csv\CsvReader;
use FirefightingEquipment\Csv\CsvRecord;

/**
 * Eine gelesene Import-Datei (noch ohne Datenbank): Kopfzeile zugeordnet, Zeilen geprüft.
 *
 * Fehler der ganzen Datei (ImportFileException): leer, zu groß (MAX_BYTES), nicht lesbar
 * (CsvReader), unbekannte, doppelte oder leere Überschrift, Spalte Kennung fehlt, abgebrochener
 * Export (Zeile CsvFormat::ABORTED_LINE), keine oder mehr als MAX_ROWS Zeilen. Fehler einzelner
 * Zeilen (falsche Anzahl Felder, ungültige Werte) stehen in den Zeilen.
 */
final readonly class DeviceImportFile
{
    public const int MAX_BYTES = 2_097_152;
    public const int MAX_ROWS = 5000;

    /**
     * @param list<ImportColumn> $columns Spalten in der Reihenfolge der Datei
     * @param list<ImportRow>    $rows    nicht leere Zeilen nach der Kopfzeile
     */
    private function __construct(
        /** SHA-256 der Datei (Teil des Kennzeichens der Vorschau) */
        public string $hash,
        public string $encoding,
        public string $delimiter,
        public array $columns,
        public array $rows,
    ) {
    }

    /**
     * @throws ImportFileException
     */
    public static function parse(string $bytes): self
    {
        if ($bytes === '') {
            throw new ImportFileException('Die Datei ist leer.');
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new ImportFileException(
                sprintf('Die Datei ist zu groß (%s, höchstens 2 MiB). Bitte in mehrere Dateien aufteilen.', self::size(strlen($bytes))),
                ['max_bytes' => self::MAX_BYTES],
            );
        }

        try {
            $table = CsvReader::read($bytes, static fn (string $header): bool => ImportColumn::fromHeader($header) !== null);
        } catch (CsvException $e) {
            throw new ImportFileException($e->getMessage(), $e->lineNumber === null ? [] : ['line' => $e->lineNumber]);
        }

        $columns = self::columns($table->header);
        $index = array_search(ImportColumn::Identifier, $columns, true);

        foreach ($table->records as $record) {
            if (trim($record->fields[0]) === CsvFormat::ABORTED_LINE && implode('', array_slice($record->fields, 1)) === '') {
                throw new ImportFileException(
                    'Die Datei stammt aus einem abgebrochenen Export und ist unvollständig. Bitte den Export wiederholen.',
                    ['line' => $record->line],
                );
            }
        }
        if ($table->records === []) {
            throw new ImportFileException('Die Datei enthält keine Geräte (nur die Kopfzeile).');
        }
        if (count($table->records) > self::MAX_ROWS) {
            throw new ImportFileException(
                sprintf('Die Datei enthält %d Zeilen; höchstens %d sind möglich. Bitte in mehrere Dateien aufteilen.', count($table->records), self::MAX_ROWS),
                ['rows' => count($table->records), 'max_rows' => self::MAX_ROWS],
            );
        }

        $rows = [];
        foreach ($table->records as $record) {
            if (count($record->fields) !== count($columns)) {
                $rows[] = ImportRow::invalid($record->line, $record->fields[$index] ?? '', sprintf(
                    'Die Zeile hat %d Felder, die Kopfzeile %d. Bitte Trennzeichen und Anführungszeichen prüfen.',
                    count($record->fields),
                    count($columns),
                ));
                continue;
            }
            $cells = [];
            foreach ($columns as $i => $column) {
                $cells[$column->value] = $record->fields[$i];
            }
            $rows[] = ImportRow::fromCells($record->line, $cells);
        }

        return new self(hash('sha256', $bytes), $table->encoding, $table->delimiter, $columns, $rows);
    }

    /**
     * @return list<ImportColumn>
     *
     * @throws ImportFileException
     */
    private static function columns(CsvRecord $header): array
    {
        $columns = [];
        $unknown = [];
        foreach ($header->fields as $i => $text) {
            $column = ImportColumn::fromHeader($text);
            if ($column === null) {
                $unknown[] = trim($text) === '' ? sprintf('(Spalte %d ohne Überschrift)', $i + 1) : trim($text);
                continue;
            }
            if (in_array($column, $columns, true)) {
                throw new ImportFileException(
                    sprintf('Die Spalte „%s“ kommt in der Kopfzeile mehrfach vor.', $column->label()),
                    ['line' => 1, 'columns' => [$column->label()]],
                );
            }
            $columns[] = $column;
        }

        if ($unknown !== []) {
            throw new ImportFileException(
                sprintf(
                    'Unbekannte Spalte%s: %s. Möglich sind: %s.',
                    count($unknown) === 1 ? '' : 'n',
                    implode(', ', array_map(static fn (string $c): string => '„' . $c . '“', $unknown)),
                    implode(', ', array_map(static fn (ImportColumn $c): string => $c->label(), ImportColumn::cases())),
                ),
                ['line' => 1, 'columns' => $unknown],
            );
        }
        if (!in_array(ImportColumn::Identifier, $columns, true)) {
            throw new ImportFileException('Die Spalte „Kennung“ fehlt; sie ordnet die Zeilen den Geräten zu.', ['line' => 1]);
        }

        return $columns;
    }

    private static function size(int $bytes): string
    {
        // Aufgerundet, damit knapp über der Grenze nicht „2,0 MiB“ erscheint.
        return number_format(ceil($bytes / 104_857.6) / 10, 1, ',', '.') . ' MiB';
    }
}
