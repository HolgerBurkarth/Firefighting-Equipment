<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http;

use FirefightingEquipment\Permission\Requirement;

/**
 * Ergebnis von Router::match: Handler, Zugriffsstufe bzw. Rechte, Werte der Platzhalter und
 * Body-Grenze.
 */
final readonly class RouteMatch
{
    /**
     * @param array<string, int> $params Platzhalter => Wert, z. B. ['id' => 42]
     */
    public function __construct(
        public RequestHandler $handler,
        public Access|Requirement $access,
        public array $params = [],
        /** Höchstgröße des Bodys in Byte (RequestGuard) */
        public int $maxBodyBytes = Request::MAX_BODY_BYTES,
    ) {
    }
}
