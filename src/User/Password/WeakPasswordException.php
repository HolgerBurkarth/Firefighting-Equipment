<?php

declare(strict_types=1);

namespace FirefightingEquipment\User\Password;

use FirefightingEquipment\User\UserException;

/**
 * Das Passwort verletzt die Regeln. Die Meldung enthält nie das Passwort.
 */
final class WeakPasswordException extends UserException
{
}
