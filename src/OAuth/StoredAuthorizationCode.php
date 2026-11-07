<?php

declare(strict_types=1);

namespace FirefightingEquipment\OAuth;

use FirefightingEquipment\Claude\ClaudeAccess;

/**
 * Ein eingelöster (schon gelöschter) Code mit seinen Bindungen.
 */
final readonly class StoredAuthorizationCode
{
    public function __construct(
        public int $userId,
        public int $oauthClientId,
        public string $redirectUri,
        public string $codeChallenge,
        public ClaudeAccess $access,
        /** noch nicht abgelaufen */
        public bool $valid,
    ) {
    }
}
