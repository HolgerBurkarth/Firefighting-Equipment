<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http;

/**
 * Format des Bodys ändernder Anfragen einer Route (RequestGuard).
 */
enum BodyFormat
{
    /** application/json mit einem JSON-Objekt (Regelfall). */
    case Json;
    /**
     * application/x-www-form-urlencoded, nur einfache Werte, jeder Name höchstens einmal. Nur für
     * oauth/token und oauth/revoke (OAuth 2.1, Abschnitt 3.2; docs/PLAN-MCP.md, Abschnitt 4): Diese
     * Routen lesen kein Cookie (CSRF braucht eines), die Origin-Prüfung bleibt.
     */
    case Form;
}
