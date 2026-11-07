<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http;

/**
 * Antwort der REST-API: JSON (JsonResponse, Regelfall und alle Fehler), ein gestreamter
 * CSV-Download (CsvResponse) oder eine zwischenspeicherbare öffentliche Datei (CacheableResponse:
 * Logo, Standard-Symbol, Web-App-Manifest) oder eine leere Antwort (EmptyResponse, 202 an mcp).
 */
interface Response
{
    public function statusCode(): int;

    /**
     * @return array<string, string> alle Header einschließlich der Standard-Header
     */
    public function headers(): array;

    public function send(): void;
}
