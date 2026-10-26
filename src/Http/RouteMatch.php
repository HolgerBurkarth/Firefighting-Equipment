<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http;

/**
 * Ergebnis von Router::match: Handler, Zugriffsstufe, Werte der Platzhalter und Body-Grenze.
 */
final readonly class RouteMatch
{
    /**
     * @param array<string, int> $params Platzhalter => Wert, z. B. ['id' => 42]
     */
    public function __construct(
        public RequestHandler $handler,
        public Access $access,
        public array $params = [],
        /** Höchstgröße des Bodys in Byte (RequestGuard) */
        public int $maxBodyBytes = Request::MAX_BODY_BYTES,
    ) {
    }
}
