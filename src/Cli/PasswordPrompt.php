<?php

declare(strict_types=1);

namespace FirefightingEquipment\Cli;

/**
 * Fragt ein Passwort verdeckt ab.
 */
interface PasswordPrompt
{
    /**
     * @return string Eingabe ohne Zeilenende
     *
     * @throws PasswordPromptException wenn keine verdeckte Eingabe möglich ist
     */
    public function ask(string $label): string;
}
