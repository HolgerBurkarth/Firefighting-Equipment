<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Booking;

/**
 * Die Quittung konnte nicht gespeichert werden, weil dieselbe client_ref gleichzeitig verarbeitet
 * wurde (Fehler 1062 auf uq_fe_device_bookings_tenant_ref); die Transaktion wurde zurückgerollt.
 * Der Aufrufer liest die gespeicherte Quittung und wiederholt deren Ergebnis.
 */
final class DuplicateClientRefException extends \RuntimeException
{
}
