<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Import;

use FirefightingEquipment\Csv\CsvFormat;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Tests\Integration\Export\ExportApiTestCase;

/**
 * Basis für Tests des CSV-Imports: wie ExportApiTestCase (Stadt immendingen mit „Atemschutzmaske“,
 * „HLF 20“ / „G1“, „Lager“; Admin und Benutzer angemeldet; feste Uhr für Exporte), dazu Dateien im
 * Format des Exports und Aufrufe von Vorschau und Übernehmen als Admin.
 */
abstract class ImportApiTestCase extends ExportApiTestCase
{
    protected const string HEADER = 'Kennung;Gerätetyp;Standort;Status;Nächste Prüfung;Seriennummer;Hersteller;Modell;Beschaffungsdatum;Notiz';

    /**
     * Datei wie der Export (BOM, Semikolon, CRLF, Formelschutz).
     *
     * @param list<list<string|null>> $rows
     */
    protected static function csv(array $rows, string $header = self::HEADER): string
    {
        return CsvFormat::BOM . $header . "\r\n" . implode('', array_map(CsvFormat::line(...), $rows));
    }

    protected function preview(string $bytes, ?string $cookie = null): JsonResponse
    {
        return $this->api('POST', 'devices/import/preview', ['file' => base64_encode($bytes)], $cookie);
    }

    /**
     * Vorschau, die 200 liefern muss.
     *
     * @return array<string, mixed>
     */
    protected function previewOk(string $bytes): array
    {
        $response = $this->preview($bytes);
        self::assertSame(200, $response->statusCode(), $response->body());

        return self::json($response);
    }

    protected function apply(string $bytes, string $token, ?string $comment = null, ?string $cookie = null): JsonResponse
    {
        $body = ['file' => base64_encode($bytes), 'token' => $token];
        if ($comment !== null) {
            $body['comment'] = $comment;
        }

        return $this->api('POST', 'devices/import', $body, $cookie);
    }

    /**
     * Vorschau (fehlerfrei) und Übernehmen; liefert die Antwort des Übernehmens.
     *
     * @return array<string, mixed>
     */
    protected function import(string $bytes, ?string $comment = null): array
    {
        $preview = $this->previewOk($bytes);
        self::assertIsString($preview['token'], json_encode($preview['rows'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $response = $this->apply($bytes, $preview['token'], $comment);
        self::assertSame(200, $response->statusCode(), $response->body());

        return self::json($response);
    }

    /**
     * Zeile der Vorschau mit der Zeilennummer $line.
     *
     * @param array<string, mixed> $preview
     *
     * @return array<string, mixed>
     */
    protected static function row(array $preview, int $line): array
    {
        foreach ($preview['rows'] as $row) {
            if ($row['line'] === $line) {
                return $row;
            }
        }
        self::fail(sprintf('Zeile %d fehlt in der Vorschau.', $line));
    }

    /**
     * Änderungen einer Zeile als [Feld, alt, neu].
     *
     * @param array<string, mixed> $row
     *
     * @return list<array{string, ?string, ?string}>
     */
    protected static function changes(array $row): array
    {
        return array_map(static fn (array $c): array => [$c['field'], $c['old'], $c['new']], $row['changes']);
    }

    /**
     * @return array<string, mixed> Zeile aus fe_devices
     */
    protected function deviceRow(int $id): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM fe_devices WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: self::fail('Gerät fehlt.');
    }

    protected function deviceIdOf(string $identifier, int $tenantId = 0): int
    {
        $stmt = $this->pdo()->prepare("SELECT id FROM fe_devices WHERE tenant_id = ? AND identifier COLLATE utf8mb4_bin = ? AND status <> 'retired'");
        $stmt->execute([$tenantId === 0 ? $this->tenantId : $tenantId, $identifier]);

        return (int) ($stmt->fetchColumn() ?: self::fail('Gerät fehlt: ' . $identifier));
    }
}
