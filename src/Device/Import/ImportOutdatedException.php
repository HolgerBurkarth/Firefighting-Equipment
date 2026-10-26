<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Import;

use FirefightingEquipment\Device\DeviceException;

/**
 * Übernehmen abgelehnt: Die erneute Prüfung ergibt Fehler oder ein anderes Kennzeichen als die
 * Vorschau (Gerät geändert, Kennung inzwischen vergeben, Standort gesperrt, andere Datei …). Es
 * wurde nichts geschrieben. $plan ist das Ergebnis der erneuten Prüfung (null, wenn erst beim
 * Schreiben ein Konflikt auftrat).
 */
final class ImportOutdatedException extends DeviceException
{
    public function __construct(public readonly ?DeviceImportPlan $plan)
    {
        parent::__construct($plan !== null && $plan->token === null
            ? 'Die Datei enthält inzwischen Fehler (z. B. weil sich Geräte, Standorte oder Gerätetypen geändert haben). Bitte die Vorschau neu erstellen.'
            : 'Seit der Vorschau wurden Geräte, Standorte oder Gerätetypen geändert, oder die Datei ist eine andere. Bitte die Vorschau neu erstellen.');
    }
}
