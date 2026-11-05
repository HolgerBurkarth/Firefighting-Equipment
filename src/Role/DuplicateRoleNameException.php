<?php

declare(strict_types=1);

namespace FirefightingEquipment\Role;

/**
 * Der Name ist in der Stadt schon vergeben (UNIQUE-Index, ohne Groß- und Kleinschreibung).
 */
final class DuplicateRoleNameException extends RoleException
{
    public static function forName(string $name): self
    {
        return new self(sprintf('In dieser Stadt gibt es bereits eine Rolle „%s“.', $name));
    }
}
