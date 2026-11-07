<?php

declare(strict_types=1);

namespace FirefightingEquipment\OAuth;

/**
 * Art eines OAuthSecret; der Wert ist das Präfix.
 */
enum OAuthSecretKind: string
{
    /** Zugriffstoken (1 Stunde), im Header Authorization: Bearer. */
    case AccessToken = 'ffa_';
    /** Refresh-Token (rotiert bei jedem Erneuern). */
    case RefreshToken = 'ffr_';
    /** Code der Anmeldung (einmal, wenige Minuten). */
    case Code = 'ffc_';
}
