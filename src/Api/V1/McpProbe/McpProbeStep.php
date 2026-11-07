<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\McpProbe;

/**
 * Schritte der Vorklärung zum Claude-Zugang (docs/PLAN-MCP.md, Runde 3), je eine Route.
 */
enum McpProbeStep: string
{
    /** POST mcp ohne Anmeldung: 401 mit WWW-Authenticate (resource_metadata). */
    case Mcp = 'mcp';
    /** GET .well-known/oauth-protected-resource (RFC 9728). */
    case ProtectedResource = 'protected-resource';
    /** GET .well-known/openid-configuration (OpenID Discovery, Variante 3 der MCP-Spezifikation). */
    case OpenIdConfiguration = 'openid-configuration';
    /** GET .well-known/oauth-authorization-server (RFC 8414, angehängt; nur zum Vergleich). */
    case OAuthAuthorizationServer = 'oauth-authorization-server';
    /** GET .well-known/jwks.json: leer, es gibt keine ID-Tokens. */
    case Jwks = 'jwks';
    /** POST oauth/register (RFC 7591): vergibt eine Kennung, speichert nichts. */
    case Register = 'register';
    /** GET claude-verbinden: Platzhalter der Anmeldeseite, zeigt die empfangenen Angaben. */
    case Authorize = 'authorize';
    /** GET api/v1/mcp-probe: das Protokoll lesen (Admin der Test-Stadt). */
    case Trace = 'trace';
}
