<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Devices;

use FirefightingEquipment\Device\Booking\ClientRef;
use FirefightingEquipment\Device\DeviceChanges;
use FirefightingEquipment\Device\DeviceDate;
use FirefightingEquipment\Device\DeviceException;
use FirefightingEquipment\Device\DeviceField;
use FirefightingEquipment\Device\DeviceIdentifier;
use FirefightingEquipment\Device\DeviceReinstatement;
use FirefightingEquipment\Device\DeviceStateChange;
use FirefightingEquipment\Device\DeviceStatus;
use FirefightingEquipment\Device\DeviceText;
use FirefightingEquipment\Device\NewDevice;
use FirefightingEquipment\Device\ScanCode;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Offline\ActionTime;

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
            'identifier', 'scan_code', 'device_type_id', 'location_id', 'status', 'serial_number', 'manufacturer',
            'model', 'procured_on', 'next_inspection_due', 'note', 'comment',
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
            ScanCode::fromString($context->optionalStringField('scan_code')),
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
                DeviceField::ScanCode => ScanCode::fromString($context->optionalStringField($field->bodyKey())),
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
     * POST devices/{id}/state: location_id und/oder status (nicht ausgesondert).
     *
     * @throws HttpException   400 invalid_request
     * @throws DeviceException weder Standort noch Status
     */
    public static function stateChange(RequestContext $context): DeviceStateChange
    {
        self::rejectUnknown($context, ['version', 'location_id', 'status', 'comment', 'client_ref', 'occurred_at', 'confirmed_stale']);

        return new DeviceStateChange(
            $context->optionalIntField('location_id', 1, self::MAX_ID),
            self::serviceStatus($context, false),
        );
    }

    /**
     * Zusatzfelder von POST devices/{id}/state: client_ref (UUID v4), occurred_at (Aktionszeit in
     * UTC, nur mit client_ref) und confirmed_stale (true/false, nur mit occurred_at). Fehlen sie oder
     * sind sie null: wie ohne.
     *
     * @return array{?ClientRef, ?ActionTime, bool}
     *
     * @throws HttpException   400 invalid_request
     * @throws DeviceException ungültige client_ref oder occurred_at
     */
    public static function bookingFields(RequestContext $context): array
    {
        $clientRef = $context->optionalStringField('client_ref');
        $occurredAt = $context->optionalStringField('occurred_at');
        $confirmedStale = ($context->body['confirmed_stale'] ?? null) === null ? null : $context->boolField('confirmed_stale');

        $clientRef = $clientRef === null ? null : ClientRef::fromString($clientRef);
        $occurredAt = $occurredAt === null ? null : ActionTime::fromString($occurredAt);
        if ($occurredAt !== null && $clientRef === null) {
            throw HttpException::invalidRequest('Eine Buchung mit "occurred_at" braucht eine Buchungs-Kennung "client_ref".');
        }
        if ($confirmedStale !== null && $occurredAt === null) {
            throw HttpException::invalidRequest('Das Feld "confirmed_stale" ist nur zusammen mit "occurred_at" vorgesehen.');
        }

        return [$clientRef, $occurredAt, $confirmedStale ?? false];
    }

    /**
     * POST devices/{id}/retire: nur version und comment; liefert den Kommentar.
     *
     * @throws HttpException   400 invalid_request
     * @throws DeviceException
     */
    public static function retirementComment(RequestContext $context): ?string
    {
        self::rejectUnknown($context, ['version', 'comment']);

        return self::comment($context);
    }

    /**
     * POST devices/{id}/reinstate: Pflicht status (nicht ausgesondert), optional location_id,
     * identifier (nicht leerbar), scan_code (null oder leer = entfernen) und device_type_id.
     *
     * @throws HttpException   400 invalid_request
     * @throws DeviceException ungültige Kennung
     */
    public static function reinstatement(RequestContext $context): DeviceReinstatement
    {
        self::rejectUnknown($context, ['version', 'status', 'location_id', 'identifier', 'scan_code', 'device_type_id', 'comment']);

        return new DeviceReinstatement(
            self::serviceStatus($context, true) ?? throw new \LogicException('Status fehlt.'),
            $context->optionalIntField('location_id', 1, self::MAX_ID),
            $context->hasField('identifier') ? DeviceIdentifier::fromString($context->stringField('identifier')) : null,
            $context->optionalIntField('device_type_id', 1, self::MAX_ID),
            $context->hasField('scan_code'),
            ScanCode::fromString($context->optionalStringField('scan_code')),
        );
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
     * Status im Dienst (nicht ausgesondert; dafür gibt es POST devices/{id}/retire).
     *
     * @throws HttpException 400 invalid_request
     */
    private static function serviceStatus(RequestContext $context, bool $required): ?DeviceStatus
    {
        $value = $required ? $context->stringField('status') : $context->optionalStringField('status');
        if ($value === null) {
            return null;
        }
        $status = DeviceStatus::tryFrom($value);
        if ($status === null || $status === DeviceStatus::Retired) {
            $allowed = array_filter(DeviceStatus::cases(), static fn (DeviceStatus $s): bool => $s !== DeviceStatus::Retired);
            throw HttpException::invalidRequest(sprintf(
                'Das Feld "status" muss einer der Werte %s sein (Aussondern über POST devices/{id}/retire).',
                implode(', ', array_map(static fn (DeviceStatus $s): string => sprintf('"%s"', $s->value), $allowed)),
            ));
        }

        return $status;
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
     * Lehnt Felder des Bodys ab, die nicht in $allowed stehen (auch für den CSV-Import).
     *
     * @param list<string> $allowed
     *
     * @throws HttpException 400 invalid_request
     */
    public static function rejectUnknown(RequestContext $context, array $allowed): void
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
