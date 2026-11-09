<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Besondere Art einer Buchung, gespeichert in fe_device_events.action (Migration 0065). Rückgabe, Benutzt
 * gemeldet und Tausch erkennt der Verlauf schon an gespeicherten Angaben (Konto fehlt, Zielstatus, swap_id).
 */
enum EventAction: string
{
    /** Verleih (P2.5): Buchung auf einen Standort mit der Aufgabe „Ausgeliehen an“ */
    case Lent = 'lent';
}
