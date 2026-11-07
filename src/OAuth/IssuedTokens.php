<?php

declare(strict_types=1);

namespace FirefightingEquipment\OAuth;

use FirefightingEquipment\Claude\ClaudeAccess;
use FirefightingEquipment\User\User;

/**
 * Ergebnis des Token-Endpunkts: das neue Paar (nur hier im Klartext) und der wirksame Zugang.
 */
final readonly class IssuedTokens
{
    public function __construct(
        public OAuthSecret $accessToken,
        public OAuthSecret $refreshToken,
        public int $expiresIn,
        public ClaudeAccess $access,
        public User $user,
        public int $connectionId,
        /** true = neue Verbindung (Code eingelöst), false = erneuert */
        public bool $created,
    ) {
    }

    /**
     * Antwort nach RFC 6749, 5.1; scope = wirksamer Zugang ("read" bzw. "read write").
     *
     * @return array<string, string|int>
     */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken->value(),
            'token_type' => 'Bearer',
            'expires_in' => $this->expiresIn,
            'refresh_token' => $this->refreshToken->value(),
            'scope' => $this->access === ClaudeAccess::Write ? 'read write' : 'read',
        ];
    }

    public function __debugInfo(): array
    {
        return ['access' => $this->access, 'connectionId' => $this->connectionId, 'created' => $this->created];
    }
}
