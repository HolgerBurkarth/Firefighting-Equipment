<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

use FirefightingEquipment\Permission\DeviceVisibility;

/**
 * Ein Eintrag der Historie eines Geräts. Welche Angaben gefüllt sind, hängt von der Art ab
 * (siehe Migration 0015): created/imported nur $toLocation und $toStatus, state_changed je Paar
 * beide oder keiner, due_changed $fromDue/$toDue, field_changed $field mit $oldValue/$newValue.
 * Nicht übernommene Offline-Buchungen (applied = false, Migration 0025) haben nur $toLocation
 * und/oder $toStatus.
 */
final readonly class DeviceEvent
{
    public function __construct(
        public int $id,
        public DeviceEventType $type,
        /** null = Rückgabe ohne Anmeldung (P2.6): userName nennt dann den Überbringer */
        public ?int $userId,
        /** Anzeigename; anonymisierte Konten als „Gelöschter Benutzer #ID“ */
        public string $userName,
        public ?DeviceEventLocation $fromLocation,
        public ?DeviceEventLocation $toLocation,
        public ?DeviceStatus $fromStatus,
        public ?DeviceStatus $toStatus,
        public ?DeviceDate $fromDue,
        public ?DeviceDate $toDue,
        public ?DeviceField $field,
        public ?string $oldValue,
        public ?string $newValue,
        public ?string $comment,
        /** Eingang beim Server (UTC) */
        public \DateTimeImmutable $createdAt,
        /** Aktionszeit auf dem Handy (UTC); null = Serveraktion, siehe occurredAt() */
        public ?\DateTimeImmutable $clientOccurredAt = null,
        /** false = Offline-Buchung nicht übernommen, nur Historie (nur state_changed, ohne „von“) */
        public bool $applied = true,
        public ?NotAppliedReason $notAppliedReason = null,
        /** Kennung der Buchung vom Handy (client_ref), falls mitgesendet */
        public ?string $clientRef = null,
        public EventChannel $channel = EventChannel::App,
        /** Buchung auf einen Standort mit der Aufgabe „Ausgeliehen an“ (Verleih, P2.5): Bezeichnung „Verliehen“ */
        public bool $lent = false,
    ) {
    }

    /**
     * Derselbe Eintrag; Standorte außerhalb des Datenfilters als „anderer Standort“.
     */
    public function maskedFor(DeviceVisibility $visibility): self
    {
        if ($visibility->isAll()) {
            return $this;
        }

        return new self(
            $this->id,
            $this->type,
            $this->userId,
            $this->userName,
            $this->fromLocation?->maskedFor($visibility),
            $this->toLocation?->maskedFor($visibility),
            $this->fromStatus,
            $this->toStatus,
            $this->fromDue,
            $this->toDue,
            $this->field,
            $this->oldValue,
            $this->newValue,
            $this->comment,
            $this->createdAt,
            $this->clientOccurredAt,
            $this->applied,
            $this->notAppliedReason,
            $this->clientRef,
            $this->channel,
            $this->lent,
        );
    }

    /**
     * Aktionszeit: auf dem Handy (Offline-Buchung), sonst der Eingang beim Server.
     */
    public function occurredAt(): \DateTimeImmutable
    {
        return $this->clientOccurredAt ?? $this->createdAt;
    }

    /**
     * Deutsche Bezeichnung; bei state_changed nach Inhalt („Ausgesondert“, „Wieder aufgenommen“,
     * „Verliehen“, „Umgebucht“, „Status geändert“, „Umgebucht und Status geändert“).
     */
    public function label(): string
    {
        if ($this->type !== DeviceEventType::StateChanged) {
            return $this->type->label();
        }
        if (!$this->applied) {
            return match (true) {
                $this->toLocation !== null && $this->toStatus !== null => 'Umbuchung und Statusänderung nicht übernommen',
                $this->toLocation !== null => 'Umbuchung nicht übernommen',
                default => 'Statusänderung nicht übernommen',
            };
        }

        return match (true) {
            $this->userId === null => 'Rückgabe',
            $this->lent && $this->toLocation !== null && $this->toStatus === null => 'Verliehen',
            $this->toStatus === DeviceStatus::Retired => 'Ausgesondert',
            $this->fromStatus === DeviceStatus::Retired => 'Wieder aufgenommen',
            $this->toLocation !== null && $this->toStatus !== null => 'Umgebucht und Status geändert',
            $this->toLocation !== null => 'Umgebucht',
            default => 'Status geändert',
        };
    }
}
