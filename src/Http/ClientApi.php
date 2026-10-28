<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http;

/**
 * Vertragsnummer der API für das Frontend: Die App sendet sie bei jedem API-Aufruf als
 * `X-Client-Api` (frontend/src/api/client.ts). Gleich mit resources/client-api.json (per Test).
 *
 * Noch NICHT ausgewertet. Beschlossener Plan (CLAUDE.md, "Versionierung der API für das Frontend"):
 * MIN_CLIENT_API, 409 client_outdated, Antwort-Header X-Api-Version.
 */
final class ClientApi
{
    public const string HEADER = 'X-Client-Api';

    /** Aktuelle Vertragsnummer; nur bei inkompatiblen Änderungen erhöhen. */
    public const int CURRENT = 1;
}
