<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1;

use FirefightingEquipment\Api\V1\Devices\DeviceJson;
use FirefightingEquipment\Device\DeviceException;
use FirefightingEquipment\Device\DeviceNotFoundException;
use FirefightingEquipment\Device\DeviceRuleViolationException;
use FirefightingEquipment\Device\DeviceTypeNotFoundException;
use FirefightingEquipment\Device\DeviceVersionConflictException;
use FirefightingEquipment\Device\DuplicateDeviceIdentifierException;
use FirefightingEquipment\Device\DuplicateDeviceNameException;
use FirefightingEquipment\Device\DuplicateDeviceScanCodeException;
use FirefightingEquipment\Device\InvalidDeviceValueException;
use FirefightingEquipment\Device\LocationNotFoundException;
use FirefightingEquipment\Http\HttpException;

/**
 * Fachliche Fehler zu Gerätetypen, Standorten und Geräten als HTTP-Fehler: ungültiger Wert 400,
 * fremde oder unbekannte ID 404 not_found, Name vergeben 409 name_taken, Kennung vergeben 409
 * identifier_taken, Scan-Code vergeben 409 scan_code_taken, veraltete Version 409 version_conflict (details {"device": aktueller Stand}),
 * Regelverstoß 409 rule_violation (mit "details", falls vorhanden).
 */
final class DeviceErrors
{
    private function __construct()
    {
    }

    public static function http(DeviceException $e): HttpException
    {
        return match (true) {
            $e instanceof InvalidDeviceValueException => HttpException::invalidRequest($e->getMessage()),
            $e instanceof DeviceTypeNotFoundException, $e instanceof LocationNotFoundException,
            $e instanceof DeviceNotFoundException => HttpException::notFound(),
            $e instanceof DuplicateDeviceNameException => HttpException::nameTaken($e->getMessage()),
            $e instanceof DuplicateDeviceIdentifierException => HttpException::identifierTaken($e->getMessage()),
            $e instanceof DuplicateDeviceScanCodeException => HttpException::scanCodeTaken($e->getMessage()),
            $e instanceof DeviceVersionConflictException => HttpException::versionConflict(
                $e->getMessage(),
                ['device' => DeviceJson::device($e->current)],
            ),
            $e instanceof DeviceRuleViolationException => HttpException::ruleViolation($e->getMessage(), $e->details),
            default => throw new \LogicException(sprintf('Unbekannter Fehler: %s', $e::class), previous: $e),
        };
    }
}
