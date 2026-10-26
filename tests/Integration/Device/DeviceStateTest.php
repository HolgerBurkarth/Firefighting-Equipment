<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Device;

use FirefightingEquipment\Api\V1\Devices\DeviceActionHandler;
use FirefightingEquipment\Api\V1\Devices\DeviceFields;
use FirefightingEquipment\Device\DeviceEventWriter;
use FirefightingEquipment\Device\DeviceRepository;
use FirefightingEquipment\Device\DeviceStateChange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * POST devices/{id}/state (angemeldet, user und admin): Umbuchen und/oder Status ändern in EINEM
 * Ereignis state_changed, Version einmal erhöht. Gleicher Standort bzw. Status wird ignoriert;
 * ändert sich nichts, 200 ohne Ereignis und ohne neue Version (Version wird trotzdem geprüft).
 */
#[CoversClass(DeviceActionHandler::class)]
#[CoversClass(DeviceFields::class)]
#[CoversClass(DeviceRepository::class)]
#[CoversClass(DeviceEventWriter::class)]
#[CoversClass(DeviceStateChange::class)]
final class DeviceStateTest extends DeviceApiTestCase
{
    public function testMovesWithOneEvent(): void
    {
        $device = $this->createViaApi('MA-01');

        $response = $this->api('POST', 'devices/' . $device['id'] . '/state', ['version' => 1, 'location_id' => $this->room, 'comment' => 'Nach Einsatz']);

        self::assertSame(200, $response->statusCode(), $response->body());
        $moved = self::json($response)['device'];
        self::assertSame([$this->room, 'Lager', 'ready', 2], [$moved['location']['id'], $moved['location']['path'], $moved['status'], $moved['version']]);
        $events = $this->events($device['id']);
        self::assertCount(2, $events);
        self::assertSame(
            ['state_changed', $this->adminId, $this->g1, $this->room, null, null, 'Nach Einsatz'],
            self::state($events[1]),
        );
    }

    public function testUserMayChangeStatus(): void
    {
        $device = $this->createViaApi('MA-01');

        $response = $this->api('POST', 'devices/' . $device['id'] . '/state', ['version' => 1, 'status' => 'defective'], $this->user);

        self::assertSame(200, $response->statusCode(), $response->body());
        self::assertSame(['defective', 'defekt', 2], [self::json($response)['device']['status'], self::json($response)['device']['status_label'], self::json($response)['device']['version']]);
        self::assertSame(['state_changed', $this->userId, null, null, 'ready', 'defective', null], self::state($this->events($device['id'])[1]));
    }

    public function testMoveAndStatusInOneEvent(): void
    {
        $device = $this->createViaApi('MA-01', ['status' => 'maintenance']);

        $response = $this->api('POST', 'devices/' . $device['id'] . '/state', ['version' => 1, 'location_id' => $this->hlf, 'status' => 'ready'], $this->user);

        self::assertSame(200, $response->statusCode(), $response->body());
        self::assertSame(2, self::json($response)['device']['version']);
        $events = $this->events($device['id']);
        self::assertCount(2, $events);
        self::assertSame(['state_changed', $this->userId, $this->g1, $this->hlf, 'maintenance', 'ready', null], self::state($events[1]));
    }

    public function testUnchangedPartIsIgnored(): void
    {
        $device = $this->createViaApi('MA-01');

        $response = $this->api('POST', 'devices/' . $device['id'] . '/state', ['version' => 1, 'location_id' => $this->g1, 'status' => 'maintenance']);

        self::assertSame(200, $response->statusCode(), $response->body());
        self::assertSame(['state_changed', $this->adminId, null, null, 'ready', 'maintenance', null], self::state($this->events($device['id'])[1]));

        $moved = $this->api('POST', 'devices/' . $device['id'] . '/state', ['version' => 2, 'location_id' => $this->room, 'status' => 'maintenance']);
        self::assertSame(200, $moved->statusCode(), $moved->body());
        self::assertSame(['state_changed', $this->adminId, $this->g1, $this->room, null, null, null], self::state($this->events($device['id'])[2]));
    }

    public function testNothingChangedWritesNothingButVersionIsChecked(): void
    {
        $device = $this->createViaApi('MA-01');
        // Gesperrt, aber der aktuelle Standort: wird ohne Wechsel nicht gelesen.
        $this->pdo()->prepare('UPDATE fe_locations SET active = 0 WHERE id = ?')->execute([$this->room]);
        $before = $this->deviceSnapshot();

        $same = $this->api('POST', 'devices/' . $device['id'] . '/state', ['version' => 1, 'location_id' => $this->g1, 'status' => 'ready', 'comment' => 'x'], $this->user);
        self::assertSame(200, $same->statusCode(), $same->body());
        self::assertSame(1, self::json($same)['device']['version']);
        self::assertSame($before, $this->deviceSnapshot());

        $stale = $this->api('POST', 'devices/' . $device['id'] . '/state', ['version' => 2, 'status' => 'ready'], $this->user);
        self::assertError(409, 'version_conflict', $stale);
        self::assertSame($before, $this->deviceSnapshot());
    }

    public function testStaleVersionReturnsCurrentStateAndWritesNothing(): void
    {
        $device = $this->createViaApi('MA-01');
        $this->api('POST', 'devices/' . $device['id'] . '/state', ['version' => 1, 'status' => 'defective']);
        $before = $this->deviceSnapshot();

        $response = $this->api('POST', 'devices/' . $device['id'] . '/state', ['version' => 1, 'location_id' => $this->room], $this->user);

        self::assertError(409, 'version_conflict', $response);
        $details = self::json($response)['details']['device'];
        self::assertSame([2, 'defective', $this->g1], [$details['version'], $details['status'], $details['location']['id']]);
        self::assertSame($before, $this->deviceSnapshot());
    }

