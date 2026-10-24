<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http;

use FirefightingEquipment\Database\DatabaseError;

/**
 * Schreibt Fehlerdetails ins Server-Log (error_log), nie in die Antwort.
 * Meldungen werden maskiert; Stack-Traces werden nicht geschrieben, da sie Argumente
 * wie Zugangsdaten enthalten können.
 */
final readonly class ErrorReporter
{
    /** @var \Closure(string): void */
    private \Closure $sink;

    /**
     * @param (\Closure(string): void)|null $sink Ziel der Zeilen; Standard error_log()
     */
    public function __construct(?\Closure $sink = null)
    {
        $this->sink = $sink ?? static function (string $line): void {
            error_log($line);
        };
    }

    public function report(string $where, \Throwable $e): void
    {
        $parts = [];
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            $parts[] = sprintf(
                '%s: %s (%s:%d)',
                $current::class,
                self::describe($current),
                basename($current->getFile()),
                $current->getLine(),
            );
        }

        ($this->sink)(sprintf('firefighting: %s: %s', $where, implode(' <- ', $parts)));
    }

    private static function describe(\Throwable $e): string
    {
        return $e instanceof \PDOException ? DatabaseError::describe($e) : DatabaseError::mask($e->getMessage());
    }
}
