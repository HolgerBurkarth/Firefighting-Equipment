<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Auth;

use FirefightingEquipment\Auth\AuthSession;
use FirefightingEquipment\Auth\SessionCookie;
use FirefightingEquipment\Auth\SessionManager;
use FirefightingEquipment\Auth\SessionToken;
use FirefightingEquipment\Http\Access;
use FirefightingEquipment\Http\Application;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Http\Router;
use FirefightingEquipment\Permission\Action;
use FirefightingEquipment\Permission\Area;
use FirefightingEquipment\Permission\AreaLevel;
use FirefightingEquipment\Permission\PermissionRepository;
use FirefightingEquipment\Permission\Permissions;
use FirefightingEquipment\Permission\Requirement;
use FirefightingEquipment\User\UserRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Zentrale Prüfung der Zugriffsstufen und Rechte in Application (mit eigenen Test-Routen).
 */
#[CoversClass(Application::class)]
#[CoversClass(SessionCookie::class)]
#[UsesClass(Access::class)]
#[UsesClass(PermissionRepository::class)]
#[UsesClass(Permissions::class)]
#[UsesClass(Requirement::class)]
#[UsesClass(AuthSession::class)]
#[UsesClass(SessionManager::class)]
#[UsesClass(SessionToken::class)]
final class AccessControlTest extends AuthTestCase
{
    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();

        $whoami = new class implements RequestHandler {
            public function handle(RequestContext $context): JsonResponse
            {
                return new JsonResponse(['user' => $context->auth?->user->id]);
            }
        };
        $book = new class implements RequestHandler {
            public function handle(RequestContext $context): JsonResponse
            {
                return new JsonResponse(['all' => $context->permissions()->isAll(), 'status' => $context->permissions()->can(Action::BookStatus)]);
            }
        };
        $service = Router::forService()
            ->add('GET', 'public', Access::Public, $whoami)
            ->add('GET', 'me', Access::Authenticated, $whoami)
            ->add('GET', 'super', Access::SuperAdmin, $whoami);
        $tenant = Router::forTenant()
            ->add('GET', 'public', Access::Public, $whoami)
            ->add('GET', 'me', Access::Authenticated, $whoami)
            ->add('GET', 'admin', Requirement::area(Area::Users, AreaLevel::Write), $whoami)
            ->add('POST', 'book', Requirement::action(Action::BookMove)->withFieldRule('status', Action::BookStatus), $book);

