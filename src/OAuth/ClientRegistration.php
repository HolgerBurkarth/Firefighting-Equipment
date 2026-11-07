<?php

declare(strict_types=1);

namespace FirefightingEquipment\OAuth;

/**
 * Angaben einer Registrierung (RFC 7591, Abschnitt 2), geprüft: redirect_uris Pflicht (1-5, je nach
 * RedirectUriRules), nur öffentliche Clients (token_endpoint_auth_method none), nur die Grants
 * authorization_code und refresh_token, nur response_types code. Andere Felder (z. B. scope,
 * logo_uri) werden ignoriert.
 */
final readonly class ClientRegistration
{
    public const int MAX_REDIRECT_URIS = 5;
    public const int MAX_NAME_LENGTH = 100;

    private const array GRANT_TYPES = ['authorization_code', 'refresh_token'];

    /**
     * @param list<string> $redirectUris
     */
    private function __construct(public ?string $clientName, public array $redirectUris)
    {
    }

    /**
     * @param array<string, mixed> $metadata JSON-Body der Registrierung
     *
     * @throws OAuthError invalid_redirect_uri, invalid_client_metadata
     */
    public static function fromMetadata(array $metadata): self
    {
        $uris = $metadata['redirect_uris'] ?? null;
        if (!is_array($uris) || !array_is_list($uris) || $uris === [] || count($uris) > self::MAX_REDIRECT_URIS) {
            throw OAuthError::invalidRedirectUri(sprintf('"redirect_uris" muss eine Liste mit 1 bis %d Adressen sein.', self::MAX_REDIRECT_URIS));
        }
        foreach ($uris as $uri) {
            if (!is_string($uri) || !RedirectUriRules::isAllowed($uri)) {
                throw OAuthError::invalidRedirectUri('Erlaubt sind nur die Rückrufe von claude.ai und claude.com sowie http://localhost/callback.');
            }
        }

        $method = $metadata['token_endpoint_auth_method'] ?? 'none';
        if ($method !== 'none') {
            throw OAuthError::invalidClientMetadata('Erlaubt ist nur "token_endpoint_auth_method" "none" (öffentlicher Client mit PKCE).');
        }
        $grants = $metadata['grant_types'] ?? self::GRANT_TYPES;
        if (!self::isStringList($grants) || !in_array('authorization_code', $grants, true) || array_diff($grants, self::GRANT_TYPES) !== []) {
            throw OAuthError::invalidClientMetadata('Erlaubt sind nur die "grant_types" authorization_code und refresh_token.');
        }
        $responseTypes = $metadata['response_types'] ?? ['code'];
        if (!self::isStringList($responseTypes) || array_values(array_unique($responseTypes)) !== ['code']) {
            throw OAuthError::invalidClientMetadata('Erlaubt ist nur der "response_types" code.');
        }

        return new self(self::name($metadata['client_name'] ?? null), array_values(array_unique($uris)));
    }

    /**
     * Name wie angegeben (Rand ohne Leerraum); leer, fehlend, zu lang oder mit Steuerzeichen → kein
     * Name (eine Registrierung scheitert nicht am Anzeigenamen).
     */
    private static function name(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/[\p{Cc}\p{Cf}\x{2028}\x{2029}]/u', $value) !== 0) {
            return null;
        }
        $name = trim($value);

        return $name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH ? null : $name;
    }

    private static function isStringList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value) && $value !== [] && array_all($value, static fn (mixed $v): bool => is_string($v));
    }
}
