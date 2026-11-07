<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Http;

use FirefightingEquipment\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Request::class)]
final class RequestTest extends TestCase
{
    public function testHeadersCookiesAndAddressAreRead(): void
    {
        $request = Request::fromServer(
            [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/firefighting/service/api/v1/health',
                'HTTP_USER_AGENT' => 'curl/8.0',
                'HTTP_X_CUSTOM_VALUE' => 'a',
                'CONTENT_TYPE' => 'application/json',
                'REMOTE_ADDR' => '192.0.2.10',
                'HTTP_NOT_STRING' => ['x'],
            ],
            ['ff_session' => 'abc', 'kaputt' => ['x']],
            static fn () => self::fail('GET liest keinen Body.'),
        );

        self::assertSame('GET', $request->method);
        self::assertSame('/firefighting/service/api/v1/health', $request->uri);
        self::assertSame('curl/8.0', $request->header('User-Agent'));
        self::assertSame('a', $request->header('x-custom-value'));
        self::assertSame('application/json', $request->header('content-type'));
        self::assertNull($request->header('not-string'));
        self::assertSame(['ff_session' => 'abc'], $request->cookies);
        self::assertSame('192.0.2.10', $request->remoteAddress);
        self::assertNull($request->body);
        self::assertFalse($request->isMutating());
    }

    public function testDefaultsWithoutServerValues(): void
    {
        $request = Request::fromServer([], [], static fn () => false);

        self::assertSame('GET', $request->method);
        self::assertSame('/', $request->uri);
        self::assertSame('', $request->remoteAddress);
        self::assertSame([], $request->headers);
    }

    public function testQueryReturnsOnlySimpleValues(): void
    {
        $request = new Request('GET', '/firefighting/immendingen/api/v1/users?status=pending&a[]=1&b[x]=2&leer=&status2=x%20y');

        self::assertSame(['status' => 'pending', 'leer' => '', 'status2' => 'x y'], $request->query());
    }

    public function testQueryWithoutQueryString(): void
    {
        self::assertSame([], new Request('GET', '/firefighting/immendingen/api/v1/users')->query());
        self::assertSame([], new Request('GET', '/firefighting/immendingen/api/v1/users?')->query());
    }

    public function testBodyOfMutatingRequestIsRead(): void
    {
        $request = self::post('{"a":1}', null);

        self::assertTrue($request->isMutating());
        self::assertSame('{"a":1}', $request->body);
        self::assertFalse($request->bodyTooLarge);
    }

    public function testBodyAtLimitIsRead(): void
    {
        $body = str_repeat('a', Request::MAX_UPLOAD_BYTES);

        self::assertSame($body, self::post($body, (string) strlen($body))->body);
    }

    public function testBodyOverLimitIsNotKept(): void
    {
        $request = self::post(str_repeat('a', Request::MAX_UPLOAD_BYTES + 1), null);

        self::assertNull($request->body);
        self::assertTrue($request->bodyTooLarge);
    }

    public function testLargeContentLengthIsRejectedWithoutReading(): void
    {
        foreach ([(string) (Request::MAX_UPLOAD_BYTES + 1), '99999999999999', 'abc', '-1'] as $length) {
            $request = Request::fromServer(
                ['REQUEST_METHOD' => 'POST', 'CONTENT_LENGTH' => $length],
                [],
                static fn () => self::fail('Body darf nicht gelesen werden.'),
            );

            self::assertTrue($request->bodyTooLarge, $length);
            self::assertNull($request->body);
        }
    }

    public function testDeleteReadsBody(): void
    {
        $request = Request::fromServer(['REQUEST_METHOD' => 'DELETE'], [], self::stream('{}'));

        self::assertSame('{}', $request->body);
    }

    private static function post(string $body, ?string $contentLength): Request
    {
        $server = ['REQUEST_METHOD' => 'POST'];
        if ($contentLength !== null) {
            $server['CONTENT_LENGTH'] = $contentLength;
        }

        return Request::fromServer($server, [], self::stream($body));
    }

    /**
     * @return \Closure(): resource
     */
    private static function stream(string $content): \Closure
    {
        return static function () use ($content) {
            $stream = fopen('php://memory', 'w+b') ?: throw new \RuntimeException('php://memory');
            fwrite($stream, $content);
            rewind($stream);

            return $stream;
        };
    }
}
