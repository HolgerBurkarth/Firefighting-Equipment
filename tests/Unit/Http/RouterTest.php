<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Http;

use FirefightingEquipment\Http\Access;
use FirefightingEquipment\Http\BodyFormat;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\Request;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Http\RouteMatch;
use FirefightingEquipment\Http\Router;
use FirefightingEquipment\Permission\Area;
use FirefightingEquipment\Permission\AreaLevel;
use FirefightingEquipment\Permission\Requirement;
use FirefightingEquipment\User\UserRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Router::class)]
#[CoversClass(RouteMatch::class)]
#[CoversClass(Access::class)]
#[UsesClass(HttpException::class)]
#[UsesClass(Requirement::class)]
#[UsesClass(JsonResponse::class)]
final class RouterTest extends TestCase
{
    public function testMatchingRouteReturnsHandlerAndAccess(): void
    {
        $get = self::handler();
        $post = self::handler();
        $router = Router::forTenant()
            ->add('GET', 'api/v1/items', Access::Public, $get)
            ->add('POST', 'api/v1/items', $edit = self::edit(), $post);

        $match = $router->match('GET', 'api/v1/items');
        self::assertSame($get, $match->handler);
        self::assertSame(Access::Public, $match->access);
        self::assertSame([], $match->params);

        $match = $router->match('POST', 'api/v1/items');
        self::assertSame($post, $match->handler);
        self::assertSame($edit, $match->access);
    }

    public function testBodyLimitIsDefaultOrPerRoute(): void
    {
        $router = Router::forTenant()
            ->add('POST', 'api/v1/items', self::edit(), self::handler())
            ->add('POST', 'api/v1/items/import', self::edit(), self::handler(), Request::MAX_UPLOAD_BYTES)
            ->add('POST', 'api/v1/items/{id}/upload', self::edit(), self::handler(), 50_000);

        self::assertSame(Request::MAX_BODY_BYTES, $router->match('POST', 'api/v1/items')->maxBodyBytes);
        self::assertSame(Request::MAX_UPLOAD_BYTES, $router->match('POST', 'api/v1/items/import')->maxBodyBytes);
        self::assertSame(50_000, $router->match('POST', 'api/v1/items/7/upload')->maxBodyBytes);
    }

    public function testBodyLimitOutsideRangeIsRejected(): void
    {
        foreach ([0, Request::MAX_UPLOAD_BYTES + 1] as $limit) {
            try {
                Router::forTenant()->add('POST', 'api/v1/items', self::edit(), self::handler(), $limit);
                self::fail('Grenze angenommen: ' . $limit);
            } catch (\LogicException $e) {
                self::assertStringContainsString('Body-Grenze', $e->getMessage());
            }
        }
    }

    public function testUnknownPathIs404(): void
    {
        $router = Router::forTenant()->add('GET', 'api/v1/items', Access::Public, self::handler());

        $e = self::catch(fn () => $router->match('GET', 'api/v1/other'));

        self::assertSame(404, $e->statusCode);
        self::assertSame('not_found', $e->errorCode);
        self::assertSame([], $e->headers);
    }

    public function testPathIsMatchedExactly(): void
    {
        $router = Router::forTenant()->add('GET', 'api/v1/items', Access::Public, self::handler());

        self::assertSame(404, self::catch(fn () => $router->match('GET', 'api/v1/items/1'))->statusCode);
        self::assertSame(404, self::catch(fn () => $router->match('GET', 'api/v1'))->statusCode);
        self::assertSame(404, self::catch(fn () => $router->match('GET', 'API/v1/items'))->statusCode);
        self::assertSame(404, self::catch(fn () => $router->match('GET', ''))->statusCode);
    }

    public function testWrongMethodIs405WithSortedAllowHeader(): void
    {
        $router = Router::forTenant()
            ->add('POST', 'api/v1/items', Access::Public, self::handler())
            ->add('GET', 'api/v1/items', Access::Public, self::handler())
            ->add('DELETE', 'api/v1/other', Access::Public, self::handler());

        $e = self::catch(fn () => $router->match('PUT', 'api/v1/items'));

        self::assertSame(405, $e->statusCode);
        self::assertSame('method_not_allowed', $e->errorCode);
        self::assertSame(['Allow' => 'GET, POST'], $e->headers);
        self::assertSame('GET, POST', $e->toResponse()->headers()['Allow']);
    }

    public function testHeadIsNotTreatedAsGet(): void
    {
        $router = Router::forTenant()->add('GET', 'api/v1/items', Access::Public, self::handler());

        self::assertSame(405, self::catch(fn () => $router->match('HEAD', 'api/v1/items'))->statusCode);
    }

