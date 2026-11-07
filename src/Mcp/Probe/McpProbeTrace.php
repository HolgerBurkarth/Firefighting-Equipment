<?php

declare(strict_types=1);

namespace FirefightingEquipment\Mcp\Probe;

/**
 * Protokoll der Vorklärung zum Claude-Zugang (docs/PLAN-MCP.md, Runde 3): welche Adressen Claude
 * beim Verbinden abruft. Nur feste Schritte und kurze, bereinigte Angaben (kein Body, keine
 * IP-Adresse, keine Tokens); höchstens MAX_ENTRIES Einträge, älteste fallen weg.
 *
 * Gespeichert als JSON-Datei in var/mcp-probe/ (außerhalb von public/, in .gitignore). Fehler beim
 * Schreiben haben keine Folgen für die Antwort.
 */
final readonly class McpProbeTrace
{
    public const int MAX_ENTRIES = 50;
    public const int MAX_VALUE_LENGTH = 200;
    private const int MAX_FIELDS = 12;

    public function __construct(private string $directory)
    {
    }

    /** Ordner var/mcp-probe im Projekt (wie var/mail für FileMailer). */
    public static function inProject(): self
    {
        return new self(dirname(__DIR__, 3) . '/var/mcp-probe');
    }

    /**
     * @param array<string, string|bool|int|null|list<string>> $details
     *
     * @throws \RuntimeException wenn die Datei nicht geschrieben werden kann
     */
    public function record(string $slug, string $step, \DateTimeImmutable $at, array $details): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Ordner des Protokolls nicht anlegbar.');
        }
        $handle = @fopen($this->file($slug), 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Protokoll nicht zu öffnen.');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Protokoll nicht zu sperren.');
            }
            $entries = self::decode(stream_get_contents($handle));
            $entries[] = [
                'at' => $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'step' => self::clean($step),
                'details' => self::cleanDetails($details),
            ];
            $entries = array_slice($entries, -self::MAX_ENTRIES);
            $json = json_encode($entries, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $json);
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return list<array<string, mixed>> älteste zuerst
     */
    public function entries(string $slug): array
    {
        $file = $this->file($slug);
        if (!is_file($file)) {
            return [];
        }
        $content = @file_get_contents($file);

        return self::decode($content);
    }

    private function file(string $slug): string
    {
        // Das Kürzel ist schon geprüft (TenantSlug); trotzdem nur a-z, 0-9 und Bindestrich.
        return $this->directory . '/' . preg_replace('/[^a-z0-9-]/', '', $slug) . '.json';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function decode(string|false $content): array
    {
        if ($content === false || $content === '') {
            return [];
        }
        try {
            $data = json_decode($content, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
    }

    /**
     * @param array<string, string|bool|int|null|list<string>> $details
     *
     * @return array<string, string|bool|int|null|list<string>>
     */
    private static function cleanDetails(array $details): array
    {
        $clean = [];
        foreach (array_slice($details, 0, self::MAX_FIELDS, true) as $name => $value) {
            $clean[self::clean($name)] = match (true) {
                is_string($value) => self::clean($value),
                is_array($value) => array_map(self::clean(...), array_slice($value, 0, 5)),
                default => $value,
            };
        }

        return $clean;
    }

    /** Nur druckbares ASCII, gekürzt (Angaben stammen aus fremden Anfragen). */
    public static function clean(string $value): string
    {
        $value = (string) preg_replace('/[^\x20-\x7E]/', '?', $value);

        return strlen($value) > self::MAX_VALUE_LENGTH ? substr($value, 0, self::MAX_VALUE_LENGTH) . '…' : $value;
    }
}
