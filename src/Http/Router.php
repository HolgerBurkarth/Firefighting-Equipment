<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http;

/**
 * Ordnet Methode und Pfad (relativ zu /firefighting/<kürzel>) einem Handler zu.
 * Pfade sind exakt, ohne Platzhalter.
 */
final class Router
{
    private const string METHOD_PATTERN = '/^[A-Z]+$/D';

    /** @var array<string, array<string, RequestHandler>> Pfad => Methode => Handler */
    private array $routes = [];

    /**
     * @param string $method z. B. "GET"
     * @param string $path ohne führenden Slash, z. B. "api/v1/health"
     */
    public function add(string $method, string $path, RequestHandler $handler): self
    {
        if (preg_match(self::METHOD_PATTERN, $method) !== 1) {
            throw new \LogicException(sprintf('Ungültige Methode: %s', $method));
        }
        if (str_starts_with($path, '/') || str_ends_with($path, '/')) {
            throw new \LogicException(sprintf('Pfad ohne Slash am Anfang/Ende angeben: %s', $path));
        }
        if (isset($this->routes[$path][$method])) {
            throw new \LogicException(sprintf('Route doppelt: %s %s', $method, $path));
        }

        $this->routes[$path][$method] = $handler;

        return $this;
    }

    /**
     * @throws HttpException 404 für unbekannte Pfade, 405 (mit Allow) für falsche Methoden
     */
    public function match(string $method, string $path): RequestHandler
    {
        $byMethod = $this->routes[$path] ?? throw HttpException::notFound();
        if (isset($byMethod[$method])) {
            return $byMethod[$method];
        }

        $allowed = array_keys($byMethod);
        sort($allowed);

        throw HttpException::methodNotAllowed($allowed);
    }
}