    public function testMethodIsCaseSensitive(): void
    {
        $router = Router::forTenant()->add('GET', 'api/v1/items', Access::Public, self::handler());

        self::assertSame(405, self::catch(fn () => $router->match('get', 'api/v1/items'))->statusCode);
    }

    public function testPlaceholderMatchesPositiveIntegerAndReturnsParam(): void
    {
        $handler = self::handler();
        $router = Router::forTenant()->add('DELETE', 'api/v1/items/{id}', Access::Authenticated, $handler);

        $match = $router->match('DELETE', 'api/v1/items/42');

        self::assertSame($handler, $match->handler);
        self::assertSame(Access::Authenticated, $match->access);
        self::assertSame(['id' => 42], $match->params);
        self::assertSame(['id' => 999999999999999999], $router->match('DELETE', 'api/v1/items/999999999999999999')->params);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidIds(): iterable
    {
        yield 'null' => ['api/v1/items/0'];
        yield 'führende Null' => ['api/v1/items/007'];
        yield 'negativ' => ['api/v1/items/-1'];
        yield 'Buchstaben' => ['api/v1/items/abc'];
        yield 'gemischt' => ['api/v1/items/12a'];
        yield 'Plus' => ['api/v1/items/+1'];
        yield 'Leerzeichen' => ['api/v1/items/%201'];
        yield '19 Stellen' => ['api/v1/items/1000000000000000000'];
        yield 'zu viele Segmente' => ['api/v1/items/1/2'];
        yield 'ohne ID' => ['api/v1/items'];
    }

    #[DataProvider('invalidIds')]
    public function testPlaceholderRejectsEverythingElse(string $path): void
    {
        $router = Router::forTenant()->add('GET', 'api/v1/items/{id}', Access::Public, self::handler());

        self::assertSame(404, self::catch(fn () => $router->match('GET', $path))->statusCode);
    }

    public function testExactPathHasPrecedenceOverPlaceholder(): void
    {
        $exact = self::handler();
        $pattern = self::handler();
        $router = Router::forTenant()
            ->add('GET', 'api/v1/items/{id}', Access::Public, $pattern)
            ->add('POST', 'api/v1/items/7', Access::Public, $exact);

        self::assertSame($pattern, $router->match('GET', 'api/v1/items/8')->handler);
        self::assertSame($exact, $router->match('POST', 'api/v1/items/7')->handler);
        // Der exakte Pfad gewinnt auch bei der Methode: GET auf .../7 ist dort nicht registriert.
        self::assertSame(['Allow' => 'POST'], self::catch(fn () => $router->match('GET', 'api/v1/items/7'))->headers);
    }

    public function testPlaceholderWrongMethodIs405(): void
    {
        $router = Router::forTenant()
            ->add('DELETE', 'api/v1/items/{id}', Access::Public, self::handler())
            ->add('GET', 'api/v1/items/{id}', Access::Public, self::handler());

        $e = self::catch(fn () => $router->match('POST', 'api/v1/items/3'));

        self::assertSame(405, $e->statusCode);
        self::assertSame(['Allow' => 'DELETE, GET'], $e->headers);
    }

    public function testMultiplePlaceholders(): void
    {
        $router = Router::forTenant()->add('GET', 'a/{first}/b/{second}', Access::Public, self::handler());

        self::assertSame(['first' => 1, 'second' => 22], $router->match('GET', 'a/1/b/22')->params);
        self::assertSame(404, self::catch(fn () => $router->match('GET', 'a/1/c/22'))->statusCode);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidRoutes(): iterable
    {
        yield 'Methode klein' => ['get', 'api/v1/items'];
        yield 'Slash am Anfang' => ['GET', '/api/v1/items'];
        yield 'Slash am Ende' => ['GET', 'api/v1/items/'];
        yield 'leeres Segment' => ['GET', 'api//items'];
        yield 'Platzhalter mit Großbuchstaben' => ['GET', 'api/v1/items/{Id}'];
        yield 'Platzhalter nur halb' => ['GET', 'api/v1/items/{id'];
        yield 'Platzhalter im Segment' => ['GET', 'api/v1/item-{id}'];
        yield 'Platzhalter doppelt' => ['GET', 'api/{id}/items/{id}'];
    }

    #[DataProvider('invalidRoutes')]
    public function testInvalidRouteIsRejected(string $method, string $path): void
    {
        $this->expectException(\LogicException::class);
        Router::forTenant()->add($method, $path, Access::Public, self::handler());
    }

    public function testDuplicateRouteIsRejected(): void
    {
        $router = Router::forTenant()->add('GET', 'api/v1/items', Access::Public, self::handler());

        $this->expectException(\LogicException::class);
        $router->add('GET', 'api/v1/items', self::edit(), self::handler());
    }

    public function testDuplicatePlaceholderRouteIsRejected(): void
    {
        $router = Router::forTenant()->add('GET', 'api/v1/items/{id}', Access::Public, self::handler());

        $this->expectException(\LogicException::class);
        $router->add('GET', 'api/v1/items/{id}', Access::Public, self::handler());
    }

    public function testSamePathWithOtherPlaceholderNameIsRejected(): void
    {
        $router = Router::forTenant()->add('GET', 'api/v1/items/{id}', Access::Public, self::handler());

        $this->expectException(\LogicException::class);
        $router->add('POST', 'api/v1/items/{item}', Access::Public, self::handler());
    }

    public function testRequirementIsNotAllowedUnderService(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Zugriff users:write ist in diesem Bereich nicht möglich: api/v1/items');
        Router::forService()->add('GET', 'api/v1/items', self::edit(), self::handler());
    }

    public function testRoutesListsEveryRouteWithAccess(): void
    {
        $edit = self::edit();
        $router = Router::forTenant()
            ->add('GET', 'api/v1/items/{id}', Access::Authenticated, self::handler())
            ->add('GET', 'api/v1/items', Access::Public, self::handler())
            ->add('POST', 'api/v1/items', $edit, self::handler());

        self::assertSame([
            ['method' => 'GET', 'path' => 'api/v1/items', 'access' => Access::Public],
            ['method' => 'POST', 'path' => 'api/v1/items', 'access' => $edit],
            ['method' => 'GET', 'path' => 'api/v1/items/{id}', 'access' => Access::Authenticated],
        ], $router->routes());
    }

    public function testSuperAdminAccessIsNotAllowedUnderTenant(): void
    {
        $this->expectException(\LogicException::class);
        Router::forTenant()->add('GET', 'api/v1/items', Access::SuperAdmin, self::handler());
    }

    public function testServiceAllowsSuperAdminAccess(): void
    {
        $router = Router::forService()->add('GET', 'api/v1/tenants', Access::SuperAdmin, self::handler());

        self::assertSame(Access::SuperAdmin, $router->match('GET', 'api/v1/tenants')->access);
    }

    public function testClaudeAccessOnlyUnderTenant(): void
    {
        $router = Router::forTenant()->add('POST', 'mcp', Access::Claude, self::handler());
        self::assertSame(Access::Claude, $router->match('POST', 'mcp')->access);

        $this->expectException(\LogicException::class);
        Router::forService()->add('POST', 'mcp', Access::Claude, self::handler());
    }

    public function testBodyFormatIsJsonOrFormForPublicRoutes(): void
    {
        $router = Router::forTenant()
            ->add('POST', 'oauth/token', Access::Public, self::handler(), bodyFormat: BodyFormat::Form)
            ->add('POST', 'oauth/register', Access::Public, self::handler());

        self::assertSame(BodyFormat::Form, $router->match('POST', 'oauth/token')->bodyFormat);
        self::assertSame(BodyFormat::Json, $router->match('POST', 'oauth/register')->bodyFormat);

        // Ein Formular fremder Seiten trüge das Cookie mit: nur bei öffentlichen Routen.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Formular nur bei öffentlichen Routen: api/v1/items');
        Router::forTenant()->add('POST', 'api/v1/items', Access::Authenticated, self::handler(), bodyFormat: BodyFormat::Form);
    }

    public function testAccessAllowsRoles(): void
    {
        foreach (UserRole::cases() as $role) {
            self::assertTrue(Access::Public->allows($role));
            self::assertTrue(Access::Authenticated->allows($role));
        }
        self::assertTrue(Access::SuperAdmin->allows(UserRole::SuperAdmin));
        self::assertFalse(Access::SuperAdmin->allows(UserRole::Admin));
        self::assertFalse(Access::SuperAdmin->allows(UserRole::User));
        // Claude-Verbindungen prüft Application über ClaudeSessions, nie über die Rolle.
        foreach (UserRole::cases() as $role) {
            self::assertFalse(Access::Claude->allows($role));
        }
    }

    private static function edit(): Requirement
    {
        return Requirement::area(Area::Users, AreaLevel::Write);
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
