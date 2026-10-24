<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http;

use FirefightingEquipment\Database\LazyConnection;

/**
 * Bearbeitet eine Anfrage vollständig und liefert immer eine JSON-Antwort.
 *
 * Reihenfolge: Adresse zerlegen, Route suchen, erst dann die Stadt laden (Datenbank),
 * zum Schluss den Handler aufrufen. Unbekannte Adressen verbinden sich so nie mit der Datenbank.
 */
final readonly class Application
{
    public function __construct(
        private Router $serviceRoutes,
        private Router $tenantRoutes,
        private LazyConnection $db,
        private ErrorReporter $errors = new ErrorReporter(),
        private TenantResolver $tenants = new TenantResolver(),
    ) {
    }

    public static function create(string $configPath): self
    {
        return new self(Routes::service(), Routes::tenant(), LazyConnection::fromConfigFile($configPath));
    }

    public function handle(Request $request): JsonResponse
    {
        try {
            $path = AppPath::parse($request->uri) ?? throw HttpException::notFound();
            $routes = $path->isService() ? $this->serviceRoutes : $this->tenantRoutes;
            $handler = $routes->match($request->method, $path->rest);
            $tenant = $path->isService() ? null : $this->tenants->resolve($path->scope, $this->db);

            $response = $handler->handle(new RequestContext($request, $tenant, $this->db, $this->errors));
            // Kodierfehler hier abfangen, nicht erst beim Senden.
            $response->body();

            return $response;
        } catch (HttpException $e) {
            if ($e->getPrevious() !== null) {
                $this->errors->report($e->errorCode, $e->getPrevious());
            }

            return $e->toResponse();
        } catch (\Throwable $e) {
            $this->errors->report('internal_error', $e);

            return HttpException::internalError()->toResponse();
        }
    }
}
