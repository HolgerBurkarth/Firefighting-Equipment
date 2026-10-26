<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Devices;

use FirefightingEquipment\Device\DeviceChanges;
use FirefightingEquipment\Device\DeviceDate;
use FirefightingEquipment\Device\DeviceException;
use FirefightingEquipment\Device\DeviceField;
use FirefightingEquipment\Device\DeviceIdentifier;
use FirefightingEquipment\Device\DeviceStatus;
use FirefightingEquipment\Device\DeviceText;
use FirefightingEquipment\Device\NewDevice;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\RequestContext;

/**
 * Liest die JSON-Bodys der ändernden Geräte-Routen. Unbekannte Felder werden abgelehnt (400), damit
 * z. B. ein mitgesendeter Status nicht still ignoriert wird.
 */
final class DeviceFields
{
    /** Größte ID bzw. Version (INT UNSIGNED). */
    public const int MAX_ID = 4_294_967_295;

    private const string DUE_SUBJECT = 'Das Datum der nächsten Prüfung';

    private function __construct()
    {
    }

    /**
     * POST devices.
     *
     * @throws HttpException   400 invalid_request
     * @throws DeviceException ungültiger Wert
     */
    public static function newDevice(RequestContext $context): NewDevice
    {
        self::rejectUnknown($context, [
            'identifier', 'device_type_id', 'location_id', 'status', 'serial_number', 'manufacturer', 'model',
            'procured_on', 'next_inspection_due', 'note', 'comment',
        ]);

        return new NewDevice(
            DeviceIdentifier::fromString($context->stringField('identifier')),
            $context->intField('device_type_id', 1, self::MAX_ID),
            $context->intField('location_id', 1, self::MAX_ID),
            self::status($context),
            DeviceText::field($context->optionalStringField('serial_number'), DeviceField::SerialNumber),
            DeviceText::field($context->optionalStringField('manufacturer'), DeviceField::Manufacturer),
            DeviceText::field($context->optionalStringField('model'), DeviceField::Model),
            self::date($context, DeviceField::ProcuredOn->value, DeviceField::ProcuredOn->subject()),
            self::date($context, 'next_inspection_due', self::DUE_SUBJECT),
            DeviceText::field($context->optionalStringField('note'), DeviceField::Note),
        );
    }

    /**
     * POST devices/{id}/fields: nur die angegebenen Felder; null leert ein optionales Feld.
     *
     * @throws HttpException   400 invalid_request
     * @throws DeviceException ungültiger Wert, keine Felder
     */
    public static function changes(RequestContext $context): DeviceChanges
    {
        $keys = array_map(static fn (DeviceField $f): string => $f->bodyKey(), DeviceField::cases());
        self::rejectUnknown($context, ['version', 'comment', ...$keys]);

        $values = [];
        foreach (DeviceField::cases() as $field) {
            if (!$context->hasField($field->bodyKey())) {
                continue;
            }
            $values[$field->value] = match ($field) {
                DeviceField::Identifier => DeviceIdentifier::fromString($context->stringField($field->bodyKey())),
                DeviceField::DeviceType => $context->intField($field->bodyKey(), 1, self::MAX_ID),
                DeviceField::ProcuredOn => self::date($context, $field->bodyKey(), $field->subject()),
                default => DeviceText::field($context->optionalStringField($field->bodyKey()), $field),
            };
        }

        return new DeviceChanges($values);
    }

    /**
     * POST devices/{id}/inspection-due: Pflichtfeld next_inspection_due, null = entfernen.
     *
     * @throws HttpException   400 invalid_request
     * @throws DeviceException ungültiges Datum
     */
    public static function inspectionDue(RequestContext $context): ?DeviceDate
    {
        self::rejectUnknown($context, ['version', 'next_inspection_due', 'comment']);
        if (!$context->hasField('next_inspection_due')) {
            throw HttpException::invalidRequest('Das Feld "next_inspection_due" fehlt (Datum JJJJ-MM-TT oder null zum Entfernen).');
        }

        return self::date($context, 'next_inspection_due', self::DUE_SUBJECT);
    }

    /**
     * Erwartete Version (Pflicht bei Änderungen).
     *
     * @throws HttpException 400 invalid_request
     */
    public static function version(RequestContext $context): int
    {
        return $context->intField('version', 1, self::MAX_ID);
    }

    /**
     * Optionaler Kommentar zum Ereignis (eine Zeile, höchstens 500 Zeichen, leer = keiner).
     *
     * @throws HttpException   400 invalid_request
     * @throws DeviceException
     */
    public static function comment(RequestContext $context): ?string
    {
        return DeviceText::comment($context->optionalStringField('comment'));
    }

    /**
     * @throws HttpException 400 invalid_request
     */
    private static function status(RequestContext $context): DeviceStatus
    {
        $value = $context->optionalStringField('status');
        if ($value === null) {
            return DeviceStatus::Ready;
        }

        return DeviceStatus::tryFrom($value) ?? throw HttpException::invalidRequest(sprintf(
            'Das Feld "status" muss einer der Werte %s sein.',
            implode(', ', array_map(static fn (DeviceStatus $s): string => sprintf('"%s"', $s->value), DeviceStatus::cases())),
        ));
    }

    /**
     * @throws HttpException   400 invalid_request
     * @throws DeviceException
     */
    private static function date(RequestContext $context, string $name, string $subject): ?DeviceDate
    {
        $value = $context->optionalStringField($name);

        return $value === null ? null : DeviceDate::fromString($value, $subject);
    }

    /**
     * @param list<string> $allowed
     *
     * @throws HttpException 400 invalid_request
     */
    private static function rejectUnknown(RequestContext $context, array $allowed): void
    {
        foreach (array_keys($context->body) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw HttpException::invalidRequest(sprintf(
                    'Das Feld "%s" ist hier nicht vorgesehen (möglich: %s).',
                    $key,
                    implode(', ', $allowed),
                ));
            }
        }
    }
}
