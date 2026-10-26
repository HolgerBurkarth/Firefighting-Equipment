<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Http;

use FirefightingEquipment\Database\LazyConnection;
use FirefightingEquipment\Http\ErrorReporter;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\Request;
use FirefightingEquipment\Http\RequestContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RequestContext::class)]
#[UsesClass(HttpException::class)]
#[UsesClass(LazyConnection::class)]
#[UsesClass(ErrorReporter::class)]
#[UsesClass(Request::class)]
final class RequestContextTest extends TestCase
{
    public function testStringFields(): void
    {
        $context = self::context(['name' => 'Erika', 'leer' => null]);

        self::assertSame('Erika', $context->stringField('name'));
        self::assertSame('Erika', $context->optionalStringField('name'));
        self::assertNull($context->optionalStringField('leer'));
        self::assertNull($context->optionalStringField('fehlt'));
    }

    public function testOptionalStringFieldRejectsOtherTypes(): void
    {
        $this->expectExceptionObject(HttpException::invalidRequest('Das Feld "label" ist kein Text.'));

        self::context(['label' => 5])->optionalStringField('label');
    }

    public function testIntFieldAcceptsBounds(): void
    {
        $context = self::context(['a' => 1, 'b' => 90]);

        self::assertSame(1, $context->intField('a', 1, 90));
        self::assertSame(90, $context->intField('b', 1, 90));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidInts(): iterable
    {
        yield 'Text' => ['5'];
        yield 'Kommazahl' => [5.0];
        yield 'Wahrheitswert' => [true];
        yield 'null' => [null];
        yield 'zu klein' => [0];
        yield 'zu groß' => [91];
        yield 'negativ' => [-1];
    }

    #[DataProvider('invalidInts')]
    public function testIntFieldRejects(mixed $value): void
    {
        try {
            self::context(['tage' => $value])->intField('tage', 1, 90);
            self::fail('HttpException erwartet.');
        } catch (HttpException $e) {
            self::assertSame('invalid_request', $e->errorCode);
            self::assertSame('Das Feld "tage" muss eine ganze Zahl von 1 bis 90 sein.', $e->getMessage());
        }
    }

    public function testIntFieldMissing(): void
    {
        $this->expectException(HttpException::class);

        self::context([])->intField('tage', 1, 90);
    }

    public function testOptionalIntField(): void
    {
        $context = self::context(['a' => 7, 'leer' => null, 'text' => '7']);

        self::assertSame(7, $context->optionalIntField('a', 1, 10));
        self::assertNull($context->optionalIntField('leer', 1, 10));
        self::assertNull($context->optionalIntField('fehlt', 1, 10));

        $this->expectExceptionObject(HttpException::invalidRequest('Das Feld "text" muss eine ganze Zahl von 1 bis 10 sein.'));
        $context->optionalIntField('text', 1, 10);
    }

    public function testOptionalIntFieldChecksRange(): void
    {
        $this->expectExceptionObject(HttpException::invalidRequest('Das Feld "a" muss eine ganze Zahl von 1 bis 10 sein.'));

        self::context(['a' => 11])->optionalIntField('a', 1, 10);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function flags(): iterable
    {
        yield 'fehlt' => ['', false];
        yield 'anderer Parameter' => ['?x=1', false];
        yield '0' => ['?include_inactive=0', false];
        yield '1' => ['?include_inactive=1', true];
    }

    #[DataProvider('flags')]
    public function testQueryFlag(string $query, bool $expected): void
    {
        self::assertSame($expected, self::context([], 'GET', $query)->queryFlag('include_inactive'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidFlags(): iterable
    {
        yield 'leer' => ['?include_inactive='];
        yield 'true' => ['?include_inactive=true'];
        yield 'ja' => ['?include_inactive=ja'];
        yield '01' => ['?include_inactive=01'];
    }

    #[DataProvider('invalidFlags')]
    public function testQueryFlagRejectsOtherValues(string $query): void
    {
        $this->expectExceptionObject(HttpException::invalidRequest('Der Parameter "include_inactive" muss 0 oder 1 sein.'));

        self::context([], 'GET', $query)->queryFlag('include_inactive');
    }

    public function testHasFieldDistinguishesNullFromMissing(): void
    {
        $context = self::context(['note' => null, 'model' => 'X']);

        self::assertTrue($context->hasField('note'));
        self::assertTrue($context->hasField('model'));
        self::assertFalse($context->hasField('fehlt'));
    }

    public function testQueryInt(): void
    {
        $context = self::context([], 'GET', '?limit=200&offset=0&x=7');

        self::assertSame(200, $context->queryInt('limit', 1, 200));
        self::assertSame(0, $context->queryInt('offset', 0, 10));
        self::assertNull($context->queryInt('fehlt', 1, 10));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidQueryInts(): iterable
    {
        yield 'leer' => ['?limit='];
        yield 'führende Null' => ['?limit=05'];
        yield 'negativ' => ['?limit=-1'];
        yield 'Plus' => ['?limit=%2B5'];
        yield 'Kommazahl' => ['?limit=1.5'];
        yield 'Text' => ['?limit=zehn'];
        yield 'zu klein' => ['?limit=0'];
        yield 'zu groß' => ['?limit=201'];
        yield 'riesig' => ['?limit=99999999999999999999'];
        yield 'Leerzeichen' => ['?limit=%205'];
    }

    #[DataProvider('invalidQueryInts')]
    public function testQueryIntRejects(string $query): void
    {
        $this->expectExceptionObject(HttpException::invalidRequest('Der Parameter "limit" muss eine ganze Zahl von 1 bis 200 sein.'));

        self::context([], 'GET', $query)->queryInt('limit', 1, 200);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function context(array $body, string $method = 'POST', string $query = ''): RequestContext
    {
        return new RequestContext(
            new Request($method, '/firefighting/immendingen/api/v1/x' . $query),
            null,
            new LazyConnection(static fn (): \PDO => throw new \LogicException('Keine Datenbank im Unit-Test.')),
            new ErrorReporter(static function (): void {
            }),
            body: $body,
        );
    }
}
