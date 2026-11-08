<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Auth;

use FirefightingEquipment\Http\Access;
use FirefightingEquipment\Http\CsvResponse;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\Response;
use FirefightingEquipment\Http\Routes;
use FirefightingEquipment\Permission\Action;
use FirefightingEquipment\Permission\AreaLevel;
use FirefightingEquipment\Permission\FieldRule;
use FirefightingEquipment\Permission\Need;
use FirefightingEquipment\Permission\Requirement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Rechte aller Routen unter einer Stadt (Plan Phase 1, Schritt 1.3):
 * 1. Die Tabelle unten nennt je Route die bisherige Stufe (Access bis Schritt 1.2) und das neue
 *    Erfordernis; sie ist gleich dem, was Routes::tenant() deklariert. Eine neue Route ohne Eintrag
 *    lässt den Test scheitern.
 * 2. Gleiches Verhalten: Für die Standard-Rollen Admin und Benutzer und alle anderen Fälle
 *    (ohne Sitzung, Konto einer anderen Stadt, Super-Admin, gesperrte und nicht aktive Konten,
 *    unbekannte und gesperrte Städte) lässt Application genau die Anfragen durch, die die bisherige
 *    Stufe durchließ.
 * 3. Jedes nötige Recht wird geprüft: Eine Rolle mit genau den Rechten einer Alternative kommt
 *    durch, fehlt eines davon, 403; ebenso jede Feldregel.
 * „Durch“ heißt: Application lehnt nicht mit not_authenticated oder forbidden ab (der Handler darf
 * danach z. B. mit 400 oder 404 antworten; IDs sind absichtlich unbekannt, nichts wird geändert).
 */
#[CoversClass(Routes::class)]
final class TenantRoutePermissionsTest extends AuthTestCase
{
    private const string UNKNOWN_ID = '999999';

