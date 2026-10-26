<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Ein Eintrag der Historie eines Geräts. Welche Angaben gefüllt sind, hängt von der Art ab
 * (siehe Migration 0015): created/imported nur $toLocation und $toStatus, state_changed je Paar
 * beide oder keiner, due_changed $fromDue/$toDue, field_changed $field mit $oldValue/$newValue.
 */
final readonly class DeviceEvent
{
    public function __construct(
        public int $id,
        public DeviceEventType $type,
        public int $userId,
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
        /** UTC */
        public \DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * Deutsche Bezeichnung; bei state_changed nach Inhalt („Ausgesondert“, „Wieder aufgenommen“,
     * „Umgebucht“, „Status geändert“, „Umgebucht und Status geändert“).
     */
    public function label(): string
    {
        if ($this->type !== DeviceEventType::StateChanged) {
            return $this->type->label();
        }

        return match (true) {
            $this->toStatus === DeviceStatus::Retired => 'Ausgesondert',
            $this->fromStatus === DeviceStatus::Retired => 'Wieder aufgenommen',
            $this->toLocation !== null && $this->toStatus !== null => 'Umgebucht und Status geändert',
            $this->toLocation !== null => 'Umgebucht',
            default => 'Status geändert',
        };
    }
}
