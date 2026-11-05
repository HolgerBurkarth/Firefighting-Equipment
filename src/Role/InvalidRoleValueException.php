<?php

declare(strict_types=1);

namespace FirefightingEquipment\Role;

/**
 * Ein Wert einer Rolle verletzt die Regeln (Name, Beschreibung, Bereich, Aktion, leerer Datenfilter,
 * Standort oder Ausrüstungstyp einer anderen Stadt).
 */
final class InvalidRoleValueException extends RoleException
{
}
