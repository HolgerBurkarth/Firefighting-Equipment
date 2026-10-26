<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http;

/**
 * Antwort der REST-API: JSON (JsonResponse, Regelfall und alle Fehler) oder ein gestreamter
 * CSV-Download (CsvResponse).
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
