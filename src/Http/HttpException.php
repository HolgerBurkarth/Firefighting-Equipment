<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http;

/**
 * Bekannter Fehler einer Anfrage, der als JSON im einheitlichen Fehlerformat beantwortet wird.
 * Die Meldung ist für Benutzer bestimmt; technische Details stehen nur in $previous.
 */
final class HttpException extends \RuntimeException
{
    /**
     * @param array<string, string> $headers
     */
    private function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $statusCode,
        public readonly array $headers = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function notFound(): self
    {
        return new self('not_found', 'Die Adresse wurde nicht gefunden.', 404);
    }

    public static function tenantNotFound(): self
    {
        return new self('tenant_not_found', 'Diese Stadt ist nicht bekannt.', 404);
    }

    public static function tenantInactive(): self
    {
        return new self('tenant_inactive', 'Diese Stadt ist gesperrt.', 403);
    }

    /**
     * @param list<string> $allowedMethods
     */
    public static function methodNotAllowed(array $allowedMethods): self
    {
        return new self(
            'method_not_allowed',
            'Diese Methode ist für die Adresse nicht erlaubt.',
            405,
            ['Allow' => implode(', ', $allowedMethods)],
        );
    }

    public static function databaseUnavailable(\Throwable $previous): self
    {
        return new self(
            'database_unavailable',
            'Die Datenbank ist zurzeit nicht erreichbar.',
            503,
            previous: $previous,
        );
    }

    public static function internalError(): self
    {
        return new self('internal_error', 'Interner Fehler.', 500);
    }

    public function toResponse(): JsonResponse
    {
        return JsonResponse::error($this->errorCode, $this->getMessage(), $this->statusCode, $this->headers);
    }
}
