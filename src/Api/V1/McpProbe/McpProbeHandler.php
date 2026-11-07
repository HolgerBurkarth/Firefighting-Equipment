<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\McpProbe;

use FirefightingEquipment\Device\Seed\TestCityData;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Mcp\Probe\McpProbeTrace;
use FirefightingEquipment\Tenant\Tenant;
use FirefightingEquipment\Time\Clock;

/**
 * Vorklärung zum Claude-Zugang (docs/PLAN-MCP.md, Runde 3): Findet Claude die OAuth-Metadaten unter
 * /firefighting/<stadt>/, ohne die .htaccess des Webspace? Nur in der Test-Stadt (SLUG, fest im
 * Code), sonst 404. Öffentlich, aber harmlos: Es gibt keine Anmeldung, keine Tokens und keine Daten
 * der Stadt; die Registrierung speichert nichts, die Anmeldeseite ist ein Platzhalter. Jeder Abruf
 * kommt in McpProbeTrace (nur feste Schritte und bereinigte Angaben), lesbar für Admins der
 * Test-Stadt. Wird in Runde 4 entfernt bzw. durch den echten Zugang ersetzt.
 */
final readonly class McpProbeHandler implements RequestHandler
{
    /** Einzige Stadt der Vorklärung. */
    public const string SLUG = TestCityData::SLUG;

    public const array SCOPES = ['read', 'write'];

    /** Rückruf von claude.ai; laut Anthropic künftig auch unter claude.com (beide erlaubt). */
    public const array CLAUDE_REDIRECT_URIS = [
        'https://claude.ai/api/mcp/auth_callback',
        'https://claude.com/api/mcp/auth_callback',
    ];

    /** Loopback für Claude Code, Port beliebig (RFC 8252, 7.3). */
    private const string LOOPBACK_REDIRECT_PATTERN = '#^http://(localhost|127\.0\.0\.1)(:[1-9][0-9]{0,4})?/callback$#D';

    private const string CLIENT_ID_PREFIX = 'probe-';

    public function __construct(
        private McpProbeStep $step,
        private McpProbeTrace $trace,
        private Clock $clock,
    ) {
    }

    public function handle(RequestContext $context): JsonResponse
    {
        $tenant = $context->tenant ?? throw new \LogicException('Nur unter einer Stadt.');
        if ($tenant->slug->value !== self::SLUG) {
            throw HttpException::notFound();
        }

        if ($this->step === McpProbeStep::Trace) {
            return new JsonResponse(['entries' => $this->trace->entries($tenant->slug->value)]);
        }

        [$response, $details] = match ($this->step) {
            McpProbeStep::Mcp => $this->mcp($context, $tenant),
            McpProbeStep::ProtectedResource => [new JsonResponse($this->protectedResource($context, $tenant)), []],
            McpProbeStep::OpenIdConfiguration, McpProbeStep::OAuthAuthorizationServer => [new JsonResponse($this->authorizationServer($context, $tenant)), []],
            McpProbeStep::Jwks => [new JsonResponse(['keys' => []]), []],
            McpProbeStep::Register => $this->register($context),
            McpProbeStep::Authorize => $this->authorize($context, $tenant),
        };

        if ($this->step !== McpProbeStep::Authorize) {
            // Nur Anfragen von Servern (Claude); die Anmeldeseite öffnet der Browser der Person.
            $details = ['user_agent' => $context->request->header('user-agent'), ...$details];
        }
        $this->record($context, $tenant, [...$details, 'status' => $response->statusCode()]);

        return $response;
    }

    public static function isAllowedRedirectUri(string $uri): bool
    {
        return in_array($uri, self::CLAUDE_REDIRECT_URIS, true) || preg_match(self::LOOPBACK_REDIRECT_PATTERN, $uri) === 1;
    }

    /**
     * @return array{JsonResponse, array<string, string|bool|int|null|list<string>>}
     */
    private function mcp(RequestContext $context, Tenant $tenant): array
    {
        $method = $context->body['method'] ?? null;
        $details = [
            'jsonrpc_method' => is_string($method) ? $method : null,
            'protocol_version' => $context->request->header('mcp-protocol-version'),
            'accept' => $context->request->header('accept'),
            'authorization' => $context->request->header('authorization') !== null,
            'origin' => $context->request->header('origin'),
        ];
        $header = sprintf(
            'Bearer resource_metadata="%s", scope="%s"',
            self::area($context, $tenant) . '.well-known/oauth-protected-resource',
            implode(' ', self::SCOPES),
        );
        $error = HttpException::notAuthenticated(['WWW-Authenticate' => $header]);

        return [$error->toResponse(), $details];
    }

    /**
     * @return array<string, mixed>
     */
    private function protectedResource(RequestContext $context, Tenant $tenant): array
    {
        return [
            'resource' => self::area($context, $tenant) . 'mcp',
            'authorization_servers' => [self::issuer($context, $tenant)],
            'scopes_supported' => self::SCOPES,
            'bearer_methods_supported' => ['header'],
            'resource_name' => sprintf('Ausrüstungsverwaltung %s (Vorklärung)', $tenant->name->value),
        ];
    }

    /**
     * OpenID-Felder (jwks_uri, subject_types_supported, id_token_signing_alg_values_supported) sind
     * Pflicht der OpenID Discovery; "openid" steht nicht in scopes_supported, ID-Tokens gibt es nie.
     *
     * @return array<string, mixed>
     */
    private function authorizationServer(RequestContext $context, Tenant $tenant): array
    {
        $area = self::area($context, $tenant);

        return [
            'issuer' => self::issuer($context, $tenant),
            'authorization_endpoint' => $area . 'claude-verbinden',
            'token_endpoint' => $area . 'oauth/token',
            'registration_endpoint' => $area . 'oauth/register',
            'jwks_uri' => $area . '.well-known/jwks.json',
            'scopes_supported' => self::SCOPES,
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'code_challenge_methods_supported' => ['S256'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
        ];
    }

    /**
     * Antwort wie RFC 7591 (201), Kennung nicht gespeichert.
     *
     * @return array{JsonResponse, array<string, string|bool|int|null|list<string>>}
     */
    private function register(RequestContext $context): array
    {
        $body = $context->body;
        $redirectUris = array_values(array_filter(
            is_array($body['redirect_uris'] ?? null) ? $body['redirect_uris'] : [],
            'is_string',
        ));
        $allowed = $redirectUris !== [] && array_all($redirectUris, self::isAllowedRedirectUri(...));
        $clientName = is_string($body['client_name'] ?? null) ? $body['client_name'] : null;

        $details = [
            'client_name' => $clientName,
            'redirect_uris' => $redirectUris,
            'redirect_uris_allowed' => $allowed,
            'grant_types' => self::strings($body['grant_types'] ?? null),
            'response_types' => self::strings($body['response_types'] ?? null),
            'token_endpoint_auth_method' => is_string($body['token_endpoint_auth_method'] ?? null) ? $body['token_endpoint_auth_method'] : null,
            'scope' => is_string($body['scope'] ?? null) ? $body['scope'] : null,
            'fields' => array_map('strval', array_keys($body)),
        ];

        return [new JsonResponse([
            'client_id' => self::CLIENT_ID_PREFIX . bin2hex(random_bytes(12)),
            'client_id_issued_at' => $this->clock->now()->getTimestamp(),
            'client_name' => $clientName ?? 'unbekannt',
            'redirect_uris' => $redirectUris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ], 201), $details];
    }

    /**
     * @return array{JsonResponse, array<string, string|bool|int|null|list<string>>}
     */
    private function authorize(RequestContext $context, Tenant $tenant): array
    {
        $query = $context->request->query();
        $redirectUri = $query['redirect_uri'] ?? null;
        $checks = [
            'client_id_from_registration' => str_starts_with($query['client_id'] ?? '', self::CLIENT_ID_PREFIX),
            'response_type_code' => ($query['response_type'] ?? null) === 'code',
            'redirect_uri_allowed' => $redirectUri !== null && self::isAllowedRedirectUri($redirectUri),
            'pkce_s256' => ($query['code_challenge_method'] ?? null) === 'S256' && ($query['code_challenge'] ?? '') !== '',
            'resource_matches' => ($query['resource'] ?? null) === self::area($context, $tenant) . 'mcp',
            'state_present' => ($query['state'] ?? '') !== '',
        ];
        $received = [
            'client_id' => $query['client_id'] ?? null,
            'redirect_uri' => $redirectUri,
            'response_type' => $query['response_type'] ?? null,
            'code_challenge_method' => $query['code_challenge_method'] ?? null,
            'scope' => $query['scope'] ?? null,
            'resource' => $query['resource'] ?? null,
            'parameters' => array_map('strval', array_keys($query)),
        ];

        return [new JsonResponse([
            'vorklaerung' => 'Claude hat die Anmeldeseite der Test-Stadt erreicht: Die Metadaten unter /firefighting/'
                . $tenant->slug->value . '/ wurden gefunden. Hier gibt es noch keine Anmeldung; '
                . 'bitte dieses Fenster schließen (in Claude erscheint danach ein Fehler, das ist erwartet).',
            'checks' => $checks,
            'received' => array_map(
                static fn (string|array|null $v): string|array|null => is_string($v) ? McpProbeTrace::clean($v) : $v,
                $received,
            ),
        ]), [...$checks, ...array_diff_key($received, ['client_id' => true])]];
    }

    /**
     * @param array<string, string|bool|int|null|list<string>> $details
     */
    private function record(RequestContext $context, Tenant $tenant, array $details): void
    {
        try {
            $this->trace->record($tenant->slug->value, $this->step->value, $this->clock->now(), $details);
        } catch (\Throwable $e) {
            $context->errors->report('mcp_probe_trace_failed', $e);
        }
    }

    /**
     * @return list<string>|null
     */
    private static function strings(mixed $value): ?array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : null;
    }

    private static function area(RequestContext $context, Tenant $tenant): string
    {
        return $context->urls->area($tenant);
    }

    /** Ohne / am Ende (RFC 8414: Issuer ohne abschließenden Slash). */
    private static function issuer(RequestContext $context, Tenant $tenant): string
    {
        return rtrim(self::area($context, $tenant), '/');
    }
}
