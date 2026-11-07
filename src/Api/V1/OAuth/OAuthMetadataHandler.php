<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\OAuth;

use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\OAuth\OAuthUrls;

/**
 * Öffentliche Metadaten unter .well-known/ (docs/PLAN-MCP.md, Abschnitte 1 und 5): Ressource (RFC
 * 9728), Autorisierungsserver (OpenID Discovery bzw. RFC 8414, gleiches Dokument) und eine leere
 * Schlüsselliste (es gibt keine ID-Tokens). Nur in Städten mit Claude-Zugang, sonst 404.
 */
final readonly class OAuthMetadataHandler implements RequestHandler
{
    public function __construct(private OAuthMetadataDocument $document)
    {
    }

    public function handle(RequestContext $context): JsonResponse
    {
        $urls = new OAuthUrls($context->urls, ClaudeTenant::of($context));

        return new JsonResponse(match ($this->document) {
            OAuthMetadataDocument::ProtectedResource => $urls->protectedResourceDocument(),
            OAuthMetadataDocument::AuthorizationServer => $urls->authorizationServerDocument(),
            OAuthMetadataDocument::Jwks => ['keys' => []],
        });
    }
}