    /**
     * Route => [bisherige Stufe, neues Erfordernis (Access-Wert oder Requirement::describe())].
     */
    private const array ROUTES = [
        'POST api/v1/auth/login' => ['public', 'public'],
        'POST api/v1/auth/logout' => ['public', 'public'],
        'GET api/v1/auth/me' => ['authenticated', 'authenticated'],
        'GET api/v1/auth/sessions' => ['authenticated', 'authenticated'],
        'DELETE api/v1/auth/sessions/{id}' => ['authenticated', 'authenticated'],
        'POST api/v1/auth/sessions/logout-others' => ['authenticated', 'authenticated'],
        'POST api/v1/auth/password' => ['authenticated', 'authenticated'],
        'POST api/v1/auth/password-reset/request' => ['public', 'public'],
        'POST api/v1/auth/password-reset/confirm' => ['public', 'public'],
        'GET api/v1/health' => ['public', 'public'],
        'GET api/v1/tenant' => ['public', 'public'],
        'POST api/v1/tenant/appearance' => ['admin', 'settings:write'],
        'POST api/v1/tenant/logo' => ['admin', 'settings:write'],
        'DELETE api/v1/tenant/logo' => ['admin', 'settings:write'],
        'GET api/v1/tenant/logo/{size}' => ['public', 'public'],
        'GET api/v1/app-icon/{size}' => ['public', 'public'],
        'GET manifest.webmanifest' => ['public', 'public'],
        'POST api/v1/auth/register' => ['public', 'public'],
        'POST api/v1/auth/email/verify' => ['public', 'public'],
        'POST api/v1/auth/email/resend' => ['public', 'public'],
        'POST api/v1/auth/invitation/accept' => ['public', 'public'],
        'GET api/v1/auth/notifications' => ['admin', 'mail.log'],
        'POST api/v1/auth/notifications' => ['admin', 'mail.log'],
        'GET api/v1/invitation-codes' => ['admin', 'users:read'],
        'POST api/v1/invitation-codes' => ['admin', 'users:write'],
        'POST api/v1/invitation-codes/{id}/revoke' => ['admin', 'users:write'],
        'GET api/v1/users' => ['admin', 'users:read'],
        'GET api/v1/users/{id}' => ['admin', 'users:read'],
        'DELETE api/v1/users/{id}' => ['admin', 'users:write'],
        'POST api/v1/users/{id}/approve' => ['admin', 'users:write'],
        'POST api/v1/users/{id}/reject' => ['admin', 'users:write'],
        'POST api/v1/users/{id}/lock' => ['admin', 'users:write'],
        'POST api/v1/users/{id}/unlock' => ['admin', 'users:write'],
        'POST api/v1/users/{id}/role' => ['admin', 'users:write'],
        'POST api/v1/users/{id}/display-name' => ['admin', 'users:write'],
        // Neu seit Schritt M1 (Claude-Zugang); F12 (nur die Rolle Admin) prüft UserAdministration.
        'POST api/v1/users/{id}/claude-access' => ['admin', 'users:write'],
        'POST api/v1/users/{id}/claude-connections/end' => ['admin', 'users:write'],
        'POST api/v1/users/bulk/role' => ['admin', 'users:write'],
        'GET api/v1/roles' => ['admin', 'users:read'],
        'GET api/v1/device-types' => ['authenticated', 'device_types:read | search:read | devices:read'],
        'POST api/v1/device-types' => ['admin', 'device_types:write'],
        'POST api/v1/device-types/{id}/rename' => ['admin', 'device_types:write'],
        'POST api/v1/device-types/{id}/deactivate' => ['admin', 'device_types:write'],
        'POST api/v1/device-types/{id}/activate' => ['admin', 'device_types:write'],
        'GET api/v1/locations' => ['authenticated', 'locations:read | search:read | devices:read'],
        'POST api/v1/locations' => ['admin', 'locations:write'],
        'GET api/v1/locations/{id}' => ['authenticated', 'locations:read | search:read | devices:read'],
        'POST api/v1/locations/{id}/rename' => ['admin', 'locations:write'],
        'POST api/v1/locations/{id}/lock' => ['admin', 'locations:write'],
        'POST api/v1/locations/{id}/unlock' => ['admin', 'locations:write'],
        'POST api/v1/locations/{id}/task' => ['admin', 'locations:write'],
        'GET api/v1/location-tasks' => ['authenticated', 'locations:read | search:read | devices:read'],
        'GET api/v1/devices' => ['authenticated', 'search:read | devices:read'],
        // Exporte seit Schritt 1.7a nur mit csv.export (Plan, F2; Benutzer siehe CHANGED_FOR_USER).
        'GET api/v1/devices/export' => ['authenticated', 'csv.export'],
        'GET api/v1/device-events/export' => ['authenticated', 'csv.export & log:read'],
        'GET api/v1/device-events' => ['admin', 'dashboard:read | log:read'],
        'POST api/v1/devices' => ['admin', 'device.create'],
        'GET api/v1/devices/import/template' => ['admin', 'csv.import'],
        'GET api/v1/devices/import/values' => ['admin', 'csv.import'],
        'POST api/v1/devices/import/preview' => ['admin', 'csv.import'],
        'POST api/v1/devices/import' => ['admin', 'csv.import'],
        'POST api/v1/devices/bulk/inspection-due' => ['admin', 'devices.bulk & due:write'],
        'POST api/v1/devices/bulk/state' => ['admin', 'devices.bulk; Felder: devices[].location_id → book.move, devices[].status → book.status'],
        'GET api/v1/devices/{id}' => ['authenticated', 'search:read | devices:read'],
        'POST api/v1/devices/{id}/fields' => ['admin', 'device.edit | device.code; Felder: scan_code → device.code, identifier → device.edit,'
            . ' device_type_id → device.edit, serial_number → device.edit, manufacturer → device.edit, model → device.edit,'
            . ' procured_on → device.edit, note → device.edit'],
        'POST api/v1/devices/{id}/inspection-due' => ['admin', 'due:write'],
        'POST api/v1/devices/{id}/state' => ['authenticated', 'book.move | book.status; Felder: location_id → book.move, status → book.status'],
        'POST api/v1/devices/{id}/retire' => ['admin', 'device.retire'],
        'POST api/v1/devices/{id}/reinstate' => ['admin', 'device.retire; Felder: identifier → device.edit, device_type_id → device.edit, scan_code → device.code'],
        'GET api/v1/devices/{id}/events' => ['authenticated', 'search:read | devices:read'],
        'GET api/v1/device-summary' => ['authenticated', 'dashboard:read | due:read'],
        'GET api/v1/inspection-due' => ['authenticated', 'due:read'],
        'GET api/v1/client-config' => ['authenticated', 'authenticated'],
        'GET api/v1/notices' => ['admin', 'log:read'],
        'GET api/v1/notices/{id}' => ['admin', 'log:read'],
        'POST api/v1/notices/{id}/resolve' => ['admin', 'log:write'],
        'POST api/v1/notices/{id}/reopen' => ['admin', 'log:write'],
        'GET api/v1/auth/claude-connections' => ['authenticated', 'authenticated'],
        'DELETE api/v1/auth/claude-connections/{id}' => ['authenticated', 'authenticated'],
        // Claude-Zugang (nur Test-Stadt, sonst 404 vor der Anmeldung; OAuthServerTest, McpEndpointTest).
        'POST mcp' => ['public', 'claude'],
        'GET .well-known/oauth-protected-resource' => ['public', 'public'],
        'GET .well-known/openid-configuration' => ['public', 'public'],
        'GET .well-known/oauth-authorization-server' => ['public', 'public'],
        'GET .well-known/jwks.json' => ['public', 'public'],
        'POST oauth/register' => ['public', 'public'],
        'POST oauth/token' => ['public', 'public'],
        'POST oauth/revoke' => ['public', 'public'],
        'POST api/v1/oauth/authorize/check' => ['public', 'public'],
        'POST api/v1/oauth/authorize' => ['public', 'public'],
    ];

