<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Notice;

use FirefightingEquipment\Device\DeviceEventLocation;
use FirefightingEquipment\Device\DeviceStatus;

/**
 * Hinweis an die Admins zu einer Offline-Buchung: Gerät (aktueller Stand von Kennung und Typ), die
 * Buchung (Konto, Aktionszeit, gewünschter Standort und/oder Status) und der andere Stand (beim
 * Eingang der Buchung; bei overwritten der überschriebene) mit Aktionszeit und Konto. Standorte mit
 * ihrem aktuellen Namen und Pfad. $history: Verlauf (erledigt, wieder geöffnet), älteste zuerst.
 * $comment: Kommentar der Buchung (aus ihrem Eintrag im Verlauf des Geräts).
 */
final readonly class DeviceNotice
{
    /**
     * @param list<NoticeHistoryEntry> $history
     */
    public function __construct(
        public int $id,
        public NoticeKind $kind,
        public NoticeStatus $status,
        public bool $staleConfirmed,
        public int $deviceId,
        public string $deviceIdentifier,
        public string $deviceTypeName,
        public ?string $clientRef,
        public ?int $eventId,
        public NoticeUser $user,
        /** Aktionszeit der Buchung (UTC) */
        public \DateTimeImmutable $occurredAt,
        public ?DeviceEventLocation $requestedLocation,
        public ?DeviceStatus $requestedStatus,
        public ?NoticeUser $otherUser,
        /** Aktionszeit des anderen Stands (UTC) */
        public \DateTimeImmutable $otherOccurredAt,
        public DeviceEventLocation $otherLocation,
        public DeviceStatus $otherStatus,
        /** Eingang der Buchung = Anlegen des Hinweises (UTC) */
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public array $history = [],
        public ?string $comment = null,
    ) {
    }
}
