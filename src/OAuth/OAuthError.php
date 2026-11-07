<?php

declare(strict_types=1);

namespace FirefightingEquipment\OAuth;

use FirefightingEquipment\Http\JsonResponse;

/**
 * Fehler im Format von OAuth (RFC 6749, 5.2; RFC 7591, 3.2.2; docs/PLAN-MCP.md, Abschnitt 4):
 * {"error":"invalid_grant","error_description":"…"}. Nur für oauth/token, oauth/revoke und
 * oauth/register; die Beschreibung nennt nie Token, Code, Verifier oder Konten.
 */
final class OAuthError extends \RuntimeException
{
    private function __construct(public readonly string $error, string $description, public readonly int $status)
    {
        parent::__construct($description);
    }

    public static function invalidRequest(string $description): self
    {
        return new self('invalid_request', $description, 400);
    }

    /** Unbekannter Client: Claude registriert sich daraufhin neu. */
    public static function invalidClient(): self
    {
        return new self('invalid_client', 'Die Anwendung ist nicht (mehr) registriert.', 401);
    }

    /** Code bzw. Refresh-Token ungültig, abgelaufen, schon benutzt oder für einen anderen Client. */
    public static function invalidGrant(): self
    {
        return new self('invalid_grant', 'Die Anmeldung ist ungültig oder abgelaufen. Bitte neu verbinden.', 400);
    }

    public static function unsupportedGrantType(): self
    {
        return new self('unsupported_grant_type', 'Erlaubt sind nur authorization_code und refresh_token.', 400);
    }

    public static function invalidScope(): self
    {
        return new self('invalid_scope', 'Erlaubt sind nur die Scopes read und write.', 400);
    }

    public static function invalidTarget(): self
    {
        return new self('invalid_target', 'Die Ressource ist nicht die MCP-Adresse dieser Stadt.', 400);
    }

    public static function invalidRedirectUri(string $description): self
    {
        return new self('invalid_redirect_uri', $description, 400);
    }

    public static function invalidClientMetadata(string $description): self
    {
        return new self('invalid_client_metadata', $description, 400);
    }

    public function toResponse(): JsonResponse
    {
        return new JsonResponse(['error' => $this->error, 'error_description' => $this->getMessage()], $this->status);
    }
}
