<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Import;

/**
 * Ergebnis einer Zeile in der Vorschau des Imports.
 */
enum ImportResult: string
{
    case New = 'new';
    case Changed = 'changed';
    case Unchanged = 'unchanged';
    case Skipped = 'skipped';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::New => 'neu',
            self::Changed => 'Änderungen',
            self::Unchanged => 'keine Änderung',
            self::Skipped => 'übersprungen',
            self::Error => 'Fehler',
        };
    }
}
