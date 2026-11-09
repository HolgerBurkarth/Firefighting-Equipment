<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Auth;

use FirefightingEquipment\Api\V1\Auth\NotificationSettingsHandler;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Eigene Benachrichtigung „Log“ (GET/POST auth/notifications): Admins unter ihrer Stadt,
 * Super-Admins unter service, Standard aus. Die Routen gibt es unter beiden Bereichen mit
 * unterschiedlicher Zugriffsstufe, daher stehen ihre Rechte hier und nicht in
 * AdminRoutesAccessTest bzw. SuperAdminRoutesAccessTest.
 */
#[CoversClass(NotificationSettingsHandler::class)]
final class NotificationSettingsTest extends AuthTestCase
{
    public function testAdminSwitchesOwnNotification(): void
    {
        $admin = $this->account('immendingen', 'admin@example.org', ['role' => 'admin']);
        $cookie = $this->loginCookie('immendingen', 'admin@example.org');
        $url = self::url('immendingen', 'auth/notifications');

        self::assertSame(['device_log' => false], self::json($this->send('GET', $url, cookie: $cookie))['notifications']);

        $response = $this->send('POST', $url, ['device_log' => true], $cookie);
        self::assertSame(200, $response->statusCode(), $response->body());
        self::assertSame(['device_log' => true], self::json($response)['notifications']);
        self::assertSame(['device_log' => true], self::json($this->send('GET', $url, cookie: $cookie))['notifications']);
        self::assertSame(1, (int) $this->pdo()->query('SELECT notify_device_log FROM fe_users WHERE id = ' . $admin)->fetchColumn());
        self::assertTrue(self::json($this->send('GET', self::url('immendingen', 'auth/me'), cookie: $cookie))['user']['notifications']['device_log']);

        // Wiederholbar.
        self::assertSame(200, $this->send('POST', $url, ['device_log' => true], $cookie)->statusCode());
        self::assertSame(['device_log' => false], self::json($this->send('POST', $url, ['device_log' => false], $cookie))['notifications']);
    }

    public function testMailTopicsAreChosenPerPerson(): void
    {
        $admin = $this->account('immendingen', 'admin@example.org', ['role' => 'admin']);
        $cookie = $this->loginCookie('immendingen', 'admin@example.org');
        $url = self::url('immendingen', 'auth/notifications');

        $initial = self::json($this->send('GET', $url, cookie: $cookie))['topics'];
        self::assertNull($initial['selected'], 'ohne Auswahl gelten alle Themen');
        self::assertSame(['defect_reported', 'used_reported', 'return_received', 'lent', 'notices', 'changes', 'master_data'], array_column($initial['available'], 'id'));

        $response = $this->send('POST', $url, ['device_log' => true, 'topics' => ['lent', 'defect_reported', 'lent']], $cookie);
        self::assertSame(200, $response->statusCode(), $response->body());
        self::assertSame(['defect_reported', 'lent'], self::json($response)['topics']['selected'], 'ohne Wiederholung, in fester Reihenfolge der Datenbank');
        self::assertSame(2, (int) $this->pdo()->query('SELECT COUNT(*) FROM fe_user_mail_prefs WHERE user_id = ' . $admin)->fetchColumn());

        // Ohne "topics" bleibt die Auswahl, null stellt auf alle Themen zurück.
        self::assertSame(['defect_reported', 'lent'], self::json($this->send('POST', $url, ['device_log' => true], $cookie))['topics']['selected']);
        self::assertNull(self::json($this->send('POST', $url, ['device_log' => true, 'topics' => null], $cookie))['topics']['selected']);
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM fe_user_mail_prefs')->fetchColumn());

        foreach ([[], 'lent', ['unbekannt'], [1], ['lent' => 'x']] as $bad) {
            $rejected = $this->send('POST', $url, ['device_log' => true, 'topics' => $bad], $cookie);
            self::assertSame(400, $rejected->statusCode(), json_encode($bad, JSON_THROW_ON_ERROR));
        }
    }

    public function testSuperAdminSwitchesOwnNotificationEvenIfProtected(): void
    {
        $chef = $this->account(null, 'chef@example.org', ['protected' => 1]);
        $cookie = $this->loginCookie(null, 'chef@example.org');

        $response = $this->send('POST', self::url(null, 'auth/notifications'), ['device_log' => true], $cookie);

        self::assertSame(200, $response->statusCode(), $response->body());
        self::assertSame(['device_log' => true], self::json($response)['notifications']);
        self::assertSame(1, (int) $this->pdo()->query('SELECT notify_device_log FROM fe_users WHERE id = ' . $chef)->fetchColumn());
    }

    public function testOnlyAdminsAndSuperAdminsOfTheirArea(): void
    {
        $this->account('immendingen', 'admin@example.org', ['role' => 'admin']);
        $this->account('immendingen', 'user@example.org');
        $this->account('tuttlingen', 'admin@example.org', ['role' => 'admin']);
        $this->account(null, 'chef@example.org');
        $tenantUrl = self::url('immendingen', 'auth/notifications');
        $serviceUrl = self::url(null, 'auth/notifications');
        $user = $this->loginCookie('immendingen', 'user@example.org');
        $otherAdmin = $this->loginCookie('tuttlingen', 'admin@example.org');
        $admin = $this->loginCookie('immendingen', 'admin@example.org');
        $chef = $this->loginCookie(null, 'chef@example.org');

        foreach (['GET' => null, 'POST' => ['device_log' => true]] as $method => $body) {
            self::assertError(401, 'not_authenticated', $this->send($method, $tenantUrl, $body));
            self::assertError(401, 'not_authenticated', $this->send($method, $serviceUrl, $body));
            self::assertError(403, 'forbidden', $this->send($method, $tenantUrl, $body, $user));
            self::assertError(401, 'not_authenticated', $this->send($method, $tenantUrl, $body, $otherAdmin));
            self::assertError(401, 'not_authenticated', $this->send($method, $tenantUrl, $body, $chef));
            self::assertError(401, 'not_authenticated', $this->send($method, $serviceUrl, $body, $admin));
        }
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM fe_users WHERE notify_device_log = 1')->fetchColumn());
    }

    public function testInvalidBody(): void
    {
        $this->account('immendingen', 'admin@example.org', ['role' => 'admin']);
        $cookie = $this->loginCookie('immendingen', 'admin@example.org');
        $url = self::url('immendingen', 'auth/notifications');

        foreach ([[], ['device_log' => 1], ['device_log' => 'true'], ['device_log' => null], ['device_log' => true, 'other' => true]] as $body) {
            self::assertError(400, 'invalid_request', $this->send('POST', $url, $body, $cookie));
        }
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM fe_users WHERE notify_device_log = 1')->fetchColumn());
    }
}
