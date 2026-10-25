<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http;

use FirefightingEquipment\Auth\AuthSession;
use FirefightingEquipment\Auth\SessionCookie;
use FirefightingEquipment\Auth\SessionManager;
use FirefightingEquipment\Database\ConnectionException;
use FirefightingEquipment\Database\LazyConnection;
use FirefightingEquipment\Tenant\Tenant;
use FirefightingEquipment\User\Password\PasswordHasher;

/**
 * Bearbeitet eine Anfrage vollständig und liefert immer eine JSON-Antwort.
 *
 * Reihenfolge: Adresse zerlegen, Route suchen, ändernde Anfragen prüfen (CSRF, JSON-Body; ohne
 * Datenbank), erst dann die Stadt laden (Datenbank), Sitzung und Zugriffsstufe prüfen (nur bei
 * Routen, die nicht öffentlich sind), zum Schluss den Handler aufrufen. Unbekannte Adressen
 * verbinden sich so nie mit der Datenbank.
 */
final readonly class Application
{
    public function __construct(
        private Router $serviceRoutes,
        private Router $tenantRoutes,
        private LazyConnection $db,
        private ErrorReporter $errors = new ErrorReporter(),
        private TenantResolver $tenants = new TenantResolver(),
        private RequestGuard $guard = new RequestGuard(),
        private PasswordHasher $hasher = new PasswordHasher(),
    ) {
    }

    /**
     * Produktion: Argon2id immer mit den Standardparametern, nie aus der Konfigurationsdatei.
     */
    public static function create(string $configPath): self
    {
        return new self(
            Routes::service(),
            Routes::tenant(),
            LazyConnection::fromConfigFile($configPath),
            guard: new RequestGuard(AllowedOrigins::fromConfigFile($configPath)),
        );
    }

    public function handle(Request $request): JsonResponse
    {
        try {
            $path = AppPath::parse($request->uri) ?? throw HttpException::notFound();
            $routes = $path->isService() ? $this->serviceRoutes : $this->tenantRoutes;
            $route = $routes->match($request->method, $path->rest);
            $body = $this->guard->check($request);
            $tenant = $path->isService() ? null : $this->tenants->resolve($path->scope, $this->db);

            $auth = null;
            if ($route->access !== Access::Public) {
                $auth = $this->authenticate($request, $tenant);
                if (!$route->access->allows($auth->user->role)) {
                    throw HttpException::forbidden();
                }
            }

            $context = new RequestContext($request, $tenant, $this->db, $this->errors, $route->params, $body, $auth, $this->hasher);
            $response = $route->handler->handle($context);
            // Kodierfehler hier abfangen, nicht erst beim Senden.
            $response->body();

            return $response;
        } catch (HttpException $e) {
            if ($e->getPrevious() !== null) {
                $this->errors->report($e->errorCode, $e->getPrevious());
            }

            return $e->toResponse();
        } catch (ConnectionException $e) {
            // Verbindung erst im Handler aufgebaut (z. B. Anmeldung unter service).
            $this->errors->report('database_unavailable', $e);

            return HttpException::databaseUnavailable($e)->toResponse();
        } catch (\Throwable $e) {
            $this->errors->report('internal_error', $e);

            return HttpException::internalError()->toResponse();
        }
    }

    /**
     * @throws HttpException 401 not_authenticated (ein ungültiges Cookie wird gelöscht),
     *                       503 database_unavailable
     */
    private function authenticate(Request $request, ?Tenant $tenant): AuthSession
    {
        $token = SessionCookie::tokenFrom($request);
        try {
            $auth = $token === null ? null : new SessionManager($this->db->get())->resume($token, $tenant);
        } catch (ConnectionException | \PDOException $e) {
            throw HttpException::databaseUnavailable($e);
        }

        if ($auth === null) {
            throw HttpException::notAuthenticated(
                SessionCookie::isPresent($request) ? ['Set-Cookie' => SessionCookie::clear($tenant)] : [],
            );
        }

        return $auth;
    }
}
