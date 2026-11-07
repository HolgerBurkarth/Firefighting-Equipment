<?php

declare(strict_types=1);

namespace FirefightingEquipment\OAuth;

use FirefightingEquipment\Claude\ClaudeAccess;

/**
 * Geprüfte Anfrage der Seite „Claude verbinden“ (Parameter aus der Adresse, OAuth 2.1 mit PKCE S256
 * und RFC 8707). Zuerst Client und Rücksprung: Sind sie nicht exakt registriert, gibt es keinen
 * Rücksprung (kein offener Redirect). Erst danach führen Fehler mit error und dem unveränderten
 * state zurück zu Claude.
 */
final readonly class AuthorizationRequest
{
    public const int MAX_STATE_LENGTH = 500;

    private function __construct(
        public OAuthClient $client,
        public string $redirectUri,
        public ?string $state,
        public string $codeChallenge,
        /** angefragter Zugang: write, wenn der Scope write enthält, sonst read */
        public ClaudeAccess $requestedAccess,
        public string $issuer,
    ) {
    }

    /**
     * @param array<string, mixed> $params Parameter der Adresse (nur Texte zählen)
     *
     * @throws AuthorizationRequestException
     */
    public static function fromParams(array $params, OAuthClientRepository $clients, int $tenantId, OAuthUrls $urls): self
    {
        $value = static fn (string $name): ?string => is_string($params[$name] ?? null) ? $params[$name] : null;

        $clientId = $value('client_id');
        $client = $clientId === null || $clientId === '' ? null : $clients->find($tenantId, $clientId);
        if ($client === null) {
            throw AuthorizationRequestException::withoutRedirect('Diese Anwendung ist bei der Stadt nicht registriert. Bitte die Verbindung in Claude neu starten.');
        }
        $redirectUri = $value('redirect_uri');
        if ($redirectUri === null || !$client->allowsRedirectUri($redirectUri)) {
            throw AuthorizationRequestException::withoutRedirect('Die Rücksprung-Adresse ist für diese Anwendung nicht registriert.');
        }
        $state = $value('state');
        if ($state !== null && strlen($state) > self::MAX_STATE_LENGTH) {
            throw AuthorizationRequestException::withoutRedirect('Die Anfrage ist zu lang.');
        }

        $issuer = $urls->issuer();
        $fail = static fn (string $error, string $description): AuthorizationRequestException => AuthorizationRequestException::withRedirect(
            $description,
            self::redirect($redirectUri, ['error' => $error, 'error_description' => $description], $state, $issuer),
        );

        if ($value('response_type') !== 'code') {
            throw $fail('unsupported_response_type', 'Erlaubt ist nur response_type=code.');
        }
        $challenge = $value('code_challenge');
        if ($value('code_challenge_method') !== Pkce::METHOD || $challenge === null || !Pkce::isValidChallenge($challenge)) {
            throw $fail('invalid_request', 'PKCE mit S256 ist Pflicht.');
        }
        $resource = $value('resource');
        if ($resource !== null && $resource !== $urls->resource()) {
            throw $fail('invalid_target', 'Die Ressource ist nicht die MCP-Adresse dieser Stadt.');
        }
        $scope = $value('scope');
        $scopes = $scope === null ? OAuthUrls::SCOPES : array_values(array_filter(explode(' ', $scope), static fn (string $s): bool => $s !== ''));
        if ($scopes === [] || array_diff($scopes, OAuthUrls::SCOPES) !== []) {
            throw $fail('invalid_scope', 'Erlaubt sind nur die Scopes read und write.');
        }

        return new self(
            $client,
            $redirectUri,
            $state,
            $challenge,
            in_array('write', $scopes, true) ? ClaudeAccess::Write : ClaudeAccess::Read,
            $issuer,
        );
    }

    /**
     * Rücksprung zu Claude mit Code bzw. Fehler, state unverändert und iss (RFC 9207).
     *
     * @param array<string, string> $params
     */
    public function redirectWith(array $params): string
    {
        return self::redirect($this->redirectUri, $params, $this->state, $this->issuer);
    }

    /**
     * @param array<string, string> $params
     */
    private static function redirect(string $redirectUri, array $params, ?string $state, string $issuer): string
    {
        if ($state !== null) {
            $params['state'] = $state;
        }
        $params['iss'] = $issuer;

        return RedirectUriRules::withParams($redirectUri, $params);
    }
}
