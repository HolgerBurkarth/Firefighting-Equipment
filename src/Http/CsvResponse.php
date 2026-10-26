<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http;

use FirefightingEquipment\Csv\CsvFormat;

/**
 * CSV-Download (Format siehe CsvFormat), Zeile für Zeile geschrieben, ohne die Datei im Speicher
 * aufzubauen. Header wie bei JSON: Cache-Control no-store und X-Content-Type-Options nosniff, dazu
 * Content-Disposition attachment mit dem Dateinamen.
 *
 * Die Zeilen kommen in der Regel aus einem Generator mit einer ungepufferten Abfrage. Der
 * Konstruktor startet ihn bis zur ersten Zeile, damit Fehler der Abfrage noch im Handler auftreten
 * (normale Fehlerantwort als JSON). Ein Fehler danach (Header schon gesendet) wird protokolliert
 * (csv_export_failed), und die Datei endet mit der Zeile ABORTED_LINE, damit niemand sie für
 * vollständig hält.
 */
final readonly class CsvResponse implements Response
{
    public const string CONTENT_TYPE = 'text/csv; charset=utf-8';

    /** Nach so vielen Zeilen wird die Ausgabe an den Webserver weitergereicht. */
    public const int FLUSH_ROWS = 500;

    public const string ABORTED_LINE = 'Export abgebrochen – Datei unvollständig';

    /** @var \Iterator<mixed, mixed> */
    private \Iterator $items;

    /**
     * @param string                        $filename nur a-z, 0-9, Punkt und Bindestrich, Endung .csv
     * @param list<string>                  $header   Spaltenüberschriften
     * @param iterable<mixed, mixed>        $items    Einträge, je einer wird zu einer Zeile
     * @param \Closure(mixed): list<string|null> $toRow
     */
    public function __construct(
        private string $filename,
        private array $header,
        iterable $items,
        private \Closure $toRow,
        private ErrorReporter $errors,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9.-]*\.csv\z/', $filename) !== 1) {
            throw new \InvalidArgumentException(sprintf('Ungültiger Dateiname: %s', $filename));
        }
        $this->items = match (true) {
            $items instanceof \Iterator => $items,
            is_array($items) => new \ArrayIterator($items),
            default => new \IteratorIterator($items),
        };
        $this->items->rewind();
    }

    public function statusCode(): int
    {
        return 200;
    }

    public function filename(): string
    {
        return $this->filename;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return [
            'Content-Type' => self::CONTENT_TYPE,
            'Content-Disposition' => sprintf('attachment; filename="%s"', $this->filename),
            'Cache-Control' => JsonResponse::DEFAULT_HEADERS['Cache-Control'],
            'X-Content-Type-Options' => JsonResponse::DEFAULT_HEADERS['X-Content-Type-Options'],
        ];
    }

    /**
     * Schreibt die Datei (BOM, Überschriften, Zeilen). Nur einmal aufrufbar, da die Zeilen nur
     * einmal gelesen werden.
     *
     * @param resource                $stream
     * @param (\Closure(): void)|null $flush  nach jeweils FLUSH_ROWS Zeilen aufgerufen
     */
    public function writeTo($stream, ?\Closure $flush = null): void
    {
        self::write($stream, CsvFormat::BOM . CsvFormat::line($this->header));
        $count = 0;
        try {
            // Nicht foreach: Das würde den schon gestarteten Generator erneut zurücksetzen.
            for (; $this->items->valid(); $this->items->next()) {
                self::write($stream, CsvFormat::line(($this->toRow)($this->items->current())));
                if (++$count % self::FLUSH_ROWS === 0 && $flush !== null) {
                    $flush();
                }
            }
        } catch (\Throwable $e) {
            $this->errors->report('csv_export_failed', $e);
            self::write($stream, CsvFormat::line([self::ABORTED_LINE]));
        }
    }

    public function send(): void
    {
        http_response_code($this->statusCode());
        foreach ($this->headers() as $name => $value) {
            header($name . ': ' . $value);
        }
        // Eigene Puffer von PHP leeren, sonst landet doch alles im Speicher.
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        $out = fopen('php://output', 'wb') ?: throw new \RuntimeException('Ausgabe nicht verfügbar.');
        $this->writeTo($out, static function () use ($out): void {
            fflush($out);
            flush();
        });
        fclose($out);
    }

    /**
     * @param resource $stream
     */
    private static function write($stream, string $data): void
    {
        // Bricht der Browser ab, endet das Skript ohnehin; ein Fehler hier ist nicht zu melden.
        fwrite($stream, $data);
    }
}
