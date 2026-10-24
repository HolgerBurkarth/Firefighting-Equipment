<?php

declare(strict_types=1);

namespace FirefightingEquipment\Migration;

/**
 * Liest den Migrationsordner ein und prüft Dateinamen und Nummerierung.
 */
final readonly class MigrationDirectory
{
    public const string FILENAME_PATTERN = '/^(\d{4})_([a-z0-9_]+)\.sql$/D';

    public function __construct(private string $path)
    {
    }

    /**
     * Dateien, die mit einem Punkt beginnen (z. B. .gitkeep), werden ignoriert;
     * jede andere Datei muss dem Namensschema entsprechen.
     *
     * @return list<MigrationFile> nach Version sortiert, lückenlos ab 0001
     *
     * @throws MigrationException
     */
    public function load(): array
    {
        if (!is_dir($this->path)) {
            throw new MigrationException(sprintf('Migrationsordner nicht gefunden: %s', $this->path));
        }

        $entries = scandir($this->path);
        if ($entries === false) {
            throw new MigrationException(sprintf('Migrationsordner nicht lesbar: %s', $this->path));
        }

        $files = [];
        foreach ($entries as $entry) {
            if (str_starts_with($entry, '.')) {
                continue;
            }

            if (preg_match(self::FILENAME_PATTERN, $entry, $match) !== 1) {
                throw new MigrationException(sprintf(
                    'Ungültiger Dateiname im Migrationsordner: %s (erwartet NNNN_beschreibung.sql,'
                    . ' nur Kleinbuchstaben, Ziffern und _).',
                    $entry,
                ));
            }

            $path = $this->path . DIRECTORY_SEPARATOR . $entry;
            if (!is_file($path)) {
                throw new MigrationException(sprintf('%s im Migrationsordner ist keine Datei.', $entry));
            }

            $version = (int) $match[1];
            if (isset($files[$version])) {
                throw new MigrationException(sprintf(
                    'Nummer %04d ist doppelt vergeben: %s und %s.',
                    $version,
                    $files[$version]->filename,
                    $entry,
                ));
            }

            $files[$version] = self::read($path, $entry, $version);
        }

        ksort($files);

        $expected = 1;
        foreach ($files as $version => $file) {
            if ($version !== $expected) {
                throw new MigrationException($expected === 1
                    ? sprintf('Die Nummerierung muss mit 0001 beginnen, erste Datei ist %s.', $file->filename)
                    : sprintf('Lücke in der Nummerierung: %04d fehlt (nächste Datei ist %s).', $expected, $file->filename));
            }
            $expected++;
        }

        return array_values($files);
    }

    /**
     * @throws MigrationException
     */
    private static function read(string $path, string $filename, int $version): MigrationFile
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new MigrationException(sprintf('Migration %s ist nicht lesbar.', $filename));
        }
        if (str_starts_with($content, "\u{FEFF}")) {
            throw new MigrationException(sprintf('Migration %s beginnt mit einem BOM; bitte als UTF-8 ohne BOM speichern.', $filename));
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            throw new MigrationException(sprintf('Migration %s ist nicht in UTF-8 gespeichert.', $filename));
        }

        $content = Checksum::normalize($content);

        try {
            $statements = SqlSplitter::split($content);
        } catch (MigrationException $e) {
            throw new MigrationException(sprintf('Migration %s: %s', $filename, $e->getMessage()));
        }
        if ($statements === []) {
            throw new MigrationException(sprintf('Migration %s enthält keine SQL-Anweisung.', $filename));
        }

        return new MigrationFile($version, $filename, Checksum::of($content), $statements);
    }
}
