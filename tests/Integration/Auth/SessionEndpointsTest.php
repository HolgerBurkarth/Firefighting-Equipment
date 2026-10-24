<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Auth;

use FirefightingEquipment\Api\V1\Auth\LogoutOthersHandler;
use FirefightingEquipment\Api\V1\Auth\MeHandler;
use FirefightingEquipment\Api\V1\Auth\SessionDeleteHandler;
use FirefightingEquipment\Api\V1\Auth\SessionListHandler;
use FirefightingEquipment\Http\Routes;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(MeHandler::class)]
#[CoversClass(SessionListHandler::class)]
#[CoversClass(SessionDeleteHandler::class)]
#[CoversClass(LogoutOthersHandler::class)]
#[CoversClass(Routes::class)]
final class SessionEndpointsTest extends AuthTestCase
{
    public function testMeRequiresSession(): void
    {
        self::assertError(401, 'not_authenticated', $this->send('GET', self::url('immendingen', 'auth/me')));
        self::assertError(401, 'not_authenticated', $this->send('GET', self::url(null, 'auth/me')));
    }

    public function testMeReturnsAccountTenantAndSession(): void
    {
        $id = $this->account('immendingen', 'admin@example.org', ['role' => 'admin']);
        $cookie = $this->loginCookie('immendingen', 'admin@example.org');

        $data = self::json($this->send('GET', self::url('immendingen', 'auth/me'), cookie: $cookie));

        self::assertSame($id, $data['user']['id']);
        self::assertSame('admin', $data['user']['role']);
        self::assertSame('immendingen', $data['tenant']['slug']);
        self::assertSame($this->sessionIdOf($id), $data['session']['id']);
        self::assertTrue($data['session']['current']);
    }

    public function testMeForSuperAdminUnderServiceOnly(): void
    {
        $this->account(null, 'chef@example.org');
        $cookie = $this->loginCookie(null, 'chef@example.org');

        $data = self::json($this->send('GET', self::url(null, 'auth/me'), cookie: $cookie));
        self::assertSame('superadmin', $data['user']['role']);
        self::assertNull($data['tenant']);

        self::assertError(401, 'not_authenticated', $this->send('GET', self::url('immendingen', 'auth/me'), cookie: $cookie));
    }

    public function testCookieOfOneTenantIsUselessInAnother(): void
    {
        $this->account('immendingen', 'user@example.org');
        $this->account('tuttlingen', 'user@example.org');
        $cookie = $this->loginCookie('immendingen', 'user@example.org');

        foreach (['auth/me', 'auth/sessions'] as $rest) {
            self::assertError(401, 'not_authenticated', $this->send('GET', self::url('tuttlingen', $rest), cookie: $cookie));
            self::assertError(401, 'not_authenticated', $this->send('GET', self::url(null, $rest), cookie: $cookie));
        }
        self::assertError(401, 'not_authenticated', $this->send('POST', self::url('tuttlingen', 'auth/sessions/logout-others'), [], $cookie));
    }

    public function testMeAfterLogoutIs401(): void
    {
        $this->account('immendingen', 'user@example.org');
        $cookie = $this->loginCookie('immendingen', 'user@example.org');
        $this->send('POST', self::url('immendingen', 'auth/logout'), [], $cookie);

        self::assertError(401, 'not_authenticated', $this->send('GET', self::url('immendingen', 'auth/me'), cookie: $cookie));
    }

    public function testListShowsOwnValidSessionsWithCurrentFlag(): void
    {
        $id = $this->account('immendingen', 'user@example.org');
        $other = $this->account('immendingen', 'andere@example.org');
        $this->loginCookie('immendingen', 'user@example.org');
        $firefox = $this->send(
            'POST',
            self::url('immendingen', 'auth/login'),
            ['email' => 'user@example.org', 'password' => self::PASSWORD],
            headers: ['user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0'],
        );
        $current = (string) self::cookieValue($firefox);
        $this->loginCookie('immendingen', 'andere@example.org');
        $this->loginCookie('immendingen', 'user@example.org');
        $this->ageSession($this->sessionIdOf($id), 31 * 86400);

        $response = $this->send('GET', self::url('immendingen', 'auth/sessions'), cookie: $current);

        self::assertSame(200, $response->statusCode(), $response->body());
        $sessions = self::json($response)['sessions'];
        self::assertCount(2, $sessions, 'abgelaufene und fremde Sitzungen fehlen');
        self::assertSame(['Firefox auf Windows', 'Chrome auf Android'], array_column($sessions, 'device_label'));
        self::assertSame([true, false], array_column($sessions, 'current'));
        self::assertSame(['id', 'device_label', 'created_at', 'last_seen_at', 'expires_at', 'current'], array_keys($sessions[0]));
        self::assertSame(1, $this->sessionCountOf($other));
    }