    public function testLockedTargetIsRejected(): void
    {
        $device = $this->createViaApi('MA-01');
        self::assertSame(200, $this->api('POST', 'locations/' . $this->room . '/lock', [])->statusCode());
        $before = $this->deviceSnapshot();

        $response = $this->api('POST', 'devices/' . $device['id'] . '/state', ['version' => 1, 'location_id' => $this->room, 'status' => 'defective'], $this->user);

        self::assertError(409, 'rule_violation', $response);
        self::assertSame('Der Standort „Lager“ ist gesperrt und kann keine Geräte aufnehmen.', self::json($response)['message']);
        self::assertSame($before, $this->deviceSnapshot());
    }

    public function testUnknownOrForeignTargetIsInvalid(): void
    {
        $device = $this->createViaApi('MA-01');
        $before = $this->deviceSnapshot();

        foreach ([$this->otherLocation, 999_999_999] as $location) {
            $response = $this->api('POST', 'devices/' . $device['id'] . '/state', ['version' => 1, 'location_id' => $location]);
            self::assertError(400, 'invalid_request', $response);
            self::assertSame('Der Standort wurde nicht gefunden.', self::json($response)['message']);
        }
        self::assertSame($before, $this->deviceSnapshot());
    }

    public function testRetiredDeviceIsRejected(): void
    {
        $id = $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => 'MA-01', 'status' => 'retired']);
        $before = $this->deviceSnapshot();

        $response = $this->api('POST', 'devices/' . $id . '/state', ['version' => 1, 'location_id' => $this->room], $this->user);

        self::assertError(409, 'rule_violation', $response);
        self::assertSame(
            'Das Gerät „MA-01“ ist ausgesondert; Standort und Status lassen sich erst nach der Wiederaufnahme ändern.',
            self::json($response)['message'],
        );
        self::assertSame($before, $this->deviceSnapshot());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'ohne Version' => [['status' => 'defective'], '"version"'];
        yield 'weder Standort noch Status' => [['version' => 1, 'comment' => 'x'], 'location_id'];
        yield 'beides null' => [['version' => 1, 'location_id' => null, 'status' => null], 'location_id'];
        yield 'ausgesondert' => [['version' => 1, 'status' => 'retired'], 'retire'];
        yield 'unbekannter Status' => [['version' => 1, 'status' => 'kaputt'], '"ready", "maintenance", "defective"'];
        yield 'Standort als Text' => [['version' => 1, 'location_id' => '3'], '"location_id"'];
        yield 'Stammdaten' => [['version' => 1, 'status' => 'ready', 'identifier' => 'MA-02'], '"identifier"'];
        yield 'Kommentar mehrzeilig' => [['version' => 1, 'status' => 'defective', 'comment' => "a\nb"], 'Kommentar'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidBodies')]
    public function testInvalidBodyIsRejected(array $body, string $message): void
    {
        $device = $this->createViaApi('MA-01');
        $before = $this->deviceSnapshot();

        $response = $this->api('POST', 'devices/' . $device['id'] . '/state', $body, $this->user);

        self::assertError(400, 'invalid_request', $response);
        self::assertStringContainsString($message, self::json($response)['message']);
        self::assertSame($before, $this->deviceSnapshot());
    }

    public function testFieldsAreCheckedBeforeDeviceIsLookedUp(): void
    {
        self::assertError(400, 'invalid_request', $this->api('POST', 'devices/999999999/state', ['version' => 1]));
        self::assertError(404, 'not_found', $this->api('POST', 'devices/999999999/state', ['version' => 1, 'status' => 'ready']));
    }

    public function testDeviceOfOtherTenantIsNotFound(): void
    {
        $foreign = $this->createDevice($this->otherTenantId, $this->otherTypeId, $this->otherLocation);
        $before = $this->deviceSnapshot();

        self::assertError(404, 'not_found', $this->api('POST', 'devices/' . $foreign . '/state', ['version' => 1, 'status' => 'defective']));
        self::assertError(404, 'not_found', $this->api('POST', 'devices/' . $foreign . '/state', ['version' => 1, 'location_id' => $this->room]));
        self::assertSame($before, $this->deviceSnapshot());
    }

    public function testOnlyAccountsOfOwnTenant(): void
    {
        $device = $this->createViaApi('MA-01');
        $this->account('tuttlingen', 'admin@example.org', ['role' => 'admin']);
        $this->account(null, 'chef@example.org');
        $path = 'devices/' . $device['id'] . '/state';
        $body = ['version' => 1, 'status' => 'defective'];
        $before = $this->deviceSnapshot();

        self::assertError(401, 'not_authenticated', $this->send('POST', self::url('immendingen', $path), $body));
        self::assertError(401, 'not_authenticated', $this->api('POST', $path, $body, $this->loginCookie('tuttlingen', 'admin@example.org')));
        $superAdmin = $this->loginCookie(null, 'chef@example.org');
        self::assertError(401, 'not_authenticated', $this->api('POST', $path, $body, $superAdmin));
        self::assertError(404, 'not_found', $this->send('POST', self::url(null, $path), $body, $superAdmin));
        self::assertSame($before, $this->deviceSnapshot());

        self::assertSame(200, $this->api('POST', $path, $body, $this->user)->statusCode());
    }
}