        $this->app = $this->app($service, $tenant);
    }

    public function testWithoutCookieIs401WithoutSetCookie(): void
    {
        foreach (['/firefighting/immendingen/me', '/firefighting/immendingen/admin', '/firefighting/service/super'] as $uri) {
            $response = $this->get($uri, null);

            self::assertError(401, 'not_authenticated', $response);
            self::assertArrayNotHasKey('Set-Cookie', $response->headers());
        }
    }

    public function testInvalidCookieIs401AndCookieIsCleared(): void
    {
        foreach (['kaputt', SessionToken::generate()->cookieValue()] as $cookie) {
            $response = $this->get('/firefighting/immendingen/me', $cookie);

            self::assertError(401, 'not_authenticated', $response);
            self::assertSame(
                'ff_session=; Path=/firefighting/immendingen/; Max-Age=0; HttpOnly; Secure; SameSite=Strict',
                $response->headers()['Set-Cookie'],
            );
        }
    }

    public function testPublicRouteIgnoresSession(): void
    {
        $cookie = $this->cookie($this->account('immendingen', 'user@example.org'));

        self::assertSame('{"user":null}', $this->get('/firefighting/immendingen/public', $cookie)->body());
        self::assertSame('{"user":null}', $this->get('/firefighting/immendingen/public', 'kaputt')->body());
        self::assertSame('{"user":null}', $this->get('/firefighting/service/public', null)->body());
    }

    public function testUserMayUseAuthenticatedButNotAdminRoute(): void
    {
        $id = $this->account('immendingen', 'user@example.org');
        $cookie = $this->cookie($id);

        self::assertSame(sprintf('{"user":%d}', $id), $this->get('/firefighting/immendingen/me', $cookie)->body());
        self::assertError(403, 'forbidden', $this->get('/firefighting/immendingen/admin', $cookie));
    }

    public function testAdminMayUseAdminRoute(): void
    {
        $id = $this->account('immendingen', 'admin@example.org', ['role' => 'admin']);

        self::assertSame(sprintf('{"user":%d}', $id), $this->get('/firefighting/immendingen/admin', $this->cookie($id))->body());
    }

    public function testRequirementUsesRoleOfAccountAndFieldRules(): void
    {
        $tenantId = $this->tenant('immendingen')->id;
        $roleId = $this->insert('fe_roles', ['tenant_id' => $tenantId, 'name' => 'Umbucher']);
        $this->insert('fe_role_areas', ['role_id' => $roleId, 'tenant_id' => $tenantId, 'area' => 'search', 'level' => 'read']);
        $this->insert('fe_role_actions', ['role_id' => $roleId, 'tenant_id' => $tenantId, 'action' => 'book.move']);
        $mover = $this->cookie($this->account('immendingen', 'umbucher@example.org', ['role_id' => $roleId]));
        $user = $this->cookie($this->account('immendingen', 'user@example.org'));
        $admin = $this->cookie($this->account('immendingen', 'admin@example.org', ['role' => 'admin']));

        self::assertSame('{"all":false,"status":false}', $this->post('/firefighting/immendingen/book', ['location_id' => 1], $mover)->body());
        self::assertError(403, 'forbidden', $this->post('/firefighting/immendingen/book', ['status' => 'ready'], $mover));
        self::assertError(403, 'forbidden', $this->post('/firefighting/immendingen/book', ['status' => null], $mover));
        self::assertSame('{"all":false,"status":true}', $this->post('/firefighting/immendingen/book', ['status' => 'ready'], $user)->body());
        self::assertSame('{"all":true,"status":true}', $this->post('/firefighting/immendingen/book', ['status' => 'ready'], $admin)->body());
        self::assertError(403, 'forbidden', $this->get('/firefighting/immendingen/admin', $mover));

        // Ohne Bereich wirkt die Aktion nicht.
        $this->pdo()->exec('DELETE FROM fe_role_areas WHERE role_id = ' . $roleId);
        self::assertError(403, 'forbidden', $this->post('/firefighting/immendingen/book', [], $mover));
    }

    public function testSuperAdminOnlyUnderService(): void
    {
        $id = $this->account(null, 'chef@example.org');
        $cookie = $this->cookie($id);

        self::assertSame(sprintf('{"user":%d}', $id), $this->get('/firefighting/service/super', $cookie)->body());
        self::assertSame(sprintf('{"user":%d}', $id), $this->get('/firefighting/service/me', $cookie)->body());
        self::assertError(401, 'not_authenticated', $this->get('/firefighting/immendingen/me', $cookie));
        self::assertError(401, 'not_authenticated', $this->get('/firefighting/immendingen/admin', $cookie));
    }

    public function testTenantAccountNotInOtherTenantOrService(): void
    {
        $cookie = $this->cookie($this->account('immendingen', 'admin@example.org', ['role' => 'admin']));

        self::assertError(401, 'not_authenticated', $this->get('/firefighting/tuttlingen/me', $cookie));
        self::assertError(401, 'not_authenticated', $this->get('/firefighting/tuttlingen/admin', $cookie));
        self::assertError(401, 'not_authenticated', $this->get('/firefighting/service/me', $cookie));
        self::assertError(401, 'not_authenticated', $this->get('/firefighting/service/super', $cookie));
    }

    public function testInactiveTenantIs403BeforeSessionCheck(): void
    {
        $cookie = $this->cookie($this->account('tuttlingen', 'user@example.org'));
        $this->pdo()->exec("UPDATE fe_tenants SET active = 0 WHERE slug = 'tuttlingen'");

        self::assertError(403, 'tenant_inactive', $this->get('/firefighting/tuttlingen/me', $cookie));
    }

    private function get(string $uri, ?string $cookie): JsonResponse
    {
        return $this->send('GET', $uri, cookie: $cookie, app: $this->app);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $uri, array $body, ?string $cookie): JsonResponse
    {
        return $this->send('POST', $uri, $body, $cookie, app: $this->app);
    }

    private function cookie(int $userId): string
    {
        $user = new UserRepository($this->pdo())->findById($userId) ?? throw new \LogicException('Konto fehlt.');

        return new SessionManager($this->pdo())->start($user, 'curl')->token->cookieValue();
    }
}
