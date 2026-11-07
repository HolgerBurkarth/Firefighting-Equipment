<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\OAuth;

use FirefightingEquipment\Claude\ClaudeConnectionRepository;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Mail\MailTemplates;
use FirefightingEquipment\OAuth\OAuthClient;
use FirefightingEquipment\OAuth\OAuthClientRepository;
use FirefightingEquipment\OAuth\OAuthError;
use FirefightingEquipment\OAuth\OAuthUrls;
use FirefightingEquipment\OAuth\RedirectUriRules;
use FirefightingEquipment\OAuth\TokenService;
use FirefightingEquipment\Tenant\Tenant;

/**
 * POST oauth/token (öffentlich, Formular; OAuth 2.1, Abschnitt 3.2) und POST oauth/revoke (RFC 7009).
 * Fehler im OAuth-Format (OAuthError). Unbekannter Client → 401 invalid_client (Claude registriert
 * neu). Nach einer neuen Verbindung geht die Mail „Neue Claude-Verbindung“ an das Konto (O6).
 * Räumt abgelaufene Codes und Verbindungen der Stadt auf (Ersatz für einen Cronjob).
 */
final readonly class TokenHandler implements RequestHandler
{
    public function __construct(private bool $revoke = false)
    {
    }

    public function handle(RequestContext $context): JsonResponse
    {
        $tenant = ClaudeTenant::of($context);
        try {
            return $this->revoke ? $this->revoke($context, $tenant) : $this->token($context, $tenant);
        } catch (OAuthError $e) {
            return $e->toResponse();
        }
    }

    private function token(RequestContext $context, Tenant $tenant): JsonResponse
    {
        $grantType = self::param($context, 'grant_type');
        if ($grantType !== 'authorization_code' && $grantType !== 'refresh_token') {
            throw OAuthError::unsupportedGrantType();
        }
        $resource = self::optionalParam($context, 'resource');
        if ($resource !== null && $resource !== new OAuthUrls($context->urls, $tenant)->resource()) {
            throw OAuthError::invalidTarget();
        }
        $scope = self::optionalParam($context, 'scope');
        if ($scope !== null && array_diff(array_filter(explode(' ', $scope)), OAuthUrls::SCOPES) !== []) {
            throw OAuthError::invalidScope();
        }

        $pdo = $context->db->get();
        $client = $this->client($context, $tenant);
        new ClaudeConnectionRepository($pdo)->deleteExpired($tenant->id);
        $service = new TokenService($pdo);

        if ($grantType === 'refresh_token') {
            return new JsonResponse($service->refresh($tenant, $client, self::param($context, 'refresh_token'))->toArray());
        }

        $redirectUri = self::param($context, 'redirect_uri');
        $issued = $service->exchangeCode(
            $tenant,
            $client,
            self::param($context, 'code'),
            $redirectUri,
            self::param($context, 'code_verifier'),
        );
        $context->notifier()->send('claude_connected', $issued->user->id, static fn () => MailTemplates::claudeConnected(
            $issued->user,
            $tenant,
            RedirectUriRules::targetLabel($redirectUri),
            $issued->access,
            $context->urls->area($tenant),
        ));

        return new JsonResponse($issued->toArray());
    }

    private function revoke(RequestContext $context, Tenant $tenant): JsonResponse
    {
        $token = self::param($context, 'token');
        new TokenService($context->db->get())->revoke($tenant, $this->client($context, $tenant), $token);

        return new JsonResponse([]);
    }

    /**
     * @throws OAuthError invalid_request, invalid_client
     */
    private function client(RequestContext $context, Tenant $tenant): OAuthClient
    {
        return new OAuthClientRepository($context->db->get())->find($tenant->id, self::param($context, 'client_id'))
            ?? throw OAuthError::invalidClient();
    }

    /**
     * @throws OAuthError invalid_request
     */
    private static function param(RequestContext $context, string $name): string
    {
        $value = self::optionalParam($context, $name);
        if ($value === null || $value === '') {
            throw OAuthError::invalidRequest(sprintf('Der Parameter "%s" fehlt.', $name));
        }

        return $value;
    }

    private static function optionalParam(RequestContext $context, string $name): ?string
    {
        $value = $context->body[$name] ?? null;

        return is_string($value) ? $value : null;
    }
}
