<?php

declare(strict_types=1);

namespace FirefightingEquipment\User\Password;

/**
 * Sperrliste häufiger Passwörter und Grundwörter (resources/password-blocklist.txt).
 * Ein Eintrag pro Zeile, Zeilen mit # sind Kommentare. Verglichen wird in der Vergleichsform
 * (PasswordPolicy::fold()).
 */
final readonly class PasswordBlocklist
{
    public const string DEFAULT_PATH = __DIR__ . '/../../../resources/password-blocklist.txt';

    /**
     * @param array<string, true> $entries
     */
    private function __construct(private array $entries)
    {
    }

    /**
     * @throws \RuntimeException wenn die Datei nicht lesbar ist
     */
    public static function fromFile(string $path): self
    {
        $content = is_file($path) ? file_get_contents($path) : false;
        if ($content === false) {
            throw new \RuntimeException(sprintf('Sperrliste nicht lesbar: %s', $path));
        }

        $entries = [];
        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, '#')) {
                $entries[] = $line;
            }
        }

        return self::fromEntries($entries);
    }

    /**
     * @param list<string> $entries
     */
    public static function fromEntries(array $entries): self
    {
        $folded = [];
        foreach ($entries as $entry) {
            $folded[PasswordPolicy::fold($entry)] = true;
        }

        return new self($folded);
    }

    /**
     * @param string $folded Text in der Vergleichsform
     */
    public function contains(#[\SensitiveParameter] string $folded): bool
    {
        return isset($this->entries[$folded]);
    }
}
