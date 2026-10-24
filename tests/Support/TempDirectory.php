<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Support;

/**
 * Temporärer Ordner für Tests, der nach dem Test vollständig gelöscht wird.
 */
final readonly class TempDirectory
{
    public string $path;

    public function __construct()
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fe-test-' . bin2hex(random_bytes(8));
        if (!mkdir($path, 0700)) {
            throw new \RuntimeException(sprintf('Temporärer Ordner konnte nicht angelegt werden: %s', $path));
        }
        $this->path = $path;
    }

    /**
     * Kopiert alle Dateien eines Fixture-Ordners (ohne Unterordner).
     */
    public static function copyOf(string $source): self
    {
        $dir = new self();
        foreach (scandir($source) ?: [] as $entry) {
            if (is_file($source . DIRECTORY_SEPARATOR . $entry)) {
                copy($source . DIRECTORY_SEPARATOR . $entry, $dir->file($entry));
            }
        }

        return $dir;
    }

    public function file(string $name): string
    {
        return $this->path . DIRECTORY_SEPARATOR . $name;
    }

    public function write(string $name, string $content): void
    {
        file_put_contents($this->file($name), $content);
    }

    public function remove(): void
    {
        self::removeRecursive($this->path);
    }

    private static function removeRecursive(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . DIRECTORY_SEPARATOR . $entry;
            is_dir($child) ? self::removeRecursive($child) : unlink($child);
        }
        rmdir($path);
    }
}
