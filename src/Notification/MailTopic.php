<?php

declare(strict_types=1);

namespace FirefightingEquipment\Notification;

use FirefightingEquipment\Device\DeviceEvent;
use FirefightingEquipment\Device\DeviceEventType;
use FirefightingEquipment\Device\DeviceStatus;

/**
 * Themen der Benachrichtigung „Log“ (Phase 2, P2.10): Wer die Benachrichtigung eingeschaltet hat, wählt, zu
 * welchen Themen er Mails bekommt (fe_user_mail_prefs; ohne Auswahl alle). Nur Mails, die eine Aktion auslöst;
 * Erinnerungen gibt es nicht (G12). Werte wie in der Datenbank und in der API.
 */
enum MailTopic: string
{
    case DefectReported = 'defect_reported';
    case UsedReported = 'used_reported';
    case ReturnReceived = 'return_received';
    case Lent = 'lent';
    case Notices = 'notices';
    case Changes = 'changes';
    case MasterData = 'master_data';

    public function label(): string
    {
        return match ($this) {
            self::DefectReported => 'Defekt gemeldet',
            self::UsedReported => 'Benutzt gemeldet',
            self::ReturnReceived => 'Rückgabe eingegangen',
            self::Lent => 'Ausrüstung verliehen',
            self::Notices => 'Hinweise im Protokoll',
            self::Changes => 'Sonstige Umbuchungen und Status',
            self::MasterData => 'Stammdaten, Wartungsdatum und Import',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::DefectReported => 'Eine Ausrüstung wird auf „defekt“ gesetzt.',
            self::UsedReported => 'Eine Ausrüstung wird als benutzt gemeldet.',
            self::ReturnReceived => 'Jemand gibt verliehene Ausrüstung über den Aushang zurück.',
            self::Lent => 'Ausrüstung wird an einen Leihnehmer verliehen.',
            self::Notices => 'Neuer Hinweis, z. B. Sollbestand unterschritten oder alte Offline-Buchung.',
            self::Changes => 'Umbuchen, Status, Tausch, Aussondern und Wiederaufnehmen.',
            self::MasterData => 'Anlegen, Stammdaten, Wartungsdatum und CSV-Import.',
        };
    }

    /**
     * Thema eines Eintrags der Historie.
     */
    public static function forEvent(DeviceEvent $event): self
    {
        if ($event->type !== DeviceEventType::StateChanged) {
            return self::MasterData;
        }

        return match (true) {
            !$event->applied => self::Changes,
            $event->userId === null => self::ReturnReceived,
            $event->swapId !== null => self::Changes,
            $event->lent && $event->toLocation !== null && $event->toStatus === null => self::Lent,
            $event->toStatus === DeviceStatus::Defective => self::DefectReported,
            $event->toStatus === DeviceStatus::Used => self::UsedReported,
            default => self::Changes,
        };
    }

    /**
     * @param iterable<DeviceEvent> $events
     *
     * @return list<self> die Themen ohne Wiederholung
     */
    public static function forEvents(iterable $events): array
    {
        $topics = [];
        foreach ($events as $event) {
            $topics[] = self::forEvent($event);
        }

        return array_values(array_unique($topics, SORT_REGULAR));
    }

    /**
     * @return list<array{id: string, label: string, hint: string}> alle Themen in fester Reihenfolge (für die App)
     */
    public static function catalog(): array
    {
        return array_map(static fn (self $t): array => ['id' => $t->value, 'label' => $t->label(), 'hint' => $t->hint()], self::cases());
    }
}
