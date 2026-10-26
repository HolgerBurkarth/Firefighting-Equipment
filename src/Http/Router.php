<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http;

/**
 * Ordnet Methode und Pfad (relativ zu /firefighting/<kürzel>) einem Handler zu.
 *
 * Pfade sind exakt; ein ganzes Segment darf ein Platzhalter wie {id} sein. Platzhalter passen nur
 * auf positive Ganzzahlen ohne führende Null (höchstens 18 Stellen, also immer ein int). Exakte
 * Pfade haben Vorrang vor Pfaden mit Platzhaltern, diese gelten in der Reihenfolge des Anlegens.
 */
final class Router
{
    private const string METHOD_PATTERN = '/^[A-Z]+$/D';
    private const string PLACEHOLDER_PATTERN = '/^\{([a-z][a-z_]*)\}$/D';
    private const string ID_PATTERN = '/^[1-9][0-9]{0,17}$/D';

    /** @var array<string, array<string, array{RequestHandler, Access, int}>> Pfad => Methode => Route (mit Body-Grenze) */
    private array $exact = [];

    /**
     * @var array<string, array{segments: list<string>, methods: array<string, array{RequestHandler, Access, int}>}>
     *      Pfad mit Platzhaltern (Namen durch {} ersetzt) => Segmente und Routen
     */
    private array $patterns = [];

    private function __construct(private readonly bool $forTenant)
    {
    }

    /** Routen unter /firefighting/service/; Access::Admin ist hier nicht erlaubt. */
    public static function forService(): self
    {
        return new self(false);
    }

    /** Routen unter /firefighting/<kürzel>/; Access::SuperAdmin ist hier nicht erlaubt. */
    public static function forTenant(): self
    {
        return new self(true);
    }

    /**
     * @param string $method z. B. "GET"
     * @param string $path ohne führenden Slash, z. B. "api/v1/health" oder "api/v1/auth/sessions/{id}"
     * @param int    $maxBodyBytes Höchstgröße des Bodys (1 bis Request::MAX_UPLOAD_BYTES)
     */
    public function add(string $method, string $path, Access $access, RequestHandler $handler, int $maxBodyBytes = Request::MAX_BODY_BYTES): self
    {
        if (preg_match(self::METHOD_PATTERN, $method) !== 1) {
            throw new \LogicException(sprintf('Ungültige Methode: %s', $method));
        }
        if (str_starts_with($path, '/') || str_ends_with($path, '/')) {
            throw new \LogicException(sprintf('Pfad ohne Slash am Anfang/Ende angeben: %s', $path));
        }
        if ($access === ($this->forTenant ? Access::SuperAdmin : Access::Admin)) {
            throw new \LogicException(sprintf('Zugriff %s ist in diesem Bereich nicht möglich: %s', $access->value, $path));
        }

        if ($maxBodyBytes < 1 || $maxBodyBytes > Request::MAX_UPLOAD_BYTES) {
            throw new \LogicException(sprintf('Body-Grenze außerhalb von 1 bis %d: %s', Request::MAX_UPLOAD_BYTES, $path));
        }

        $segments = explode('/', $path);
        if (in_array('', $segments, true)) {
            throw new \LogicException(sprintf('Leeres Segment im Pfad: %s', $path));
        }

        $names = [];
        $key = [];
        foreach ($segments as $segment) {
            if (preg_match(self::PLACEHOLDER_PATTERN, $segment, $m) === 1) {
                if (in_array($m[1], $names, true)) {
                    throw new \LogicException(sprintf('Platzhalter doppelt: %s', $path));
                }
                $names[] = $m[1];
                $key[] = '{}';
            } elseif (str_contains($segment, '{') || str_contains($segment, '}')) {
                throw new \LogicException(sprintf('Ungültiger Platzhalter: %s', $path));
            } else {
                $key[] = $segment;
            }
        }

        $route = [$handler, $access, $maxBodyBytes];
        if ($names === []) {
            if (isset($this->exact[$path][$method])) {
                throw new \LogicException(sprintf('Route doppelt: %s %s', $method, $path));
            }
            $this->exact[$path][$method] = $route;

            return $this;
        }

        $key = implode('/', $key);
        if (isset($this->patterns[$key]['methods'][$method])) {
            throw new \LogicException(sprintf('Route doppelt: %s %s', $method, $path));
        }
        if (isset($this->patterns[$key]) && $this->patterns[$key]['segments'] !== $segments) {
            throw new \LogicException(sprintf('Gleicher Pfad mit anderen Platzhalternamen: %s', $path));
        }
        $this->patterns[$key]['segments'] = $segments;
        $this->patterns[$key]['methods'][$method] = $route;

        return $this;
    }

    /**
     * @throws HttpException 404 für unbekannte Pfade, 405 (mit Allow) für falsche Methoden
     */
    public function match(string $method, string $path): RouteMatch
    {
        $params = [];
        $byMethod = $this->exact[$path] ?? null;
        if ($byMethod === null) {
            [$byMethod, $params] = $this->matchPattern($path) ?? throw HttpException::notFound();
        }

        if (isset($byMethod[$method])) {
            [$handler, $access, $maxBodyBytes] = $byMethod[$method];

            return new RouteMatch($handler, $access, $params, $maxBodyBytes);
        }

        $allowed = array_keys($byMethod);
        sort($allowed);

        throw HttpException::methodNotAllowed($allowed);
    }

    /**
     * @return array{array<string, array{RequestHandler, Access, int}>, array<string, int>}|null
     */
    private function matchPattern(string $path): ?array
    {
        $parts = explode('/', $path);
        foreach ($this->patterns as $pattern) {
            if (count($pattern['segments']) !== count($parts)) {
                continue;
            }

            $params = [];
            foreach ($pattern['segments'] as $i => $segment) {
                if (preg_match(self::PLACEHOLDER_PATTERN, $segment, $m) === 1) {
                    if (preg_match(self::ID_PATTERN, $parts[$i]) !== 1) {
                        continue 2;
                    }
                    $params[$m[1]] = (int) $parts[$i];
                } elseif ($segment !== $parts[$i]) {
                    continue 2;
                }
            }

            return [$pattern['methods'], $params];
        }

        return null;
    }
}
