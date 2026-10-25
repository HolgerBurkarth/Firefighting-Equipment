<?php

declare(strict_types=1);

namespace FirefightingEquipment\Auth;

/**
 * Zweck eines Einmal-Links (fe_user_tokens.purpose, Werte wie chk_fe_user_tokens_purpose).
 */
enum TokenPurpose: string
{
    case VerifyEmail = 'verify_email';
    case SetPassword = 'set_password';
    case ResetPassword = 'reset_password';

    /** Gültigkeit in Sekunden (Höchstwerte prüft auch chk_fe_user_tokens_expires). */
    public function lifetimeSeconds(): int
    {
        return match ($this) {
            self::ResetPassword => 3600,
            self::VerifyEmail, self::SetPassword => 86400,
        };
    }
}
