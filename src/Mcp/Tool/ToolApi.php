<?php

declare(strict_types=1);

namespace FirefightingEquipment\Mcp\Tool;

use FirefightingEquipment\Auth\AuthSession;
use FirefightingEquipment\Http\AppPath;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\Request;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RouteMatch;
use FirefightingEquipment\Http\Router;
use FirefightingEquipment\Permission\Permissions;
use FirefightingEquipment\Permission\Requirement;

/**
 * Ruft für ein Werkzeug eine REST-Route der Stadt auf (docs/PLAN-MCP.md, Abschnitt 6): Route aus
 * demselben Router wie die App, geprüft mit ihrem Erfordernis (samt Feldregeln) gegen die wirksamen
 * Rechte der Claude-Verbindung, dann der Handler mit derselben Stadt, demselben Konto und
 * demselben Datenfilter. Nur Routen, die das Werkzeug nennt; nur Routen mit Requirement (also nie
 * öffentliche, Anmeldung, Sitzungen oder Routen des Super-Admins).
 */
final readonly class ToolApi
{
    /**
     * @param list<string> $routes Routen des Werkzeugs, z. B. "GET api/v1/devices/{id}"
     */
    public function __construct(
        private Router $router,
        private RequestContext $mcp,
        private AuthSession $auth,
        private array $routes,
    ) {
    }

    /**
     * @param array<string, int>    $params
     * @param array<string, scalar> $query
     *
     * @return array<array-key, mixed>
     */
    public function get(string $route, array $params = [], array $query = []): array
    {
        return $this->call('GET', $route, $params, $query, null);
    }

    /**
     * @param array<string, int>   $params
     * @param array<string, mixed> $body
     *
     * @return array<array-key, mixed>
     */
    public function post(string $route, array $params = [], array $body = []): array
    {
        return $this->call('POST', $route, $params, [], $body);
    }

    /**
     * Buchungs-Kennung (client_ref, UUID v4) aus Verbindung, Art, Ausrüstung und Inhalt: derselbe
     * Aufruf (gleiche Version) ergibt dieselbe Kennung, der Server bucht ihn nicht doppelt.
     *
     * @param array<string, mixed> $content
     */
    public function stableRef(string $kind, int $deviceId, array $content): string
    {
        $connection = $this->auth->claude?->connection->id ?? throw new \LogicException('Ohne Claude-Verbindung.');
        $hash = hash('sha256', implode("\n", [$connection, $kind, $deviceId, json_encode($content, JSON_THROW_ON_ERROR)]));
        $hex = substr($hash, 0, 12) . '4' . substr($hash, 13, 3) . dechex(8 + (hexdec($hash[16]) & 3)) . substr($hash, 17, 15);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }

    /**
     * Ob die Route mit den wirksamen Rechten erreichbar ist (ohne Feldregeln eines Bodys), z. B. für
     * Teile einer Antwort, die nicht jede Rolle sieht.
     */
    public function allows(string $route): bool
    {
        [$method, $pattern] = $this->split($route);

        return self::requirementOf($this->router, $method, $pattern)->allows($this->permissions(), []);
    }

    /**
     * Erfordernis einer Route (Platzhalter durch 1 ersetzt); jede Route eines Werkzeugs muss eines
     * haben.
     */
    public static function requirementOf(Router $router, string $method, string $pattern): Requirement
    {
        $access = $router->match($method, (string) preg_replace('/\{[a-z_]+\}/', '1', $pattern))->access;
        if (!$access instanceof Requirement) {
            throw new \LogicException(sprintf('Route ohne Requirement für ein Werkzeug: %s %s', $method, $pattern));
        }

        return $access;
    }

    /**
     * @param array<string, int>        $params
     * @param array<string, scalar>     $query
     * @param array<string, mixed>|null $body
     *
     * @return array<array-key, mixed> Inhalt der JSON-Antwort
     *
     * @throws ToolFailure Fehler der Route (wie ihre Fehlerantwort) oder fehlende Rechte
     */
    private function call(string $method, string $pattern, array $params, array $query, ?array $body): array
    {
        $this->split($method . ' ' . $pattern);
        $path = (string) preg_replace_callback(
            '/\{([a-z_]+)\}/',
            static fn (array $m): string => (string) ($params[$m[1]] ?? throw new \LogicException('Platzhalter fehlt: ' . $m[1])),
            $pattern,
        );
        $match = $this->match($method, $path);
        $requirement = $match->access;
        if (!$requirement instanceof Requirement) {
            throw new \LogicException(sprintf('Route ohne Requirement für ein Werkzeug: %s %s', $method, $pattern));
        }
        $body ??= [];
        if (!$requirement->allows($this->permissions(), $body)) {
            throw ToolFailure::forbidden();
        }
        $json = $method === 'GET' ? null : json_encode($body === [] ? new \stdClass() : $body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if ($json !== null && strlen($json) > $match->maxBodyBytes) {
            throw ToolFailure::invalidArguments(sprintf('Die Angaben sind zu groß (höchstens %d KiB).', intdiv($match->maxBodyBytes, 1024)));
        }
        $query = array_map(static fn (mixed $v): string => is_bool($v) ? ($v ? '1' : '0') : (string) $v, $query);
        $tenant = $this->mcp->tenant ?? throw new \LogicException('Werkzeuge nur unter einer Stadt.');
        $request = new Request(
            $method,
            AppPath::PREFIX . '/' . $tenant->slug->value . '/' . $path . ($query === [] ? '' : '?' . http_build_query($query, encoding_type: PHP_QUERY_RFC3986)),
            ['content-type' => 'application/json'],
            [],
            $json,
            remoteAddress: $this->mcp->request->remoteAddress,
        );
        $context = new RequestContext(
            $request,
            $tenant,
            $this->mcp->db,
            $this->mcp->errors,
            $match->params,
            $body,
            $this->auth,
            $this->mcp->hasher,
            $this->mcp->mailer,
            $this->mcp->urls,
            $this->permissions(),
        );
        try {
            $response = $match->handler->handle($context);
        } catch (HttpException $e) {
            if ($e->getPrevious() !== null) {
                $this->mcp->errors->report($e->errorCode, $e->getPrevious());
            }

            throw ToolFailure::fromHttp($e);
        }
        if (!$response instanceof JsonResponse) {
            throw new \LogicException(sprintf('Route ohne JSON-Antwort für ein Werkzeug: %s %s', $method, $pattern));
        }

        return $response->data();
    }

    private function match(string $method, string $path): RouteMatch
    {
        try {
            return $this->router->match($method, $path);
        } catch (HttpException $e) {
            throw new \LogicException(sprintf('Route des Werkzeugs fehlt: %s %s', $method, $path), 0, $e);
        }
    }

    /**
     * @return array{string, string} Methode und Muster; nur Routen, die das Werkzeug nennt
     */
    private function split(string $route): array
    {
        if (!in_array($route, $this->routes, true)) {
            throw new \LogicException(sprintf('Das Werkzeug nennt die Route nicht: %s', $route));
        }

        return explode(' ', $route, 2) + [1 => ''];
    }

    private function permissions(): Permissions
    {
        return $this->auth->permissions ?? throw new \LogicException('Claude-Sitzung ohne Rechte.');
    }
}
