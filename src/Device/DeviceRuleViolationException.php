<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Eine Änderung an Standorten, Gerätetypen oder Geräten verletzt eine Regel (zwei Ebenen, Art,
 * Sperren nur leer, gesperrtes bzw. deaktiviertes Ziel, ausgesondertes Gerät). $details enthält
 * maschinenlesbare Angaben für die API (Feld "details" der Fehlerantwort).
 */
final class DeviceRuleViolationException extends DeviceException
{
    /**
     * @param array<string, int>|null $details
     */
    private function __construct(string $message, public readonly ?array $details = null)
    {
        parent::__construct($message);
    }

    public static function tooDeep(LocationName $parent): self
    {
        return new self(sprintf(
            'Standorte haben höchstens zwei Ebenen; „%s“ ist bereits ein Unterstandort.',
            $parent->value,
        ));
    }

    public static function parentLocked(LocationName $parent): self
    {
        return new self(sprintf(
            'Unter dem gesperrten Standort „%s“ können keine Unterstandorte angelegt werden.',
            $parent->value,
        ));
    }

    public static function kindMismatch(LocationName $parent, LocationKind $kind): self
    {
        return new self(sprintf(
            'Ein Unterstandort hat immer die Art des übergeordneten Standorts („%s“: %s).',
            $parent->value,
            $kind->label(),
        ));
    }

    /**
     * @param int $devices      nicht ausgesonderte Geräte am Standort
     * @param int $sublocations aktive Unterstandorte
     */
    public static function notEmpty(LocationName $name, int $devices, int $sublocations): self
    {
        $parts = [];
        if ($devices > 0) {
            $parts[] = $devices === 1 ? '1 Gerät' : sprintf('%d Geräte', $devices);
        }
        if ($sublocations > 0) {
            $parts[] = $sublocations === 1 ? '1 aktiver Unterstandort' : sprintf('%d aktive Unterstandorte', $sublocations);
        }

        return new self(
            sprintf(
                'Der Standort „%s“ ist nicht leer (%s). Bitte zuerst die Geräte umbuchen bzw. die Unterstandorte sperren.',
                $name->value,
                implode(', ', $parts),
            ),
            ['devices' => $devices, 'sublocations' => $sublocations],
        );
    }

    public static function parentInactive(LocationName $parent): self
    {
        return new self(sprintf(
            'Der übergeordnete Standort „%s“ ist gesperrt; bitte zuerst diesen entsperren.',
            $parent->value,
        ));
    }

    public static function locationLocked(LocationName $location): self
    {
        return new self(sprintf(
            'Der Standort „%s“ ist gesperrt und kann keine Geräte aufnehmen.',
            $location->value,
        ));
    }

    public static function deviceTypeInactive(DeviceTypeName $type): self
    {
        return new self(sprintf(
            'Der Gerätetyp „%s“ ist deaktiviert und kann keinem Gerät zugeordnet werden.',
            $type->value,
        ));
    }

    public static function deviceRetired(DeviceIdentifier $identifier): self
    {
        return new self(sprintf(
            'Das Gerät „%s“ ist ausgesondert; Stammdaten und Prüfdatum lassen sich erst nach der Wiederaufnahme ändern.',
            $identifier->value,
        ));
    }
}
