<?php

declare(strict_types=1);

namespace FirefightingEquipment\OAuth;

/**
 * Registrierung eines OAuth-Clients (fe_oauth_clients), z. B. claude.ai oder Claude Code.
 */
final readonly class OAuthClient
{
    /**
     * @param list<string> $redirectUris
     */
    public function __construct(
        public int $id,
        public int $tenantId,
        /** öffentlich, 43 Zeichen base64url */
        public string $clientId,
        public ?string $clientName,
        public array $redirectUris,
    ) {
    }

    /** Exakter Vergleich (Byte für Byte), keine Präfixe. */
    public function allowsRedirectUri(string $uri): bool
    {
        return in_array($uri, $this->redirectUris, true);
    }
}
