<?php

declare(strict_types=1);

namespace FirefightingEquipment\Mcp\Tool;

use FirefightingEquipment\Http\HttpException;

/**
 * Fehler eines Werkzeugs: wird zum Ergebnis mit isError (nicht zum Protokollfehler), damit Claude
 * die Meldung sieht und den Aufruf berichtigen kann. Code und Meldung wie die Fehlerantwort der
 * REST-API, details in den Begriffen der Werkzeuge (ToolFormat::details; z. B. version_conflict
 * mit dem aktuellen Stand in details.equipment samt version).
 */
final class ToolFailure extends \RuntimeException
{
    public const string NOT_FOUND = 'Nicht gefunden – den Eintrag gibt es nicht oder Sie dürfen ihn nicht sehen.';

    /**
     * @param array<string, mixed>|null $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?array $details = null,
    ) {
        parent::__construct($message);
    }

    public static function invalidArguments(string $message): self
    {
        return new self('invalid_arguments', $message);
    }

    public static function fromHttp(HttpException $e): self
    {
        if ($e->errorCode === 'not_found') {
            // Die Meldung der API meint eine Adresse; hier ist es ein Eintrag (auch außerhalb des Datenfilters).
            return self::notFound(self::NOT_FOUND);
        }

        return new self($e->errorCode, $e->getMessage(), $e->details === null ? null : ToolFormat::details($e->details));
    }

    public static function forbidden(): self
    {
        return new self('forbidden', 'Dafür fehlen Ihnen die Rechte (Rolle oder Claude-Zugang).');
    }

    public static function notFound(string $message): self
    {
        return new self('not_found', $message);
    }
}