    public function testDeleteOtherOwnSession(): void
    {
        $id = $this->account('immendingen', 'user@example.org');
        $phone = $this->loginCookie('immendingen', 'user@example.org');
        $phoneSession = $this->sessionIdOf($id);
        $laptop = $this->loginCookie('immendingen', 'user@example.org');

        $response = $this->send('DELETE', self::url('immendingen', 'auth/sessions/' . $phoneSession), [], $laptop);

        self::assertSame(200, $response->statusCode(), $response->body());
        self::assertSame('{"deleted":true,"current":false}', $response->body());
        self::assertArrayNotHasKey('Set-Cookie', $response->headers());
        self::assertError(401, 'not_authenticated', $this->send('GET', self::url('immendingen', 'auth/me'), cookie: $phone));
        self::assertSame(200, $this->send('GET', self::url('immendingen', 'auth/me'), cookie: $laptop)->statusCode());
    }

    public function testDeleteCurrentSessionLogsOut(): void
    {
        $id = $this->account('immendingen', 'user@example.org');
        $cookie = $this->loginCookie('immendingen', 'user@example.org');

        $response = $this->send('DELETE', self::url('immendingen', 'auth/sessions/' . $this->sessionIdOf($id)), [], $cookie);

        self::assertSame('{"deleted":true,"current":true}', $response->body());
        self::assertStringContainsString('Max-Age=0', $response->headers()['Set-Cookie']);
        self::assertError(401, 'not_authenticated', $this->send('GET', self::url('immendingen', 'auth/me'), cookie: $cookie));
    }

    public function testForeignOrUnknownSessionIs404(): void
    {
        $this->account('immendingen', 'user@example.org');
        $otherId = $this->account('immendingen', 'andere@example.org');
        $cookie = $this->loginCookie('immendingen', 'user@example.org');
        $this->loginCookie('immendingen', 'andere@example.org');
        $foreign = $this->sessionIdOf($otherId);

        self::assertError(404, 'not_found', $this->send('DELETE', self::url('immendingen', 'auth/sessions/' . $foreign), [], $cookie));
        self::assertError(404, 'not_found', $this->send('DELETE', self::url('immendingen', 'auth/sessions/999999'), [], $cookie));
        self::assertError(404, 'not_found', $this->send('DELETE', self::url('immendingen', 'auth/sessions/abc'), [], $cookie));
        self::assertSame(1, $this->sessionCountOf($otherId));
    }

    public function testDeleteRequiresSessionAndJson(): void
    {
        $id = $this->account('immendingen', 'user@example.org');
        $cookie = $this->loginCookie('immendingen', 'user@example.org');
        $uri = self::url('immendingen', 'auth/sessions/' . $this->sessionIdOf($id));

        self::assertError(401, 'not_authenticated', $this->send('DELETE', $uri, []));
        self::assertError(415, 'unsupported_media_type', $this->send('DELETE', $uri, cookie: $cookie));
        self::assertError(405, 'method_not_allowed', $this->send('GET', $uri, cookie: $cookie));
        self::assertSame(1, $this->sessionCountOf($id));
    }

    public function testLogoutOthersKeepsCurrentSession(): void
    {
        $id = $this->account('immendingen', 'user@example.org');
        $other = $this->account('immendingen', 'andere@example.org');
        $a = $this->loginCookie('immendingen', 'user@example.org');
        $b = $this->loginCookie('immendingen', 'user@example.org');
        $current = $this->loginCookie('immendingen', 'user@example.org');
        $this->loginCookie('immendingen', 'andere@example.org');

        $response = $this->send('POST', self::url('immendingen', 'auth/sessions/logout-others'), [], $current);

        self::assertSame('{"ended_sessions":2}', $response->body());
        self::assertSame(1, $this->sessionCountOf($id));
        self::assertSame(1, $this->sessionCountOf($other));
        self::assertSame(200, $this->send('GET', self::url('immendingen', 'auth/me'), cookie: $current)->statusCode());
        foreach ([$a, $b] as $cookie) {
            self::assertError(401, 'not_authenticated', $this->send('GET', self::url('immendingen', 'auth/me'), cookie: $cookie));
        }
    }

    public function testSessionEndpointsUnderService(): void
    {
        $this->account(null, 'chef@example.org');
        $first = $this->loginCookie(null, 'chef@example.org');
        $second = $this->loginCookie(null, 'chef@example.org');

        self::assertCount(2, self::json($this->send('GET', self::url(null, 'auth/sessions'), cookie: $second))['sessions']);
        self::assertSame('{"ended_sessions":1}', $this->send('POST', self::url(null, 'auth/sessions/logout-others'), [], $second)->body());
        self::assertError(401, 'not_authenticated', $this->send('GET', self::url(null, 'auth/me'), cookie: $first));
    }
}
