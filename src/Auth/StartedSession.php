<?php

declare(strict_types=1);

namespace FirefightingEquipment\Auth;

/**
 * Neu angelegte Sitzung: das Token geht nur einmal als Cookie an den Client.
 */
final readonly class StartedSession
{
    public function __construct(
        public AuthSession $auth,
        public SessionToken $token,
    ) {
    }
}
