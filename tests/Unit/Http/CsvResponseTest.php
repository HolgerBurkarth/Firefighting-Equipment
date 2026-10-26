<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Http;

use FirefightingEquipment\Csv\CsvFormat;
use FirefightingEquipment\Http\CsvResponse;
use FirefightingEquipment\Http\ErrorReporter;
use FirefightingEquipment\Http\JsonResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CsvResponse::class)]
#[UsesClass(CsvFormat::class)]
#[UsesClass(ErrorReporter::class)]
final class CsvResponseTest extends TestCase
{
    /** @var list<string> */
    private array $log = [];

    public function testHeaders(): void
    {
        $response = $this->response([]);

        self::assertSame(200, $response->statusCode());
        self::assertSame([
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="geraete-immendingen-2026-06-15.csv"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], $response->headers());
        self::assertSame(JsonResponse::DEFAULT_HEADERS['Cache-Control'], $response->headers()['Cache-Control']);
    }

    public function testBomOnceThenHeaderAndRows(): void
    {
        $body = self::body($this->response([['MA-01', 'Süd'], ['MA-02', null]]));

        self::assertSame("\xEF\xBB\xBFKennung;Standort\r\nMA-01;Süd\r\nMA-02;\r\n", $body);
        self::assertSame(1, substr_count($body, CsvFormat::BOM));
    }

    public function testWithoutRowsOnlyHeader(): void
    {
        self::assertSame("\xEF\xBB\xBFKennung;Standort\r\n", self::body($this->response([])));
    }

    public function testRowsAreMappedFromItems(): void
    {
        $response = new CsvResponse('a.csv', ['Nr.'], [1, 2], static fn (int $n): array => [(string) ($n * 10)], new ErrorReporter());

        self::assertSame("\xEF\xBB\xBFNr.\r\n10\r\n20\r\n", self::body($response));
    }

    public function testGeneratorStartsInConstructor(): void
    {
        $started = false;
        $rows = (static function () use (&$started): \Generator {
            $started = true;
            yield ['MA-01', 'Lager'];
        })();

        $response = $this->response($rows);

        self::assertTrue($started);
        self::assertSame("\xEF\xBB\xBFKennung;Standort\r\nMA-01;Lager\r\n", self::body($response));
    }

    public function testErrorBeforeFirstRowReachesHandler(): void
    {
        $rows = (static function (): \Generator {
            throw new \RuntimeException('Abfrage fehlgeschlagen');
            yield [];
        })();

        $this->expectException(\RuntimeException::class);
        $this->response($rows);
    }

    public function testErrorWhileStreamingEndsWithMarkerAndIsReported(): void
    {
        $rows = (static function (): \Generator {
            yield ['MA-01', 'Lager'];
            yield ['MA-02', 'Lager'];
            throw new \RuntimeException('Verbindung verloren');
        })();

        $body = self::body($this->response($rows));

        self::assertSame("\xEF\xBB\xBFKennung;Standort\r\nMA-01;Lager\r\nMA-02;Lager\r\nExport abgebrochen – Datei unvollständig\r\n", $body);
        self::assertCount(1, $this->log);
        self::assertStringContainsString('csv_export_failed', $this->log[0]);
        self::assertStringContainsString('Verbindung verloren', $this->log[0]);
    }

    public function testFlushAfterEveryBlock(): void
    {
        $flushes = 0;
        $response = $this->response(array_fill(0, 2 * CsvResponse::FLUSH_ROWS + 1, ['MA', 'Lager']));
        $stream = fopen('php://memory', 'w+b') ?: self::fail('Kein Stream.');
        $response->writeTo($stream, static function () use (&$flushes): void {
            ++$flushes;
        });

        self::assertSame(2, $flushes);
        rewind($stream);
        self::assertSame(2 * CsvResponse::FLUSH_ROWS + 2, substr_count((string) stream_get_contents($stream), "\r\n"));
    }

    public function testRejectsUnsafeFilename(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CsvResponse('a"b.csv', [], [], static fn (): array => [], new ErrorReporter());
    }

    /**
     * @param iterable<mixed, list<string|null>> $rows
     */
    private function response(iterable $rows): CsvResponse
    {
        $errors = new ErrorReporter(function (string $line): void {
            $this->log[] = $line;
        });

        return new CsvResponse('geraete-immendingen-2026-06-15.csv', ['Kennung', 'Standort'], $rows, static fn (array $row): array => $row, $errors);
    }

    private static function body(CsvResponse $response): string
    {
        $stream = fopen('php://memory', 'w+b') ?: self::fail('Kein Stream.');
        $response->writeTo($stream);
        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
