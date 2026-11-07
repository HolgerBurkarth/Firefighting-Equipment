<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Http;

use FirefightingEquipment\Http\AllowedOrigins;
use FirefightingEquipment\Http\BodyFormat;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\Request;
use FirefightingEquipment\Http\RequestGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Formular statt JSON (BodyFormat::Form, nur oauth/token und oauth/revoke): Content-Type, Origin,
 * Größe und Aufbau.
 */
#[CoversClass(RequestGuard::class)]
#[UsesClass(HttpException::class)]
#[UsesClass(Request::class)]
#[UsesClass(AllowedOrigins::class)]
final class RequestGuardFormTest extends TestCase
{
    private const string FORM = 'application/x-www-form-urlencoded';

    public function testFormIsParsed(): void
    {
        $body = new RequestGuard()->check(
            self::post('grant_type=authorization_code&code=ffc_a%2Bb&redirect_uri=https%3A%2F%2Fclaude.ai%2Fx&name=Erika+M%C3%BCller&leer='),
            format: BodyFormat::Form,
        );

        self::assertSame([
            'grant_type' => 'authorization_code',
            'code' => 'ffc_a+b',
            'redirect_uri' => 'https://claude.ai/x',
            'name' => 'Erika Müller',
            'leer' => '',
        ], $body);
    }

    public function testEmptyFormAndCharsetAreAccepted(): void
    {
        self::assertSame([], new RequestGuard()->check(self::post('', self::FORM . '; charset=UTF-8'), format: BodyFormat::Form));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function wrongTypes(): iterable
    {
        yield 'JSON' => ['application/json'];
        yield 'multipart' => ['multipart/form-data; boundary=x'];
        yield 'text' => ['text/plain'];
        yield 'anderer Zeichensatz' => [self::FORM . '; charset=iso-8859-1'];
    }

    #[DataProvider('wrongTypes')]
    public function testOtherContentTypesAre415(string $type): void
    {
        $this->assertRejected(415, 'unsupported_media_type', self::post('a=b', $type));
    }

    public function testJsonRoutesStillRejectForms(): void
    {
        $this->assertRejected(415, 'unsupported_media_type', self::post('a=b', self::FORM), BodyFormat::Json);
    }

    public function testForeignOriginIs403(): void
    {
        $this->assertRejected(403, 'origin_not_allowed', self::post('a=b', self::FORM, ['origin' => 'https://evil.example']));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidForms(): iterable
    {
        yield 'doppelter Name' => ['code=a&code=b'];
        yield 'Liste' => ['code[]=a'];
        yield 'leerer Name' => ['=a'];
        yield 'kein UTF-8' => ['code=%FF'];
    }

    #[DataProvider('invalidForms')]
    public function testInvalidFormsAre400(string $body): void
    {
        $this->assertRejected(400, 'invalid_request', self::post($body));
    }

    public function testTooLargeIs400(): void
    {
        $this->assertRejected(400, 'invalid_request', self::post('a=' . str_repeat('x', Request::MAX_BODY_BYTES)));
    }

    private function assertRejected(int $status, string $code, Request $request, BodyFormat $format = BodyFormat::Form): void
    {
        try {
            new RequestGuard()->check($request, format: $format);
            self::fail('Erwartet: ' . $code);
        } catch (HttpException $e) {
            self::assertSame($status, $e->statusCode);
            self::assertSame($code, $e->errorCode);
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private static function post(string $body, string $type = self::FORM, array $headers = []): Request
    {
        return new Request('POST', '/firefighting/test/oauth/token', ['content-type' => $type, ...$headers], body: $body);
    }
}
