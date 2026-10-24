<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Http;

use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Http\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Router::class)]
#[UsesClass(HttpException::class)]
#[UsesClass(JsonResponse::class)]
final class RouterTest extends TestCase
{
    public function testMatchingRouteReturnsHandler(): void
    {
        $get = self::handler();
        $post = self::handler();
        $router = new Router()->add('GET', 'api/v1/items', $get)->add('POST', 'api/v1/items', $post);

        self::assertSame($get, $router->match('GET', 'api/v1/items'));
        self::assertSame($post, $router->match('POST', 'api/v1/items'));
    }

    public function testUnknownPathIs404(): void
    {
        $router = new Router()->add('GET', 'api/v1/items', self::handler());

        $e = self::catch(fn () => $router->match('GET', 'api/v1/other'));

        self::assertSame(404, $e->statusCode);
        self::assertSame('not_found', $e->errorCode);
        self::assertSame([], $e->headers);
    }

    public function testPathIsMatchedExactly(): void
    {
        $router = new Router()->add('GET', 'api/v1/items', self::handler());

        self::assertSame(404, self::catch(fn () => $router->match('GET', 'api/v1/items/1'))->statusCode);
        self::assertSame(404, self::catch(fn () => $router->match('GET', 'api/v1'))->statusCode);
        self::assertSame(404, self::catch(fn () => $router->match('GET', 'API/v1/items'))->statusCode);
        self::assertSame(404, self::catch(fn () => $router->match('GET', ''))->statusCode);
    }

    public function testWrongMethodIs405WithSortedAllowHeader(): void
    {
        $router = new Router()
            ->add('POST', 'api/v1/items', self::handler())
            ->add('GET', 'api/v1/items', self::handler())
            ->add('DELETE', 'api/v1/other', self::handler());

        $e = self::catch(fn () => $router->match('PUT', 'api/v1/items'));

        self::assertSame(405, $e->statusCode);
        self::assertSame('method_not_allowed', $e->errorCode);
        self::assertSame(['Allow' => 'GET, POST'], $e->headers);
        self::assertSame('GET, POST', $e->toResponse()->headers()['Allow']);
    }

    public function testHeadIsNotTreatedAsGet(): void
    {
        $router = new Router()->add('GET', 'api/v1/items', self::handler());

        self::assertSame(405, self::catch(fn () => $router->match('HEAD', 'api/v1/items'))->statusCode);
    }

    public function testMethodIsCaseSensitive(): void
    {
        $router = new Router()->add('GET', 'api/v1/items', self::handler());

        self::assertSame(405, self::catch(fn () => $router->match('get', 'api/v1/items'))->statusCode);
    }

    public function testDuplicateRouteIsRejected(): void
    {
        $router = new Router()->add('GET', 'api/v1/items', self::handler());

        $this->expectException(\LogicException::class);
        $router->add('GET', 'api/v1/items', self::handler());
    }

    public function testLowercaseMethodIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        new Router()->add('get', 'api/v1/items', self::handler());
    }

    public function testLeadingSlashIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        new Router()->add('GET', '/api/v1/items', self::handler());
    }

    public function testTrailingSlashIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        new Router()->add('GET', 'api/v1/items/', self::handler());
    }

    private static function handler(): RequestHandler
    {
        return new class implements RequestHandler {
            public function handle(RequestContext $context): JsonResponse
            {
                return new JsonResponse([]);
            }
        };
    }

    /**
     * @param \Closure(): mixed $call
     */
    private static function catch(\Closure $call): HttpException
    {
        try {
            $call();
        } catch (HttpException $e) {
            return $e;
        }
        self::fail('HttpException erwartet.');
    }
}
