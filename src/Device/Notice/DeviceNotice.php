<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Notice;

use FirefightingEquipment\Device\DeviceEventLocation;
use FirefightingEquipment\Device\DeviceStatus;
use FirefightingEquipment\Permission\DeviceVisibility;

/**
 * Hinweis an die Admins zu einer Offline-Buchung: Gerät (aktueller Stand von Kennung und Typ), die
 * Buchung (Konto, Aktionszeit, gewünschter Standort und/oder Status) und der andere Stand (beim
 * Eingang der Buchung; bei overwritten der überschriebene) mit Aktionszeit und Konto. Standorte mit
 * ihrem aktuellen Namen und Pfad (außerhalb des Datenfilters „anderer Standort“). $history: Verlauf (erledigt, wieder geöffnet), älteste zuerst.
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
        /** aktueller Gerätetyp und Standort des Geräts (für den Datenfilter der Empfänger) */
        public int $deviceTypeId = 0,
        public int $deviceLocationId = 0,
        /** Einzelheit, bei stock_below_minimum die unterschrittene Regel */
        public ?string $detail = null,
    ) {
    }

    /** Ob das Gerät für das Konto sichtbar ist (aktueller Stand). */
    public function visibleFor(DeviceVisibility $visibility): bool
    {
        return $visibility->allows($this->deviceTypeId, $this->deviceLocationId);
    }

    /**
     * Derselbe Hinweis; Standorte außerhalb des Datenfilters (Buchung, anderer Stand) als „anderer
     * Standort“.
     */
    public function maskedFor(DeviceVisibility $visibility): self
    {
        if ($visibility->isAll()) {
            return $this;
        }

        return new self(
            $this->id,
            $this->kind,
            $this->status,
            $this->staleConfirmed,
            $this->deviceId,
            $this->deviceIdentifier,
            $this->deviceTypeName,
            $this->clientRef,
            $this->eventId,
            $this->user,
            $this->occurredAt,
            $this->requestedLocation?->maskedFor($visibility),
            $this->requestedStatus,
            $this->otherUser,
            $this->otherOccurredAt,
            $this->otherLocation->maskedFor($visibility),
            $this->otherStatus,
            $this->createdAt,
            $this->updatedAt,
            $this->history,
            $this->comment,
            $this->deviceTypeId,
            $this->deviceLocationId,
            $this->detail,
        );
    }
}
