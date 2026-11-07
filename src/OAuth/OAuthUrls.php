<?php

declare(strict_types=1);

namespace FirefightingEquipment\OAuth;

use FirefightingEquipment\Http\AppUrls;
use FirefightingEquipment\Tenant\Tenant;

/**
 * Adressen des OAuth-Servers und der MCP-Ressource einer Stadt (absolut, über app.base_url):
 * Issuer = https://www.prodad.de/firefighting/<kürzel> (ohne / am Ende, RFC 8414), Ressource =
 * <issuer>/mcp (RFC 8707), Metadaten unter <issuer>/.well-known/… (docs/PLAN-MCP.md, Abschnitt 1).
 */
final readonly class OAuthUrls
{
    public const string AUTHORIZE_PAGE = 'claude-verbinden';
    public const array SCOPES = ['read', 'write'];

    public function __construct(private AppUrls $urls, private Tenant $tenant)
    {
    }

    public function issuer(): string
    {
        return rtrim($this->urls->area($this->tenant), '/');
    }

    public function resource(): string
    {
        return $this->issuer() . '/mcp';
    }

    public function protectedResourceMetadata(): string
    {
        return $this->issuer() . '/.well-known/oauth-protected-resource';
    }

    /**
     * Wert für WWW-Authenticate einer Antwort 401 an mcp (RFC 9728, Abschnitt 5.1; RFC 6750).
     */
    public function challenge(bool $invalidToken): string
    {
        return sprintf(
            'Bearer %sresource_metadata="%s", scope="%s"',
            $invalidToken ? 'error="invalid_token", ' : '',
            $this->protectedResourceMetadata(),
            implode(' ', self::SCOPES),
        );
    }

    /**
     * RFC 9728.
     *
     * @return array<string, mixed>
     */
    public function protectedResourceDocument(): array
    {
        return [
            'resource' => $this->resource(),
            'authorization_servers' => [$this->issuer()],
            'scopes_supported' => self::SCOPES,
            'bearer_methods_supported' => ['header'],
            'resource_name' => sprintf('Ausrüstungsverwaltung %s', $this->tenant->name->value),
        ];
    }

    /**
     * Metadaten des Autorisierungsservers (RFC 8414 bzw. OpenID Discovery). Die OpenID-Felder
     * (jwks_uri, subject_types_supported, id_token_signing_alg_values_supported) sind Pflicht der
     * Discovery; "openid" steht nicht in scopes_supported, ID-Tokens gibt es nie.
     *
     * @return array<string, mixed>
     */
    public function authorizationServerDocument(): array
    {
        $issuer = $this->issuer();

        return [
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer . '/' . self::AUTHORIZE_PAGE,
            'token_endpoint' => $issuer . '/oauth/token',
            'registration_endpoint' => $issuer . '/oauth/register',
            'revocation_endpoint' => $issuer . '/oauth/revoke',
            'jwks_uri' => $issuer . '/.well-known/jwks.json',
            'scopes_supported' => self::SCOPES,
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'revocation_endpoint_auth_methods_supported' => ['none'],
            'code_challenge_methods_supported' => [Pkce::METHOD],
            'authorization_response_iss_parameter_supported' => true,
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
        ];
    }
}
