<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http;

/**
 * Eingehende HTTP-Anfrage (nur die Teile, die der Router braucht).
 */
final readonly class Request
{
    public function __construct(
        public string $method,
        /** Wie $_SERVER['REQUEST_URI'], z. B. "/firefighting/service/api/v1/health?x=1". */
        public string $uri,
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        return new self(is_string($method) ? $method : 'GET', is_string($uri) ? $uri : '/');
    }
}
