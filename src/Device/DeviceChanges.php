<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Gewünschte Änderungen an Stammdaten: nur die angegebenen Felder, null = Feld leeren (nicht bei
 * Kennung und Gerätetyp). Ob sich ein Wert wirklich ändert, entscheidet DeviceRepository.
 */
final readonly class DeviceChanges
{
    /**
     * @param array<string, DeviceIdentifier|int|string|DeviceDate|null> $values nach DeviceField->value
     *
     * @throws InvalidDeviceValueException keine Felder angegeben
     */
    public function __construct(private array $values)
    {
        if ($values === []) {
            throw new InvalidDeviceValueException(sprintf(
                'Keine Stammdaten zum Ändern angegeben (möglich: %s).',
                implode(', ', array_map(static fn (DeviceField $f): string => $f->bodyKey(), DeviceField::cases())),
            ));
        }
        foreach ($values as $key => $value) {
            $field = DeviceField::from($key);
            $valid = match ($field) {
                DeviceField::Identifier => $value instanceof DeviceIdentifier,
                DeviceField::DeviceType => is_int($value),
                DeviceField::ProcuredOn => $value === null || $value instanceof DeviceDate,
                default => $value === null || is_string($value),
            };
            if (!$valid) {
                throw new \LogicException(sprintf('Ungültiger Wert für %s.', $field->value));
            }
        }
    }

    public function has(DeviceField $field): bool
    {
        return array_key_exists($field->value, $this->values);
    }

    public function identifier(): ?DeviceIdentifier
    {
        $value = $this->values[DeviceField::Identifier->value] ?? null;

        return $value instanceof DeviceIdentifier ? $value : null;
    }

    public function deviceTypeId(): ?int
    {
        $value = $this->values[DeviceField::DeviceType->value] ?? null;

        return is_int($value) ? $value : null;
    }

    public function procuredOn(): ?DeviceDate
    {
        $value = $this->values[DeviceField::ProcuredOn->value] ?? null;

        return $value instanceof DeviceDate ? $value : null;
    }

    /**
     * Seriennummer, Hersteller, Modell oder Notiz.
     */
    public function text(DeviceField $field): ?string
    {
        $value = $this->values[$field->value] ?? null;

        return is_string($value) ? $value : null;
    }
}