    /** Routen, die jedes angemeldete Konto ohne Rechteprüfung aufrufen darf (Plan, Abschnitt 3). */
    private const array AUTHENTICATED_ONLY = [
        'GET api/v1/auth/me', 'GET api/v1/auth/sessions', 'DELETE api/v1/auth/sessions/{id}',
        'POST api/v1/auth/sessions/logout-others', 'POST api/v1/auth/password', 'GET api/v1/client-config',
        'GET api/v1/auth/claude-connections', 'DELETE api/v1/auth/claude-connections/{id}',
    ];

    /** Routen mit Formular statt JSON (BodyFormat::Form). */
    private const array FORM_ROUTES = ['POST oauth/token', 'POST oauth/revoke'];

    /**
     * Gewollte Änderung für die Standard-Rolle Benutzer (Plan, F2, Schritt 1.7a): bisher erlaubt,
     * jetzt 403, weil Benutzer kein csv.export hat. Admin kommt weiterhin durch.
     */
    private const array CHANGED_FOR_USER = ['GET api/v1/devices/export', 'GET api/v1/device-events/export'];

    private int $roleCounter = 0;

    public function testTableMatchesDeclaredRoutes(): void
    {
        $declared = [];
        foreach (Routes::tenant()->routes() as $route) {
            $access = $route['access'];
            $declared[$route['method'] . ' ' . $route['path']] = $access instanceof Requirement ? $access->describe() : $access->value;
        }
        ksort($declared);
        $expected = array_map(static fn (array $row): string => $row[1], self::ROUTES);
        ksort($expected);

        self::assertSame($expected, $declared);
        self::assertSame(
            self::AUTHENTICATED_ONLY,
            array_keys(array_filter(self::ROUTES, static fn (array $row): bool => $row[1] === 'authenticated')),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function routes(): iterable
    {
        foreach (self::ROUTES as $key => [$legacy]) {
            yield $key => [$key, $legacy];
        }
    }

    #[DataProvider('routes')]
    public function testStandardRolesBehaveLikeLegacyAccess(string $route, string $legacy): void
    {
        [$method, $path] = explode(' ', $route, 2);
        $tenantId = $this->tenant('immendingen')->id;
        $admin = $this->account('immendingen', 'admin@example.org', ['role' => 'admin']);
        $this->account('immendingen', 'user@example.org');
        $this->account('immendingen', 'gesperrt@example.org');
        $this->account('immendingen', 'gesperrt-admin@example.org', ['role' => 'admin']);
        $code = $this->createInvitationCode($tenantId, $admin);
        $this->account('immendingen', 'wartet@example.org', ['invitation_code_id' => $code]);
        $this->account('tuttlingen', 'admin@example.org', ['role' => 'admin']);
        $this->account(null, 'chef@example.org');
        $this->createTenant('geisingen');
        $this->account('geisingen', 'user@example.org');

        $cookies = [
            'Admin' => $this->loginCookie('immendingen', 'admin@example.org'),
            'Benutzer' => $this->loginCookie('immendingen', 'user@example.org'),
            'gesperrt' => $this->loginCookie('immendingen', 'gesperrt@example.org'),
            'gesperrter Admin' => $this->loginCookie('immendingen', 'gesperrt-admin@example.org'),
            'nicht aktiv' => $this->loginCookie('immendingen', 'wartet@example.org'),
            'Admin anderer Stadt' => $this->loginCookie('tuttlingen', 'admin@example.org'),
            'Super-Admin' => $this->loginCookie(null, 'chef@example.org'),
            'gesperrte Stadt' => $this->loginCookie('geisingen', 'user@example.org'),
        ];
        $this->pdo()->exec("UPDATE fe_users SET locked_at = UTC_TIMESTAMP() WHERE email LIKE 'gesperrt%'");
        $this->pdo()->exec("UPDATE fe_users SET status = 'pending' WHERE email = 'wartet@example.org'");
        $this->pdo()->exec("UPDATE fe_tenants SET active = 0 WHERE slug = 'geisingen'");

        $expected = [
            'ohne Sitzung' => $legacy === 'public',
            'Admin' => true,
            'Benutzer' => $legacy !== 'admin' && !in_array($route, self::CHANGED_FOR_USER, true),
        ];
        foreach (['gesperrt', 'gesperrter Admin', 'nicht aktiv', 'Admin anderer Stadt', 'Super-Admin'] as $actor) {
            $expected[$actor] = $legacy === 'public';
        }

        $actual = [
            'ohne Sitzung' => $this->passes($method, 'immendingen', $path, null),
            'Admin' => $this->passes($method, 'immendingen', $path, $cookies['Admin']),
            'Benutzer' => $this->passes($method, 'immendingen', $path, $cookies['Benutzer']),
        ];
        foreach (['gesperrt', 'gesperrter Admin', 'nicht aktiv', 'Admin anderer Stadt', 'Super-Admin'] as $actor) {
            $actual[$actor] = $this->passes($method, 'immendingen', $path, $cookies[$actor]);
        }
        self::assertSame($expected, $actual);

        // Die Stadt wird vor der Sitzung geprüft, für jede Route gleich.
        self::assertSame(['tenant_not_found', 404], $this->errorOf($method, 'unbekannt', $path, $cookies['Admin']));
        self::assertSame(['tenant_inactive', 403], $this->errorOf($method, 'geisingen', $path, $cookies['gesperrte Stadt']));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function requirementRoutes(): iterable
    {
        foreach (self::ROUTES as $key => [, $new]) {
            if (!in_array($new, ['public', 'authenticated', 'claude'], true)) {
                yield $key => [$key];
            }
        }
    }

    #[DataProvider('requirementRoutes')]
    public function testEveryNeededRightIsChecked(string $route): void
    {
        [$method, $path] = explode(' ', $route, 2);
        $requirement = self::requirementOf($route);

        foreach ($requirement->alternatives as $needs) {
            self::assertTrue($this->passesWith($method, $path, $needs, []), sprintf('%s mit %s', $route, self::keys($needs)));
            foreach ($needs as $i => $missing) {
                $reduced = $needs;
                unset($reduced[$i]);
                $reduced = array_values($reduced);
                if (self::satisfiesOtherAlternative($requirement, $reduced)) {
                    continue;
                }
                self::assertFalse($this->passesWith($method, $path, $reduced, []), sprintf('%s ohne %s', $route, $missing->key()));
            }
        }

        foreach ($requirement->fieldRules as $rule) {
            $base = self::alternativeWithout($requirement, $rule->action);
            $body = self::bodyFor($rule);
            self::assertFalse($this->passesWith($method, $path, $base, $body), sprintf('%s: %s ohne Recht', $route, $rule->key()));
            self::assertTrue(
                $this->passesWith($method, $path, [...$base, Need::action($rule->action)], $body),
                sprintf('%s: %s mit Recht', $route, $rule->key()),
            );
        }
    }

    public function testStandardRoleUserGetsForbiddenOnExports(): void
    {
        $this->account('immendingen', 'admin@example.org', ['role' => 'admin']);
        $this->account('immendingen', 'user@example.org');
        $admin = $this->loginCookie('immendingen', 'admin@example.org');
        $user = $this->loginCookie('immendingen', 'user@example.org');

        foreach (self::CHANGED_FOR_USER as $route) {
            [, $path] = explode(' ', $route, 2);
            self::assertSame(['forbidden', 403], $this->errorOf('GET', 'immendingen', $path, $user), $route);
            $response = $this->sendAny('GET', '/firefighting/immendingen/' . $path, null, $admin);
            self::assertInstanceOf(CsvResponse::class, $response, $route);
            self::assertSame(200, $response->statusCode(), $route);
            $this->responseBody($response);
        }
    }

    public function testAuthenticatedOnlyRoutesNeedNoRight(): void
    {
        $cookie = $this->cookieForRole([]);

        foreach (self::AUTHENTICATED_ONLY as $route) {
            [$method, $path] = explode(' ', $route, 2);
            if ($route === 'POST api/v1/auth/sessions/logout-others') {
                continue; // beendet nur andere Sitzungen; geprüft unten mit eigener Sitzung
            }
            self::assertTrue($this->passes($method, 'immendingen', $path, $cookie), $route);
        }
        self::assertTrue($this->passes('POST', 'immendingen', 'api/v1/auth/sessions/logout-others', $this->cookieForRole([])));
    }

    /**
     * Rolle mit genau diesen Rechten (Aktionen samt dem Bereich, den sie brauchen) anlegen,
     * anmelden und die Route aufrufen.
     *
     * @param list<Need>           $needs
     * @param array<string, mixed> $body
     */
    private function passesWith(string $method, string $path, array $needs, array $body): bool
    {
        return $this->passes($method, 'immendingen', $path, $this->cookieForRole($needs), $body);
    }

    /**
     * @param list<Need> $needs
     */
    private function cookieForRole(array $needs): string
    {
        $tenantId = $this->tenant('immendingen')->id;
        $roleId = $this->insert('fe_roles', ['tenant_id' => $tenantId, 'name' => 'Testrolle ' . ++$this->roleCounter]);
        $areas = [];
        $actions = [];
        foreach ($needs as $need) {
            if ($need->action !== null) {
                $actions[$need->action->value] = true;
                $required = $need->action->requires();
                if ($required !== null && !self::needsArea($needs, $required[0]->value)) {
                    $areas[$required[0]->value] = $required[1]->value;
                }
            } elseif ($need->area !== null && $need->level !== null) {
                $current = $areas[$need->area->value] ?? null;
                $areas[$need->area->value] = $current === AreaLevel::Write->value ? $current : $need->level->value;
            }
        }
        foreach ($areas as $area => $level) {
            $this->insert('fe_role_areas', ['role_id' => $roleId, 'tenant_id' => $tenantId, 'area' => $area, 'level' => $level]);
        }
        foreach (array_keys($actions) as $action) {
            $this->insert('fe_role_actions', ['role_id' => $roleId, 'tenant_id' => $tenantId, 'action' => $action]);
        }
        $email = sprintf('rolle%d@example.org', $this->roleCounter);
        $this->account('immendingen', $email, ['role_id' => $roleId]);

        return $this->loginCookie('immendingen', $email);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function passes(string $method, string $slug, string $path, ?string $cookie, array $body = []): bool
    {
        return !in_array($this->errorOf($method, $slug, $path, $cookie, $body)[0] ?? null, ['not_authenticated', 'forbidden'], true);
    }

    /**
     * Fehlercode und Status der Antwort, [] ohne JSON-Fehler.
     *
     * @param array<string, mixed> $body
     *
     * @return array{0?: string, 1?: int}
     */
    private function errorOf(string $method, string $slug, string $path, ?string $cookie, array $body = []): array
    {
        $uri = '/firefighting/' . $slug . '/' . preg_replace('/\{[a-z_]+\}/', self::UNKNOWN_ID, $path);
        if (in_array($method . ' ' . $path, self::FORM_ROUTES, true)) {
            $response = $this->sendAny($method, $uri, http_build_query($body), $cookie, ['content-type' => 'application/x-www-form-urlencoded']);

            return self::error($response);
        }
        $response = $this->sendAny($method, $uri, $method === 'GET' ? null : $body, $cookie);

        return self::error($response);
    }

    /**
     * @return array{0?: string, 1?: int}
     */
    private static function error(Response $response): array
    {
        if ($response instanceof CsvResponse || !$response instanceof JsonResponse) {
            return [];
        }
        $error = json_decode($response->body(), true)['error'] ?? null;

        return is_string($error) ? [$error, $response->statusCode()] : [];
    }

    private static function requirementOf(string $route): Requirement
    {
        foreach (Routes::tenant()->routes() as $declared) {
            if ($declared['method'] . ' ' . $declared['path'] === $route && $declared['access'] instanceof Requirement) {
                return $declared['access'];
            }
        }
        self::fail('Kein Requirement: ' . $route);
    }

    /**
     * @param list<Need> $granted
     */
    private static function satisfiesOtherAlternative(Requirement $requirement, array $granted): bool
    {
        $keys = self::grantedKeys($granted);
        foreach ($requirement->alternatives as $needs) {
            if (array_all($needs, static fn (Need $n): bool => in_array($n->key(), $keys, true))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rechte, die eine Rolle mit $granted hat (Ändern schließt Ansehen ein).
     *
     * @param list<Need> $granted
     *
     * @return list<string>
     */
    private static function grantedKeys(array $granted): array
    {
        $keys = [];
        foreach ($granted as $need) {
            $keys[] = $need->key();
            if ($need->level === AreaLevel::Write && $need->area !== null) {
                $keys[] = $need->area->value . ':read';
            }
        }

        return $keys;
    }

    /**
     * Eine Alternative ohne die Aktion der Feldregel (es gibt in jeder Route eine).
     *
     * @return list<Need>
     */
    private static function alternativeWithout(Requirement $requirement, Action $action): array
    {
        foreach ($requirement->alternatives as $needs) {
            if (!in_array($action->value, array_map(static fn (Need $n): string => $n->key(), $needs), true)) {
                return $needs;
            }
        }
        self::fail('Keine Alternative ohne ' . $action->value);
    }

    /**
     * @return array<string, mixed>
     */
    private static function bodyFor(FieldRule $rule): array
    {
        return $rule->list === null ? [$rule->field => null] : [$rule->list => [[$rule->field => null]]];
    }

    /**
     * @param list<Need> $needs
     */
    private static function needsArea(array $needs, string $area): bool
    {
        return array_any($needs, static fn (Need $n): bool => $n->area?->value === $area);
    }

    /**
     * @param list<Need> $needs
     */
    private static function keys(array $needs): string
    {
        return implode(' & ', array_map(static fn (Need $n): string => $n->key(), $needs)) ?: '(keine)';
    }

    /**
     * Die Tabelle nennt nur bekannte Stufen; öffentliche Routen bleiben öffentlich. mcp (Claude) gilt
     * hier als öffentlich: Es kennt kein Cookie, und außerhalb der Test-Stadt gibt es die Route nicht.
     */
    public function testLegacyColumnUsesKnownAccessLevels(): void
    {
        foreach (self::ROUTES as $route => [$legacy, $new]) {
            self::assertContains($legacy, ['public', 'authenticated', 'admin'], $route);
            self::assertSame($legacy === Access::Public->value, in_array($new, [Access::Public->value, Access::Claude->value], true), $route);
        }
    }
}
