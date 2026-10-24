<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Support;

use FirefightingEquipment\Cli\PasswordPrompt;
use FirefightingEquipment\Cli\PasswordPromptException;

/**
 * Liefert vorgegebene Antworten statt einer verdeckten Terminal-Eingabe und merkt sich die Fragen.
 */
final class FakePasswordPrompt implements PasswordPrompt
{
    /** @var list<string> */
    public array $asked = [];

    /**
     * @param list<string> $answers
     */
    public function __construct(private array $answers = [])
    {
    }

    public function ask(string $label): string
    {
        $this->asked[] = $label;

        return array_shift($this->answers) ?? throw new PasswordPromptException('Keine Eingabe erhalten.');
    }
}
