<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Booking;

use FirefightingEquipment\Device\DeviceException;

/**
 * Dieselbe client_ref wurde in der Stadt bereits für eine andere Buchung verwendet (anderes Konto,
 * anderes Gerät oder anderer Inhalt). Fehler des Handys; nichts geschrieben.
 */
final class ClientRefConflictException extends DeviceException
{
    public function __construct()
    {
        parent::__construct('Die Buchungs-Kennung (client_ref) wurde bereits für eine andere Buchung verwendet. Bitte die Buchung mit einer neuen Kennung senden.');
    }
}
