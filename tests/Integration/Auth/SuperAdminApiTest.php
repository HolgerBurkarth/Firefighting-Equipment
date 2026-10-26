<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Auth;

use FirefightingEquipment\Api\V1\SuperAdmins\SuperAdminJson;
use FirefightingEquipment\Api\V1\SuperAdmins\SuperAdminListHandler;
use FirefightingEquipment\Api\V1\SuperAdmins\SuperAdminLockHandler;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Super-Admins unter service: auflisten, andere sperren und entsperren (deaktivieren statt löschen).
 * Rechte in SuperAdminRoutesAccessTest.
 */
#[CoversClass(SuperAdminListHandler::class)]
#[CoversClass(SuperAdminLockHandler::class)]
#[CoversClass(SuperAdminJson::class)]
final class SuperAdminApiTest extends AuthTestCase
{
    public function testListShowsLockedAndProtected(): void
    {
        $chef = $this->account(null, 'chef@example.org', ['protected' => 1]);
        $other = $this->account(null, 'zweiter@example.org', ['locked_at' => self::CREATED_AT]);
        $this->account('immendingen', 'admin@example.org', ['role' => 'admin']);

        $data = self::json($this->send('GET', self::url(null, 'superadmins'), cookie: $this->loginCookie(null, 'chef@example.org')));

        self::assertSame([$chef, $other], array_column($data['super_admins'], 'id'));
        self::assertTrue($data['super_admins'][0]['protected']);
        self::assertFalse($data['super_admins'][0]['locked']);
        self::assertFalse($data['super_admins'][1]['protected']);
        self::assertTrue($data['super_admins'][1]['locked']);
        self::assertSame('zweiter@example.org', $data['super_admins'][1]['email']);
        self::assertArrayNotHasKey('password_hash', $data['super_admins'][0]);
    }

    public function testLockAndUnlockOtherSuperAdmin(): void
    {
        $this->account(null, 'chef@example.org');
        $other = $this->account(null, 'zweiter@example.org');
        $otherCookie = $this->loginCookie(null, 'zweiter@example.org');
        $cookie = $this->loginCookie(null, 'chef@example.org');

        $response = $this->send('POST', self::url(null, 'superadmins/' . $other . '/lock'), [], $cookie);
        self::assertSame(200, $response->statusCode(), $response->body());
        self::assertTrue(self::json($response)['super_admin']['locked']);
        // Sperren beendet die Sitzungen des Kontos.
        self::assertError(401, 'not_authenticated', $this->send('GET', self::url(null, 'auth/me'), cookie: $otherCookie));

        // Wiederholbar.
        self::assertSame(200, $this->send('POST', self::url(null, 'superadmins/' . $other . '/lock'), [], $cookie)->statusCode());

        $response = $this->send('POST', self::url(null, 'superadmins/' . $other . '/unlock'), [], $cookie);
        self::assertSame(200, $response->statusCode(), $response->body());
        self::assertFalse(self::json($response)['super_admin']['locked']);
        self::assertSame(200, $this->send('POST', self::url(null, 'superadmins/' . $other . '/unlock'), [], $cookie)->statusCode());
    }

    public function testSelfLockProtectedAndLastAreRefused(): void
    {
        $chef = $this->account(null, 'chef@example.org');
        $protected = $this->account(null, 'geschuetzt@example.org', ['protected' => 1]);
        $other = $this->account(null, 'zweiter@example.org');
        $cookie = $this->loginCookie(null, 'chef@example.org');

        $response = $this->send('POST', self::url(null, 'superadmins/' . $chef . '/lock'), [], $cookie);
        self::assertError(409, 'rule_violation', $response);
        self::assertSame('Sie können Ihr eigenes Konto nicht sperren.', self::json($response)['message']);

        $response = $this->send('POST', self::url(null, 'superadmins/' . $protected . '/lock'), [], $cookie);
        self::assertError(409, 'rule_violation', $response);
        self::assertStringContainsString('ist geschützt', self::json($response)['message']);

        // Der Aufrufer ist selbst aktiv und kann sich nicht sperren; daher bleibt über die API immer
        // mindestens ein aktiver Super-Admin (die Regel selbst prüfen die Repository-Tests).
        $this->pdo()->exec('UPDATE fe_users SET protected = 0 WHERE id = ' . $protected);
        self::assertSame(200, $this->send('POST', self::url(null, 'superadmins/' . $protected . '/lock'), [], $cookie)->statusCode());
        self::assertSame(200, $this->send('POST', self::url(null, 'superadmins/' . $other . '/lock'), [], $cookie)->statusCode());
        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM fe_users WHERE tenant_key = 0 AND locked_at IS NULL')->fetchColumn());
        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM fe_users WHERE id = ' . $chef . ' AND locked_at IS NULL')->fetchColumn());
    }

    public function testUnknownAndTenantAccountsAreNotFound(): void
    {
        $this->account(null, 'chef@example.org');
        $admin = $this->account('immendingen', 'admin@example.org', ['role' => 'admin']);
        $cookie = $this->loginCookie(null, 'chef@example.org');

        foreach (['lock', 'unlock'] as $action) {
            self::assertError(404, 'not_found', $this->send('POST', self::url(null, 'superadmins/' . $admin . '/' . $action), [], $cookie));
            self::assertError(404, 'not_found', $this->send('POST', self::url(null, 'superadmins/999999/' . $action), [], $cookie));
        }
        self::assertNull($this->pdo()->query('SELECT locked_at FROM fe_users WHERE id = ' . $admin)->fetchColumn());
    }
}
