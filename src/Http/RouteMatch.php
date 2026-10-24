<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http;

/**
 * Ergebnis von Router::match: Handler, Zugriffsstufe und Werte der Platzhalter.
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
    ) {
    }
}
