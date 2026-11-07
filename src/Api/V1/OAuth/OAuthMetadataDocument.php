<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\OAuth;

/**
 * Dokumente von OAuthMetadataHandler.
 */
enum OAuthMetadataDocument
{
    /** GET .well-known/oauth-protected-resource (RFC 9728) */
    case ProtectedResource;
    /** GET .well-known/openid-configuration und .well-known/oauth-authorization-server */
    case AuthorizationServer;
    /** GET .well-known/jwks.json */
    case Jwks;
}
