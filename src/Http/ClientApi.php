<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http;

/**
 * Vertragsnummer der API für das Frontend (CLAUDE.md, "Versionierung der API für das Frontend"):
 * Die App sendet ihre Nummer bei jedem API-Aufruf als `X-Client-Api` (frontend/src/api/client.ts),
 * jede JSON- und CSV-Antwort trägt die aktuelle als `X-Api-Version`. Gleich mit
 * resources/client-api.json (per Test).
 *
 * Liegt die Nummer der App unter MIN, antwortet Application vor dem Handler mit 409
 * client_outdated. Ohne Header (curl, ältere Apps) oder mit einem Wert, der keine Nummer ist, gilt
 * die Anfrage als aktuell.
 */
final class ClientApi
{
    public const string HEADER = 'X-Client-Api';

    /** Header jeder JSON- und CSV-Antwort mit CURRENT (JsonResponse, CsvResponse). */
    public const string RESPONSE_HEADER = 'X-Api-Version';

    /**
     * Aktuelle Vertragsnummer; nur bei inkompatiblen Änderungen erhöhen. 2 seit Plan Phase 1, Schritt
     * 1.7c: Menü, Leiste und Knöpfe folgen den Rechten der Rolle (`permissions`), nicht mehr `role`.
     */
    public const int CURRENT = 2;

    /** Kleinste Nummer, die der Server noch annimmt (MIN_CLIENT_API); höchstens CURRENT. */
    public const int MIN = 1;

    /** Nur Ziffern ohne führende Null (oder genau 0), höchstens 9 Stellen. */
    private const string PATTERN = '/^(0|[1-9][0-9]{0,8})\z/';

    /**
     * Nummer der App aus dem Header; null = ohne Header oder keine Nummer (gilt als aktuell).
     */
    public static function fromHeader(?string $value): ?int
    {
        if ($value === null || preg_match(self::PATTERN, $value) !== 1) {
            return null;
        }

        return (int) $value;
    }

    /** true = die App ist älter als `min` (409 client_outdated). */
    public static function isOutdated(?string $value, int $min = self::MIN): bool
    {
        $client = self::fromHeader($value);

        return $client !== null && $client < $min;
    }
}
