<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1;

use FirefightingEquipment\Device\DeviceException;
use FirefightingEquipment\Device\DeviceRuleViolationException;
use FirefightingEquipment\Device\DeviceTypeNotFoundException;
use FirefightingEquipment\Device\DuplicateDeviceNameException;
use FirefightingEquipment\Device\InvalidDeviceValueException;
use FirefightingEquipment\Device\LocationNotFoundException;
use FirefightingEquipment\Http\HttpException;

/**
 * Fachliche Fehler zu Gerätetypen und Standorten als HTTP-Fehler: ungültiger Wert 400, fremde oder
 * unbekannte ID 404 not_found, Name vergeben 409 name_taken, Regelverstoß 409 rule_violation (mit
 * "details", falls vorhanden).
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
            $e instanceof DeviceTypeNotFoundException, $e instanceof LocationNotFoundException => HttpException::notFound(),
            $e instanceof DuplicateDeviceNameException => HttpException::nameTaken($e->getMessage()),
            $e instanceof DeviceRuleViolationException => HttpException::ruleViolation($e->getMessage(), $e->details),
            default => throw new \LogicException(sprintf('Unbekannter Fehler: %s', $e::class), previous: $e),
        };
    }
}
