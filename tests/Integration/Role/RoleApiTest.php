<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Role;

use FirefightingEquipment\Api\V1\Roles\PermissionCatalogHandler;
use FirefightingEquipment\Api\V1\Roles\RoleFields;
use FirefightingEquipment\Api\V1\Roles\RoleHandler;
use FirefightingEquipment\Api\V1\Roles\RoleJson;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Permission\Action;
use FirefightingEquipment\Permission\AreaLevel;
use FirefightingEquipment\Role\RoleDraft;
use FirefightingEquipment\Role\RoleRepository;
use FirefightingEquipment\Role\RoleRuleViolationException;
use FirefightingEquipment\Tests\Integration\Auth\AuthTestCase;
use FirefightingEquipment\Tests\Integration\Device\DeviceRows;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Rollen einer Stadt durch den Super-Admin über die API (Plan Phase 1, Schritt 1.5): Katalog,
 * auflisten, anlegen, speichern (Version, F3, leerer Filter, fremde Standorte und Typen), Schutz der
 * Standard-Rollen, löschen nur ohne Nutzung, kopieren, aus einer anderen Stadt übernehmen, Auswahl
 * für den Datenfilter. Rechte prüft SuperAdminRoutesAccessTest.
 */
#[CoversClass(RoleHandler::class)]
#[CoversClass(RoleFields::class)]
#[CoversClass(RoleJson::class)]
#[CoversClass(PermissionCatalogHandler::class)]
#[CoversClass(RoleRepository::class)]
#[CoversClass(RoleDraft::class)]
#[CoversClass(RoleRuleViolationException::class)]
final class RoleApiTest extends AuthTestCase
{
    use DeviceRows;

