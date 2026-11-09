<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

use FirefightingEquipment\Device\Stock\StockMode;

/**
 * Gewünschte Umbuchung und/oder Statusänderung (POST devices/{id}/state): mindestens eines von
 * beiden, nie ausgesondert (dafür retire). Ob sich wirklich etwas ändert, entscheidet
 * DeviceRepository; gleiche Werte werden ignoriert.
 *
 * $process: das Ziel gibt ein Ablauf vor ("Benutzt melden" in die Sammelstelle, Phase 2, G4); es darf
 * außerhalb des Datenfilters der Rolle liegen, und das Gerät darf danach daraus fallen.
 *
 * $inTransaction: Arbeit, die der Ablauf in DERSELBEN Transaktion wie die Buchung erledigt (z. B. der
 * Eintrag des Verleihs, P2.5); sie läuft mit dem gesperrten Stand VOR der Buchung, nur wenn sich
 * wirklich etwas ändert, und ein Fehler rollt die Buchung zurück.
 *
 * $stock: Prüfung gegen den Sollbestand (P2.8), siehe StockMode; $swapId: Kennung eines Tauschs (beide Buchungen);
 * $action: besondere Art, wird am Ereignis gespeichert (Verleih).
 */
final readonly class DeviceStateChange
{
    /**
     * @throws InvalidDeviceValueException weder Standort noch Status angegeben
     */
    public function __construct(
        public ?int $locationId,
        public ?DeviceStatus $status,
        public bool $process = false,
        public ?\Closure $inTransaction = null,
        public StockMode $stock = StockMode::Enforce,
        public ?string $swapId = null,
        public ?EventAction $action = null,
    ) {
        if ($locationId === null && $status === null) {
            throw new InvalidDeviceValueException('Bitte einen neuen Standort (location_id) und/oder Status (status) angeben.');
        }
        if ($status === DeviceStatus::Retired) {
            throw new \LogicException('Ausgesondert wird über DeviceRepository::retire().');
        }
    }
}
