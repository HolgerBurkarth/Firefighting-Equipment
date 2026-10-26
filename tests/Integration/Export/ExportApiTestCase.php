<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Export;

use FirefightingEquipment\Csv\CsvFormat;
use FirefightingEquipment\Http\Application;
use FirefightingEquipment\Http\MinimumDuration;
use FirefightingEquipment\Http\Response;
use FirefightingEquipment\Http\Router;
use FirefightingEquipment\Http\Routes;
use FirefightingEquipment\Tests\Integration\Device\DeviceApiTestCase;
use FirefightingEquipment\Tests\Support\FixedClock;
use FirefightingEquipment\User\Password\PasswordHasher;

/**
 * Basis für Tests der CSV-Exporte: wie DeviceApiTestCase, die Anwendung verwendet aber die Uhr
 * $clock (2026-06-15 12:00 MESZ, „heute“ = TODAY). Anzeigenamen: Admin „Kommandant“, Benutzer
 * „Gerätewart“.
 */
abstract class ExportApiTestCase extends DeviceApiTestCase
{
    protected const string TODAY = '2026-06-15';

    protected FixedClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FixedClock('2026-06-15T10:00:00Z');

        parent::setUp();
        $this->pdo()->prepare('UPDATE fe_users SET display_name = ? WHERE id = ?')->execute(['Kommandant', $this->adminId]);
        $this->pdo()->prepare('UPDATE fe_users SET display_name = ? WHERE id = ?')->execute(['Gerätewart', $this->userId]);
    }

    protected function app(?Router $service = null, ?Router $tenant = null, ?PasswordHasher $hasher = null): Application
    {
        return parent::app($service, $tenant ?? Routes::tenant(new MinimumDuration(0), $this->clock), $hasher);
    }

    /**
     * GET-Anfrage (Standard: als Benutzer); die Antwort wird vollständig gelesen.
     *
     * @return array{Response, string}
     */
    protected function download(string $path, ?string $cookie = null, ?Application $app = null): array
    {
        $response = $this->sendAny('GET', self::url('immendingen', $path), cookie: $cookie ?? $this->user, app: $app);

        return [$response, self::responseBody($response)];
    }

    /**
     * Download, der 200 liefern muss; Zeilen ohne Überschrift als Felder.
     *
     * @return list<list<string>>
     */
    protected function rows(string $path, ?string $cookie = null): array
    {
        [$response, $body] = $this->download($path, $cookie);
        self::assertSame(200, $response->statusCode(), $body);

        return array_slice(self::parse($body), 1);
    }

    /**
     * Liest eine Datei im Format von CsvFormat (mit BOM) einschließlich der Überschrift.
     *
     * @return list<list<string>>
     */
    protected static function parse(string $body): array
    {
        self::assertStringStartsWith(CsvFormat::BOM, $body);
        self::assertStringEndsWith("\r\n", $body);
        $stream = fopen('php://memory', 'w+b') ?: self::fail('Kein Stream.');
        fwrite($stream, substr($body, strlen(CsvFormat::BOM)));
        rewind($stream);

        $rows = [];
        while (is_array($row = fgetcsv($stream, null, ';', '"', ''))) {
            $rows[] = array_map(static fn (?string $field): string => (string) $field, $row);
        }

        return $rows;
    }

    /**
     * Spalte $index aller Zeilen.
     *
     * @param list<list<string>> $rows
     *
     * @return list<string>
     */
    protected static function column(array $rows, int $index): array
    {
        return array_map(static fn (array $row): string => $row[$index], $rows);
    }
}