    private int $chef;
    private string $cookie;
    private int $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->chef = $this->account(null, 'chef@example.org');
        $this->cookie = $this->loginCookie(null, 'chef@example.org');
        $this->tenantId = $this->tenant('immendingen')->id;
    }

    // ---- Katalog und Liste -------------------------------------------------------------------

    public function testCatalogListsAreasActionsAndFilterRules(): void
    {
        $catalog = self::json($this->get('permission-catalog'));

        self::assertSame(
            ['dashboard', 'search', 'devices', 'locations', 'device_types', 'due', 'log', 'users', 'settings'],
            array_column($catalog['areas'], 'key'),
        );
        $areas = array_column($catalog['areas'], null, 'key');
        self::assertSame(['key' => 'dashboard', 'label' => 'Dashboard', 'hint' => 'Kacheln und letzte Aktivitäten', 'levels' => ['read'], 'levels_with_filter' => ['read']], $areas['dashboard']);
        self::assertSame(['read', 'write'], $areas['devices']['levels_with_filter']);
        self::assertSame(['read'], $areas['locations']['levels_with_filter'], 'F3');
        self::assertSame(['read'], $areas['device_types']['levels_with_filter'], 'F3');
        self::assertSame([], $areas['users']['levels_with_filter'], 'F3');
        self::assertSame([['key' => 'read', 'label' => 'Ansehen'], ['key' => 'write', 'label' => 'Ändern']], $catalog['levels']);
        self::assertSame(['Buchen', 'Ausrüstung verwalten', 'Mehrere Ausrüstungen', 'Benachrichtigungen'], array_column($catalog['action_groups'], 'label'));
        self::assertSame(
            ['key' => 'book.move', 'label' => 'Umbuchen', 'requires' => ['area' => 'search', 'level' => 'read'], 'with_filter' => true],
            $catalog['action_groups'][0]['actions'][0],
        );
        $actions = array_column(array_merge(...array_column($catalog['action_groups'], 'actions')), null, 'key');
        self::assertFalse($actions['csv.import']['with_filter'], 'F3');
        self::assertNull($actions['mail.log']['requires']);
        self::assertSame(['key' => 'csv.export', 'label' => 'CSV-Export', 'requires' => ['area' => 'devices', 'level' => 'read'], 'with_filter' => true], $actions['csv.export']);
        self::assertCount(10, $actions);
    }

    public function testListShowsDefaultRolesWithUsage(): void
    {
        $admin = $this->account('immendingen', 'admin@example.org', ['role' => 'admin']);
        $this->account('immendingen', 'max@example.org');
        $this->deletedAccount('immendingen', null);
        $this->openCode($admin);
        $this->openCode($admin, ['revoked_at' => gmdate('Y-m-d H:i:s')]);
        $this->createInvitationCode($this->tenantId, $admin);
        $this->role('Gerätewart', ['devices' => 'write', 'search' => 'read'], ['device.edit', 'book.move']);

        $roles = self::json($this->get('tenants/' . $this->tenantId . '/roles'))['roles'];

        self::assertSame(['Admin', 'Benutzer', 'Gerätewart'], array_column($roles, 'name'));
        self::assertTrue($roles[0]['all_rights']);
        self::assertFalse($roles[0]['editable']);
        self::assertFalse($roles[0]['deletable']);
        self::assertSame('admin', $roles[0]['system_key']);
        self::assertSame(['dashboard', 'search', 'devices', 'locations', 'device_types', 'due', 'log', 'users', 'settings'], array_keys($roles[0]['areas']));
        self::assertSame(['accounts' => 1, 'invitation_codes' => 0], $roles[0]['usage']);

        self::assertSame([
            'id' => $this->systemRoleId($this->tenantId, 'user'),
            'name' => 'Benutzer',
            'description' => 'Wie bisher; bekommt jede neue Stadt',
            'system_key' => 'user',
            'all_rights' => false,
            'editable' => true,
            'deletable' => false,
            'areas' => ['search' => 'read', 'due' => 'read'],
            'actions' => ['book.move', 'book.status'],
            'locations' => null,
            'device_types' => null,
            'version' => 1,
            'created_at' => $roles[1]['created_at'],
            'updated_at' => $roles[1]['updated_at'],
            // Gelöschte Konten zählen nicht, offene Codes ohne Rolle zählen zu Benutzer.
            'usage' => ['accounts' => 1, 'invitation_codes' => 1],
        ], $roles[1]);
        self::assertSame(['search' => 'read', 'devices' => 'write'], $roles[2]['areas'], 'Reihenfolge des Katalogs');
        self::assertSame(['book.move', 'device.edit'], $roles[2]['actions']);
        self::assertTrue($roles[2]['deletable']);
        self::assertNull($roles[2]['system_key']);

        self::assertError(404, 'not_found', $this->get('tenants/999999/roles'));
    }

    // ---- Anlegen -----------------------------------------------------------------------------

    public function testCreateRoleWithFilters(): void
    {
        $hlf = $this->createLocation($this->tenantId, ['name' => 'HLF 20']);
        $fach = $this->createSubLocation($this->tenantId, $hlf, ['name' => 'G1']);
        $type = $this->createDeviceType($this->tenantId, ['name' => 'Atemschutzmaske']);

        $response = $this->post('tenants/' . $this->tenantId . '/roles', self::draft([
            'name' => '  Geräteträger HLF ',
            'description' => ' Handy: suchen und buchen ',
            'areas' => ['due' => 'read', 'search' => 'read'],
            'actions' => ['book.status', 'book.move', 'book.move'],
            'locations' => [$fach, $hlf],
            'device_types' => [$type],
        ]));

        self::assertSame(201, $response->statusCode(), $response->body());
        $role = self::json($response)['role'];
        self::assertSame('Geräteträger HLF', $role['name']);
        self::assertSame('Handy: suchen und buchen', $role['description']);
        self::assertSame(['search' => 'read', 'due' => 'read'], $role['areas']);
        self::assertSame(['book.move', 'book.status'], $role['actions']);
        self::assertSame([$hlf, $fach], $role['locations']);
        self::assertSame([$type], $role['device_types']);
        self::assertSame(1, $role['version']);
        self::assertSame(['accounts' => 0, 'invitation_codes' => 0], $role['usage']);
        self::assertSame($role, self::json($this->get('tenants/' . $this->tenantId . '/roles/' . $role['id']))['role']);

        $row = $this->pdo()->query('SELECT updated_by, filter_locations, filter_device_types, system_key FROM fe_roles WHERE id = ' . $role['id'])->fetch();
        self::assertSame(['updated_by' => $this->chef, 'filter_locations' => 1, 'filter_device_types' => 1, 'system_key' => null], $row);
    }

    public function testEmptyDescriptionIsNone(): void
    {
        $role = self::json($this->post('tenants/' . $this->tenantId . '/roles', self::draft(['description' => '  '])))['role'];

        self::assertNull($role['description']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidDrafts(): iterable
    {
        yield 'Name leer' => [['name' => ' '], 'Der Name der Rolle darf nicht leer sein.'];
        yield 'Name zu lang' => [['name' => str_repeat('ä', 51)], 'Der Name der Rolle darf höchstens 50 Zeichen lang sein (angegeben: 51).'];
        yield 'Name mit Zeilenumbruch' => [['name' => "A\nB"], 'Der Name der Rolle darf keine Steuerzeichen (z. B. Zeilenumbrüche) enthalten.'];
        yield 'Beschreibung zu lang' => [['description' => str_repeat('x', 201)], 'Die Kurzbeschreibung darf höchstens 200 Zeichen lang sein (angegeben: 201).'];
        yield 'Bereich unbekannt' => [['areas' => ['maintenance' => 'read']], 'Unbekannter Bereich „maintenance“.'];
        yield 'Bereiche als Liste' => [['areas' => ['search']], 'Das Feld "areas" muss ein Objekt Bereich → Stufe sein.'];
        yield 'Stufe unbekannt' => [['areas' => ['search' => 'none']], 'Die Stufe für „Suchen und Ausrüstung ansehen“ muss "read" oder "write" sein.'];
        yield 'Dashboard ändern' => [['areas' => ['dashboard' => 'write']], 'Für „Dashboard“ gibt es nur „Ansehen“.'];
        yield 'Aktion unbekannt' => [['actions' => ['book.used']], 'Unbekannte Aktion „book.used“.'];
        yield 'Standorte leer' => [['locations' => []], 'Bitte mindestens einen Standort wählen oder „Alle Standorte“.'];
        yield 'Typen leer' => [['device_types' => []], 'Bitte mindestens einen Ausrüstungstyp wählen oder „Alle Ausrüstungstypen“.'];
        yield 'Standort keine Zahl' => [['locations' => ['1']], 'Das Feld "locations" darf nur positive ganze Zahlen enthalten.'];
        yield 'Standort einer anderen Stadt' => [['locations' => ['{tuttlingen-location}']], 'Den Standort #{tuttlingen-location} gibt es in dieser Stadt nicht.'];
        yield 'Typ unbekannt' => [['device_types' => [999999]], 'Den Ausrüstungstyp #999999 gibt es in dieser Stadt nicht.'];
        yield 'unbekanntes Feld' => [['restrict_single_use' => true], 'Das Feld "restrict_single_use" ist hier nicht vorgesehen (möglich: name, description, areas, actions, locations, device_types).'];
        yield 'Feld fehlt' => [['locations' => '{missing}'], 'Das Feld "locations" fehlt.'];
    }

    /**
     * Leerer Filter 400, Standorte und Typen nur aus derselben Stadt; es wird nichts angelegt.
     *
     * @param array<string, mixed> $fields
     */
    #[DataProvider('invalidDrafts')]
    public function testCreateRejectsInvalidValues(array $fields, string $message): void
    {
        $otherLocation = $this->createLocation($this->tenant('tuttlingen')->id);
        $body = self::draft($fields);
        if (($body['locations'] ?? null) === '{missing}') {
            unset($body['locations']);
        }
        if (($body['locations'][0] ?? null) === '{tuttlingen-location}') {
            $body['locations'] = [$otherLocation];
        }
        $before = $this->roleCount();

        $response = $this->post('tenants/' . $this->tenantId . '/roles', $body);

        self::assertError(400, 'invalid_request', $response);
        self::assertSame(str_replace('{tuttlingen-location}', (string) $otherLocation, $message), self::json($response)['message']);
        self::assertSame($before, $this->roleCount());
    }

    public function testNameIsUniqueWithoutCase(): void
    {
        $response = $this->post('tenants/' . $this->tenantId . '/roles', self::draft(['name' => 'BENUTZER']));

        self::assertError(409, 'name_taken', $response);
        self::assertSame('In dieser Stadt gibt es bereits eine Rolle „BENUTZER“.', self::json($response)['message']);
        // In einer anderen Stadt frei.
        self::assertSame(201, $this->post('tenants/' . $this->tenant('tuttlingen')->id . '/roles', self::draft(['name' => 'Gerätewart']))->statusCode());
        self::assertSame(201, $this->post('tenants/' . $this->tenantId . '/roles', self::draft(['name' => 'Gerätewart']))->statusCode());
    }

    // ---- F3 ----------------------------------------------------------------------------------

    /**
     * @return iterable<string, array{array<string, string>, list<string>, list<string>}>
     */
    public static function administrationRights(): iterable
    {
        yield 'Benutzer ansehen' => [['users' => 'read'], [], ['users']];
        yield 'Standorte ändern' => [['locations' => 'write'], [], ['locations']];
        yield 'Ausrüstungstypen ändern' => [['device_types' => 'write'], [], ['device_types']];
        yield 'CSV-Import' => [['devices' => 'write'], ['csv.import'], []];
    }

    /**
     * F3: Datenfilter und Verwaltungsrechte schließen sich aus (409), in beide Richtungen.
     *
     * @param array<string, string> $areas
     * @param list<string>          $actions
     * @param list<string>          $conflictingAreas
     */
    #[DataProvider('administrationRights')]
    public function testFilterExcludesAdministration(array $areas, array $actions, array $conflictingAreas): void
    {
        $type = $this->createDeviceType($this->tenantId);
        $base = $this->roleCount();

        $response = $this->post('tenants/' . $this->tenantId . '/roles', self::draft(['areas' => $areas, 'actions' => $actions, 'device_types' => [$type]]));

        self::assertError(409, 'rule_violation', $response);
        self::assertSame(['areas' => $conflictingAreas, 'actions' => $actions], self::json($response)['details']);
        self::assertStringStartsWith('Rollen mit „Sieht nur …“ dürfen nicht verwalten (', self::json($response)['message']);
        self::assertSame($base, $this->roleCount());

        // Ohne Filter erlaubt; danach den Filter setzen: ebenso 409.
        $role = self::json($this->post('tenants/' . $this->tenantId . '/roles', self::draft(['areas' => $areas, 'actions' => $actions])))['role'];
        $save = $this->post('tenants/' . $this->tenantId . '/roles/' . $role['id'], [...self::draft(['areas' => $areas, 'actions' => $actions, 'device_types' => [$type]]), 'version' => 1]);
        self::assertError(409, 'rule_violation', $save);
        self::assertSame(1, self::json($this->get('tenants/' . $this->tenantId . '/roles/' . $role['id']))['role']['version']);
    }

    public function testFilterAllowsViewingLocationsAndTypes(): void
    {
        $type = $this->createDeviceType($this->tenantId);

        $response = $this->post('tenants/' . $this->tenantId . '/roles', self::draft([
            'areas' => ['locations' => 'read', 'device_types' => 'read', 'devices' => 'write'],
            'actions' => ['device.edit', 'devices.bulk'],
            'device_types' => [$type],
        ]));

        self::assertSame(201, $response->statusCode(), $response->body());
    }

    // ---- Speichern ---------------------------------------------------------------------------

    public function testSaveChecksVersion(): void
    {
        $role = $this->role('Gerätewart', ['search' => 'read'], []);
        $url = 'tenants/' . $this->tenantId . '/roles/' . $role;

        $saved = self::json($this->post($url, [...self::draft(['name' => 'Gerätewart', 'areas' => ['devices' => 'read']]), 'version' => 1]))['role'];
        self::assertSame(2, $saved['version']);
        self::assertSame(['devices' => 'read'], $saved['areas']);

        $stale = $this->post($url, [...self::draft(['name' => 'Alt']), 'version' => 1]);
        self::assertError(409, 'version_conflict', $stale);
        self::assertSame($saved, self::json($stale)['details']['role']);
        self::assertSame('Die Rolle „Gerätewart“ wurde inzwischen geändert. Bitte die Seite neu laden und die Änderung erneut vornehmen.', self::json($stale)['message']);
        self::assertSame('Gerätewart', self::json($this->get($url))['role']['name']);

        self::assertError(400, 'invalid_request', $this->post($url, self::draft()), 'version fehlt');
        self::assertError(404, 'not_found', $this->post('tenants/' . $this->tenant('tuttlingen')->id . '/roles/' . $role, [...self::draft(), 'version' => 2]));
    }

    public function testSaveWithoutDifferenceKeepsVersion(): void
    {
        $role = $this->role('Gerätewart', ['search' => 'read', 'due' => 'write'], ['book.move']);

        $saved = self::json($this->post('tenants/' . $this->tenantId . '/roles/' . $role, [
            ...self::draft(['name' => 'Gerätewart', 'areas' => ['due' => 'write', 'search' => 'read'], 'actions' => ['book.move']]),
            'version' => 1,
        ]))['role'];

        self::assertSame(1, $saved['version']);
    }

    public function testSaveReplacesRightsAndFilters(): void
    {
        $hlf = $this->createLocation($this->tenantId);
        $lf = $this->createLocation($this->tenantId);
        $role = $this->role('Gerätewart', ['search' => 'read'], ['book.move'], [$hlf], null);

        $saved = self::json($this->post('tenants/' . $this->tenantId . '/roles/' . $role, [
            ...self::draft(['name' => 'Gerätewart', 'areas' => ['due' => 'read'], 'actions' => ['mail.log'], 'locations' => [$lf]]),
            'version' => 1,
        ]))['role'];

        self::assertSame(['due' => 'read'], $saved['areas']);
        self::assertSame(['mail.log'], $saved['actions']);
        self::assertSame([$lf], $saved['locations']);
        self::assertSame([[$lf]], $this->pdo()->query('SELECT location_id FROM fe_role_locations WHERE role_id = ' . $role)->fetchAll(\PDO::FETCH_NUM));

        $unfiltered = self::json($this->post('tenants/' . $this->tenantId . '/roles/' . $role, [...self::draft(['name' => 'Gerätewart']), 'version' => 2]))['role'];
        self::assertNull($unfiltered['locations']);
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM fe_role_locations WHERE role_id = ' . $role)->fetchColumn());
    }

    /**
     * Jede Änderung ändert permissions.version der Konten; die Sitzungen bleiben (F1), die neuen
     * Rechte gelten ab der nächsten Anfrage.
     */
    public function testSaveChangesPermissionsOfAccountsWithoutEndingSessions(): void
    {
        $role = $this->role('Geräteträger', ['search' => 'read'], ['book.move']);
        $this->account('immendingen', 'traeger@example.org', ['role_id' => $role]);
        $session = $this->loginCookie('immendingen', 'traeger@example.org');
        $before = self::json($this->send('GET', self::url('immendingen', 'auth/me'), null, $session))['permissions'];

        $this->post('tenants/' . $this->tenantId . '/roles/' . $role, [...self::draft(['name' => 'Geräteträger', 'areas' => ['search' => 'read', 'due' => 'read'], 'actions' => ['book.move']]), 'version' => 1]);

        $me = $this->send('GET', self::url('immendingen', 'auth/me'), null, $session);
        self::assertSame(200, $me->statusCode(), 'Sitzung bleibt');
        $after = self::json($me)['permissions'];
        self::assertNotSame($before['version'], $after['version']);
        self::assertSame(['search' => 'read', 'due' => 'read'], $after['areas']);
        self::assertSame(200, $this->send('GET', self::url('immendingen', 'inspection-due'), null, $session)->statusCode());
    }

    // ---- Standard-Rollen ---------------------------------------------------------------------

    public function testAdminRoleIsProtected(): void
    {
        $admin = (int) $this->systemRoleId($this->tenantId, 'admin');
        $url = 'tenants/' . $this->tenantId . '/roles/' . $admin;

        $save = $this->post($url, [...self::draft(['name' => 'Admin']), 'version' => 1]);
        self::assertError(409, 'rule_violation', $save);
        self::assertSame('Die Rolle „Admin“ hat immer alle Rechte und lässt sich nicht ändern.', self::json($save)['message']);

        $delete = $this->send('DELETE', self::url(null, $url), [], $this->cookie);
        self::assertError(409, 'rule_violation', $delete);
        self::assertSame('Die Rolle „Admin“ lässt sich nicht löschen.', self::json($delete)['message']);
        self::assertSame(2, $this->roleCount());
    }

    public function testUserRoleIsEditableButNotDeletable(): void
    {
        $user = (int) $this->systemRoleId($this->tenantId, 'user');
        $url = 'tenants/' . $this->tenantId . '/roles/' . $user;

        $saved = $this->post($url, [...self::draft(['name' => 'Mitglied', 'areas' => ['search' => 'read'], 'actions' => ['book.move']]), 'version' => 1]);
        self::assertSame(200, $saved->statusCode(), $saved->body());
        self::assertSame('user', self::json($saved)['role']['system_key']);

        $delete = $this->send('DELETE', self::url(null, $url), [], $this->cookie);
        self::assertError(409, 'rule_violation', $delete);
        self::assertSame('Die Standard-Rolle „Mitglied“ lässt sich nicht löschen; sie bekommt jede neue Registrierung ohne eigene Rolle.', self::json($delete)['message']);
    }

    // ---- Löschen -----------------------------------------------------------------------------

    public function testDeleteOnlyWithoutAccountsAndOpenCodes(): void
    {
        $admin = $this->account('immendingen', 'admin@example.org', ['role' => 'admin']);
        $role = $this->role('Gerätewart', ['search' => 'read'], ['book.move']);
        $account = $this->account('immendingen', 'wart@example.org', ['role_id' => $role]);
        $code = $this->openCode($admin, ['role_id' => $role]);
        $url = self::url(null, 'tenants/' . $this->tenantId . '/roles/' . $role);

        $response = $this->send('DELETE', $url, [], $this->cookie);
        self::assertError(409, 'rule_violation', $response);
        self::assertSame(['accounts' => 1, 'invitation_codes' => 1], self::json($response)['details']);
        self::assertSame(
            'Die Rolle „Gerätewart“ wird noch verwendet (1 Person, 1 offener Einladungscode). Löschen geht erst, wenn niemand sie hat und kein offener Einladungscode auf sie zeigt.',
            self::json($response)['message'],
        );

        $this->pdo()->exec('UPDATE fe_invitation_codes SET revoked_at = UTC_TIMESTAMP() WHERE id = ' . $code);
        self::assertSame(['accounts' => 1, 'invitation_codes' => 0], self::json($this->send('DELETE', $url, [], $this->cookie))['details']);

        // Ein gelöschtes Konto hindert nicht; es bekommt die Rolle Benutzer, der alte Code keine Rolle.
        $this->pdo()->exec("UPDATE fe_users SET status = 'deleted', email = NULL, email_verified_at = NULL, password_hash = NULL,"
            . ' name = NULL, display_name = NULL, deleted_at = UTC_TIMESTAMP() WHERE id = ' . $account);
        $response = $this->send('DELETE', $url, [], $this->cookie);

        self::assertSame(200, $response->statusCode(), $response->body());
        self::assertSame(['deleted_role_id' => $role], self::json($response));
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM fe_roles WHERE id = ' . $role)->fetchColumn());
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM fe_role_areas WHERE role_id = ' . $role)->fetchColumn());
        self::assertSame($this->systemRoleId($this->tenantId, 'user'), (int) $this->pdo()->query('SELECT role_id FROM fe_users WHERE id = ' . $account)->fetchColumn());
        self::assertNull($this->pdo()->query('SELECT role_id FROM fe_invitation_codes WHERE id = ' . $code)->fetchColumn());
        self::assertError(404, 'not_found', $this->send('DELETE', $url, [], $this->cookie));
        self::assertError(400, 'invalid_request', $this->send('DELETE', $url, ['force' => true], $this->cookie));
    }

    // ---- Kopieren und übernehmen ---------------------------------------------------------------

    public function testCopyKeepsRightsAndFilters(): void
    {
        $hlf = $this->createLocation($this->tenantId);
        $role = $this->role('Gerätewart', ['search' => 'read', 'devices' => 'write'], ['device.edit'], [$hlf], null);

        $copy = $this->post('tenants/' . $this->tenantId . '/roles', ['copy_of' => $role]);

        self::assertSame(201, $copy->statusCode(), $copy->body());
        $json = self::json($copy)['role'];
        self::assertSame('Gerätewart (Kopie)', $json['name']);
        self::assertSame(['search' => 'read', 'devices' => 'write'], $json['areas']);
        self::assertSame(['device.edit'], $json['actions']);
        self::assertSame([$hlf], $json['locations']);
        self::assertSame('Gerätewart (Kopie 2)', self::json($this->post('tenants/' . $this->tenantId . '/roles', ['copy_of' => $role]))['role']['name']);
        self::assertSame('Gerätewart (Kopie 3)', self::json($this->post('tenants/' . $this->tenantId . '/roles', ['copy_of' => $role]))['role']['name']);
        self::assertSame('Wart', self::json($this->post('tenants/' . $this->tenantId . '/roles', ['copy_of' => $role, 'name' => ' Wart ']))['role']['name']);

        self::assertError(409, 'name_taken', $this->post('tenants/' . $this->tenantId . '/roles', ['copy_of' => $role, 'name' => 'admin']));
        self::assertError(400, 'invalid_request', $this->post('tenants/' . $this->tenantId . '/roles', ['copy_of' => 999999]));
        self::assertError(400, 'invalid_request', $this->post('tenants/' . $this->tenant('tuttlingen')->id . '/roles', ['copy_of' => $role]), 'Rolle einer anderen Stadt');
        self::assertError(400, 'invalid_request', $this->post('tenants/' . $this->tenantId . '/roles', ['copy_of' => $role, 'areas' => []]));
    }

    /** Vorgabe Holger 05.11.2026: höchstens 50 Zeichen, gekürzt wird der Name der Vorlage. */
    public function testCopyNameIsShortenedToFiftyCharacters(): void
    {
        $long = mb_substr(str_repeat('Gerätewart ', 5), 0, RoleDraft::MAX_NAME_LENGTH);
        $role = $this->role($long, ['search' => 'read'], []);

        $names = [];
        for ($i = 0; $i < 3; ++$i) {
            $copy = $this->post('tenants/' . $this->tenantId . '/roles', ['copy_of' => $role]);
            self::assertSame(201, $copy->statusCode(), $copy->body());
            $names[] = self::json($copy)['role']['name'];
        }

        self::assertSame([
            'Gerätewart Gerätewart Gerätewart Gerätewar (Kopie)',
            'Gerätewart Gerätewart Gerätewart Gerätew (Kopie 2)',
            'Gerätewart Gerätewart Gerätewart Gerätew (Kopie 3)',
        ], $names);
        foreach ($names as $name) {
            self::assertSame(RoleDraft::MAX_NAME_LENGTH, mb_strlen($name));
        }
        // Kein Leerzeichen vor der Klammer, wenn die Kürzung auf ein Wortende fällt.
        $short = $this->role('Gerätewart Gerätewart Gerätewart Leitungs Nord', ['search' => 'read'], []);
        self::assertSame(
            'Gerätewart Gerätewart Gerätewart Leitungs (Kopie)',
            self::json($this->post('tenants/' . $this->tenantId . '/roles', ['copy_of' => $short]))['role']['name'],
        );
    }

    public function testCopyOfAdminIsAnOrdinaryRoleWithAllRights(): void
    {
        $copy = self::json($this->post('tenants/' . $this->tenantId . '/roles', ['copy_of' => $this->systemRoleId($this->tenantId, 'admin')]))['role'];

        self::assertSame('Admin (Kopie)', $copy['name']);
        self::assertNull($copy['system_key']);
        self::assertFalse($copy['all_rights']);
        self::assertTrue($copy['editable']);
        self::assertNull($copy['description']);
        self::assertSame(['read'], array_values(array_unique([$copy['areas']['dashboard'], $copy['areas']['search']])));
        self::assertSame('write', $copy['areas']['users']);
        self::assertCount(9, $copy['areas']);
        self::assertCount(10, $copy['actions']);
    }

    /** F10: Übernahme aus einer anderen Stadt mit den Rechten, ohne Datenfilter. */
    public function testImportFromOtherTenantTakesRightsWithoutFilters(): void
    {
        $tuttlingen = $this->tenant('tuttlingen');
        $location = $this->createLocation($tuttlingen->id);
        $source = new RoleRepository($this->pdo())->create(
            $tuttlingen,
            RoleDraft::of('Geräteträger', 'Handy', ['search' => AreaLevel::Read], [Action::BookMove], [$location], null),
            $this->chef,
        )->id;

        $response = $this->post('tenants/' . $this->tenantId . '/roles/import', ['source_role_id' => $source]);

        self::assertSame(201, $response->statusCode(), $response->body());
        $role = self::json($response)['role'];
        self::assertSame('Geräteträger', $role['name']);
        self::assertSame('Handy', $role['description']);
        self::assertSame(['search' => 'read'], $role['areas']);
        self::assertSame(['book.move'], $role['actions']);
        self::assertNull($role['locations']);
        self::assertNull($role['device_types']);
        self::assertSame('Geräteträger (2)', self::json($this->post('tenants/' . $this->tenantId . '/roles/import', ['source_role_id' => $source]))['role']['name']);

        self::assertError(400, 'invalid_request', $this->post('tenants/' . $tuttlingen->id . '/roles/import', ['source_role_id' => $source]), 'dieselbe Stadt');
        self::assertError(400, 'invalid_request', $this->post('tenants/' . $this->tenantId . '/roles/import', ['source_role_id' => 999999]));
        self::assertError(400, 'invalid_request', $this->post('tenants/' . $this->tenantId . '/roles/import', ['source_role_id' => $source, 'locations' => null]));
        self::assertError(404, 'not_found', $this->post('tenants/999999/roles/import', ['source_role_id' => $source]));
    }

    // ---- Auswahl für den Datenfilter -----------------------------------------------------------

    public function testFilterOptionsListAllLocationsAndTypes(): void
    {
        $hlf = $this->createLocation($this->tenantId, ['name' => 'HLF 20']);
        $fach = $this->createSubLocation($this->tenantId, $hlf, ['name' => 'G1', 'active' => 0]);
        $lager = $this->createLocation($this->tenantId, ['name' => 'Lager', 'kind' => 'room', 'active' => 0]);
        $type = $this->createDeviceType($this->tenantId, ['name' => 'Funkgerät', 'active' => 0]);
        $this->createLocation($this->tenant('tuttlingen')->id);

        $options = self::json($this->get('tenants/' . $this->tenantId . '/role-filter-options'));

        self::assertSame([
            ['id' => $hlf, 'name' => 'HLF 20', 'kind' => 'vehicle', 'kind_label' => 'Fahrzeug', 'active' => true, 'children' => [
                ['id' => $fach, 'name' => 'G1', 'kind' => 'vehicle', 'kind_label' => 'Fahrzeug', 'active' => false],
            ]],
            ['id' => $lager, 'name' => 'Lager', 'kind' => 'room', 'kind_label' => 'Raum/Lager', 'active' => false, 'children' => []],
        ], $options['locations']);
        self::assertContains(['id' => $type, 'name' => 'Funkgerät', 'active' => false], $options['device_types']);
    }

    // ---- Hilfen ------------------------------------------------------------------------------

    /**
     * Ganzer Stand einer Rolle; Standard: „Live-Test“ ohne Rechte, ohne Filter.
     *
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private static function draft(array $fields = []): array
    {
        return [...['name' => 'Live-Test', 'description' => null, 'areas' => [], 'actions' => [], 'locations' => null, 'device_types' => null], ...$fields];
    }

    /**
     * @param array<string, 'read'|'write'> $areas
     * @param list<string>                  $actions
     * @param list<int>|null                $locations
     * @param list<int>|null                $deviceTypes
     */
    private function role(string $name, array $areas, array $actions, ?array $locations = null, ?array $deviceTypes = null): int
    {
        return new RoleRepository($this->pdo())->create(
            $this->tenant('immendingen'),
            RoleDraft::of(
                $name,
                null,
                array_map(AreaLevel::from(...), $areas),
                array_map(Action::from(...), $actions),
                $locations,
                $deviceTypes,
            ),
            $this->chef,
        )->id;
    }

    private function deletedAccount(string $slug, ?int $roleId): int
    {
        $values = ['status' => 'deleted', 'email' => null, 'email_verified_at' => null, 'password_hash' => null,
            'name' => null, 'display_name' => null, 'deleted_at' => self::CREATED_AT];

        return $this->createUser($this->tenant($slug)->id, $roleId === null ? $values : [...$values, 'role_id' => $roleId]);
    }

    /**
     * Nutzbarer Einladungscode (gültig 30 Tage ab jetzt).
     *
     * @param array<string, scalar|null> $overrides
     */
    private function openCode(int $createdBy, array $overrides = []): int
    {
        return $this->createInvitationCode($this->tenantId, $createdBy, [
            'created_at' => gmdate('Y-m-d H:i:s', time() - 60),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 30 * 86400),
            ...$overrides,
        ]);
    }

    private function roleCount(): int
    {
        return (int) $this->pdo()->query('SELECT COUNT(*) FROM fe_roles WHERE tenant_id = ' . $this->tenantId)->fetchColumn();
    }

    private function get(string $path): JsonResponse
    {
        return $this->send('GET', self::url(null, $path), null, $this->cookie);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $path, array $body): JsonResponse
    {
        return $this->send('POST', self::url(null, $path), $body, $this->cookie);
    }
}
