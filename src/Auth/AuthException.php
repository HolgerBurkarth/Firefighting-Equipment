<?php

declare(strict_types=1);

namespace FirefightingEquipment\Auth;

/**
 * Basis für abgelehnte Anmeldungen. Die Handler übersetzen sie in HttpException.
 */
abstract class AuthException extends \RuntimeException
{
}
