<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Device;

use FirefightingEquipment\Api\V1\Returns\PublicReturnHandler;
use FirefightingEquipment\Api\V1\Returns\ReturnPointHandler;
use FirefightingEquipment\Api\V1\Returns\ReturnProcessHandler;
use FirefightingEquipment\Auth\PublicReturnThrottle;
use FirefightingEquipment\Device\Loan\ReturnKey;
use FirefightingEquipment\Device\Loan\ReturnPointRepository;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Rückgabe ohne Anmeldung und „Rückgaben bearbeiten“ (Phase 2, P2.6). Rechte der Admin-Routen:
 * TenantRoutePermissionsTest.
 */
#[CoversClass(PublicReturnHandler::class)]
#[CoversClass(ReturnPointHandler::class)]
#[CoversClass(ReturnProcessHandler::class)]
#[CoversClass(ReturnPointRepository::class)]
#[CoversClass(ReturnKey::class)]
#[CoversClass(PublicReturnThrottle::class)]
final class ReturnTest extends DeviceApiTestCase
{
    use FilteredRoles;

    private int $nachbar;
    private int $returnPoint;
    private int $returnBox;
    private int $collection;
    private int $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->nachbar = $this->createLocation($this->tenantId, ['name' => 'FF Nachbar', 'kind' => 'external']);
        $this->returnPoint = $this->createLocation($this->tenantId, ['name' => 'Rückgabe', 'kind' => 'room']);
        $this->returnBox = $this->createSubLocation($this->tenantId, $this->returnPoint, ['name' => 'Kiste', 'kind' => 'room']);
        $this->collection = $this->createLocation($this->tenantId, ['name' => 'Sammelstelle', 'kind' => 'room']);
        $this->store = $this->createLocation($this->tenantId, ['name' => 'Hauptlager', 'kind' => 'room']);
        foreach (['borrower', 'returns', 'collection', 'store'] as $task) {
            $this->insert('fe_tenant_location_tasks', ['tenant_id' => $this->tenantId, 'task' => $task, 'enabled' => 1]);
        }
        $update = $this->pdo()->prepare('UPDATE fe_locations SET task = ?, collection_accepts = ?, is_default_store = ? WHERE id = ?');
        $update->execute(['borrower', null, 0, $this->nachbar]);
        $update->execute(['returns', null, 0, $this->returnPoint]);
        $update->execute(['collection', 'both', 0, $this->collection]);
        $update->execute(['store', null, 1, $this->store]);
    }

    /** @return array<string, mixed> die Ausrüstung (verliehen) */
    private function lend(string $identifier, array $fields = []): array
    {
        $device = $this->createViaApi($identifier, $fields);
        $lend = $this->api('POST', 'loans', ['devices' => [['id' => $device['id'], 'version' => $device['version']]], 'borrower_location_id' => $this->nachbar]);
        self::assertSame(200, $lend->statusCode(), $lend->body());

        return $device;
    }

    /** @return array{0: array<string, mixed>, 1: string} Ausrüstung (verliehen) und gültiger Schlüssel */
    private function lentDevice(string $identifier = 'HRT 16', array $fields = []): array
    {
        return [$this->lend($identifier, $fields), $this->renewKey()];
    }

    private function renewKey(): string
    {
        $response = $this->api('POST', 'return-points/' . $this->returnPoint . '/renew', []);
        self::assertSame(200, $response->statusCode(), $response->body());

        return self::json($response)['key'];
    }

    /** Ohne Cookie: wie jemand, der den QR-Code des Aushangs scannt. */
    private function publicCall(string $path, array $body)
    {
        return $this->send('POST', self::url('immendingen', $path), $body, '');
    }

    public function testRenewIssuesAKeyThatIsStoredOnlyAsHash(): void
    {
        $key = $this->renewKey();

        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $key);
        $stored = (string) $this->pdo()->query('SELECT secret_hash FROM fe_return_points')->fetchColumn();
        self::assertSame(32, strlen($stored));
        self::assertNotSame($key, $stored);
        self::assertSame(hash('sha256', $key, true), $stored);
        $list = self::json($this->api('GET', 'loans'));
        self::assertSame($this->returnPoint, $list['returns'][0]['id']);
        self::assertNotNull($list['returns'][0]['link_created_at']);
    }

    public function testRenewInvalidatesTheOldKeyAndRevokeTheNewOne(): void
    {
        $old = $this->renewKey();
        [$device] = $this->lentDevice();
        $new = $this->renewKey();

        self::assertSame(400, $this->publicCall('returns/check', ['key' => $old, 'code' => 'HRT 16'])->statusCode());
        self::assertSame(200, $this->publicCall('returns/check', ['key' => $new, 'code' => 'HRT 16'])->statusCode());
        self::assertSame(200, $this->api('POST', 'return-points/' . $this->returnPoint . '/revoke', [])->statusCode());
        self::assertSame(200, $this->api('POST', 'return-points/' . $this->returnPoint . '/revoke', [])->statusCode(), 'wiederholbar');
        self::assertSame(400, $this->publicCall('returns/check', ['key' => $new, 'code' => 'HRT 16'])->statusCode());
        self::assertNull(self::json($this->api('GET', 'loans'))['returns'][0]['link_created_at']);
        self::assertSame($device['id'], self::json($this->api('GET', 'loans'))['loans'][0]['device']['id']);
    }

    public function testOnlyReturnLocationsGetAKeyAndOnlyWithRights(): void
    {
        self::assertSame(409, $this->api('POST', 'return-points/' . $this->nachbar . '/renew', [])->statusCode());
        self::assertSame(404, $this->api('POST', 'return-points/999999/renew', [])->statusCode());
        self::assertSame(403, $this->api('POST', 'return-points/' . $this->returnPoint . '/renew', [], $this->user)->statusCode());
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM fe_return_points')->fetchColumn());
    }

    public function testCheckNamesOnlyNumberAndType(): void
    {
        [, $key] = $this->lentDevice('HRT 16', ['scan_code' => '0104006381333931']);

        foreach (['HRT 16', 'hrt  16', "  HRT 16\n", '0104006381333931'] as $code) {
            $response = $this->publicCall('returns/check', ['key' => $key, 'code' => $code]);
            self::assertSame(200, $response->statusCode(), $code . ': ' . $response->body());
            self::assertSame(['identifier' => 'HRT 16', 'device_type' => 'Atemschutzmaske', 'location' => 'Rückgabe'], self::json($response), $code);
        }
    }

    public function testCheckRejectsWrongKeyUnknownCodeAndEquipmentThatIsNotLent(): void
    {
        [, $key] = $this->lentDevice();
        $this->createViaApi('HRT 20');

        foreach ([
            ['key' => 'zu-kurz', 'code' => 'HRT 16'],
            ['key' => str_repeat('A', 43), 'code' => 'HRT 16'],
            ['key' => $key, 'code' => 'HRT 99'],
            ['key' => $key, 'code' => 'HRT 20'],
            ['key' => $key, 'code' => ''],
        ] as $body) {
            self::assertSame(400, $this->publicCall('returns/check', $body)->statusCode(), json_encode($body, JSON_THROW_ON_ERROR));
        }
        self::assertSame(400, $this->publicCall('returns/check', ['key' => $key, 'code' => 'HRT 16', 'name' => 'x'])->statusCode(), 'unbekanntes Feld');
    }

    public function testReturnBooksIntoTheReturnPointWithoutAnAccount(): void
    {
        [$device, $key] = $this->lentDevice();

        $response = $this->publicCall('returns', ['key' => $key, 'code' => 'HRT 16', 'name' => 'Herr Maier', 'condition' => 'ok', 'comment' => 'Danke']);

        self::assertSame(200, $response->statusCode(), $response->body());
        $body = self::json($response);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $body['returned_at']);
        unset($body['returned_at']);
        self::assertSame(['identifier' => 'HRT 16', 'device_type' => 'Atemschutzmaske', 'location' => 'Rückgabe', 'status_label' => 'einsatzbereit'], $body);
        $now = self::json($this->api('GET', 'devices/' . $device['id']))['device'];
        self::assertSame([$this->returnPoint, 'ready', 3], [$now['location']['id'], $now['status'], $now['version']]);
        self::assertSame(['open' => 0, 'overdue' => 0], self::json($this->api('GET', 'loans'))['summary']);
        self::assertNotNull($this->pdo()->query('SELECT returned_at FROM fe_device_loans')->fetchColumn());

        $events = self::json($this->api('GET', 'devices/' . $device['id'] . '/events'))['events'];
        self::assertSame(['Rückgabe', null, 'Herr Maier (ohne Anmeldung)', 'Danke'], [$events[0]['type_label'], $events[0]['user']['id'], $events[0]['user']['name'], $events[0]['comment']]);
        $row = $this->events($device['id'])[2];
        self::assertSame([null, 'Herr Maier', 'state_changed'], [$row['user_id'], $row['actor_name'], $row['event_type']]);
    }

    public function testConditionSetsTheStatus(): void
    {
        $a = $this->lend('A-1');
        $b = $this->lend('B-2');
        $c = $this->lend('C-3', ['status' => 'maintenance']);
        $key = $this->renewKey();

        $this->publicCall('returns', ['key' => $key, 'code' => 'A-1', 'name' => 'Frau Weber', 'condition' => 'used']);
        $this->publicCall('returns', ['key' => $key, 'code' => 'B-2', 'name' => 'Frau Weber', 'condition' => 'defective']);
        $this->publicCall('returns', ['key' => $key, 'code' => 'C-3', 'name' => 'Frau Weber']);

        $status = fn (array $d): string => self::json($this->api('GET', 'devices/' . $d['id']))['device']['status'];
        self::assertSame(['used', 'defective', 'maintenance'], [$status($a), $status($b), $status($c)]);
    }

    public function testReturnRejectsBadInputWithoutChangingAnything(): void
    {
        [, $key] = $this->lentDevice();
        $before = $this->deviceSnapshot();

        foreach ([
            ['key' => $key, 'code' => 'HRT 16'],
            ['key' => $key, 'code' => 'HRT 16', 'name' => ''],
            ['key' => $key, 'code' => 'HRT 16', 'name' => 'Herr Maier', 'condition' => 'kaputt'],
            ['key' => $key, 'code' => 'HRT 16', 'name' => 'Herr Maier', 'status' => 'ready'],
            ['key' => $key, 'code' => 'HRT 16', 'name' => 'Herr Maier', 'comment' => str_repeat('x', 501)],
        ] as $body) {
            self::assertSame(400, $this->publicCall('returns', $body)->statusCode(), json_encode($body, JSON_THROW_ON_ERROR));
        }
        self::assertSame($before, $this->deviceSnapshot());
    }

    public function testReturnTwiceIsRejectedTheSecondTime(): void
    {
        [, $key] = $this->lentDevice();
        $body = ['key' => $key, 'code' => 'HRT 16', 'name' => 'Herr Maier'];

        self::assertSame(200, $this->publicCall('returns', $body)->statusCode());
        self::assertSame(400, $this->publicCall('returns', $body)->statusCode());
    }

    public function testRepeatedNumberSaysAlreadyReturnedWithTimeButNoName(): void
    {
        [, $key] = $this->lentDevice();
        $this->createViaApi('HRT 20');
        $first = self::json($this->publicCall('returns', ['key' => $key, 'code' => 'HRT 16', 'name' => 'Herr Maier']));

        foreach (['returns/check' => [], 'returns' => ['name' => 'Herr Maier']] as $path => $extra) {
            $response = $this->publicCall($path, ['key' => $key, 'code' => 'HRT16'] + $extra);
            self::assertSame(400, $response->statusCode());
            $json = self::json($response);
            self::assertSame('HRT 16 wurde bereits zurückgegeben. Sie müssen nichts weiter tun.', $json['message']);
            self::assertSame(['identifier' => 'HRT 16', 'returned_at' => $first['returned_at']], $json['details']);
            self::assertStringNotContainsString('Maier', $response->body());
        }
        // Nie verliehen oder unbekannt: gleiche Meldung, keine Auskunft über das Gerät.
        foreach (['HRT 20', 'HRT 99'] as $code) {
            $json = self::json($this->publicCall('returns/check', ['key' => $key, 'code' => $code]));
            self::assertSame('Diese Nummer kennen wir nicht oder die Ausrüstung ist nicht verliehen. Bitte schreiben Sie sie so wie auf dem Etikett, z. B. HRT 14.', $json['message'], $code);
            self::assertArrayNotHasKey('details', $json);
        }
    }

    public function testKeyOfAnotherCityDoesNotWork(): void
    {
        [, $key] = $this->lentDevice();

        $response = $this->send('POST', self::url('hattingen', 'returns/check'), ['key' => $key, 'code' => 'HRT 16'], '');

        self::assertContains($response->statusCode(), [400, 404]);
    }

    public function testManyWrongAttemptsAreThrottled(): void
    {
        [, $key] = $this->lentDevice();
        for ($i = 0; $i < PublicReturnThrottle::MAX_FAILURES_PER_IP; ++$i) {
            self::assertSame(400, $this->publicCall('returns/check', ['key' => $key, 'code' => 'X' . $i])->statusCode());
        }

        $blocked = $this->publicCall('returns/check', ['key' => $key, 'code' => 'HRT 16']);

        self::assertSame(429, $blocked->statusCode(), $blocked->body());
        self::assertNotNull($blocked->headers()['Retry-After'] ?? null);
    }

    public function testListShowsEquipmentInTheReturn(): void
    {
        [$a, $key] = $this->lentDevice('A-1');
        $this->publicCall('returns', ['key' => $key, 'code' => 'A-1', 'name' => 'Herr Maier', 'condition' => 'defective']);

        $list = self::json($this->api('GET', 'loans'));

        self::assertSame([$a['id']], array_column($list['in_returns'], 'id'));
    }

    public function testProcessSendsUsedAndDefectiveToTheCollectionPointAndTheRestToTheStore(): void
    {
        $a = $this->lend('A-1');
        $b = $this->lend('B-2');
        $c = $this->lend('C-3');
        $key = $this->renewKey();
        $this->publicCall('returns', ['key' => $key, 'code' => 'A-1', 'name' => 'X Y', 'condition' => 'defective']);
        $this->publicCall('returns', ['key' => $key, 'code' => 'B-2', 'name' => 'X Y', 'condition' => 'used']);
        $this->publicCall('returns', ['key' => $key, 'code' => 'C-3', 'name' => 'X Y']);
        $current = static fn (self $t, array $d): array => ['id' => $d['id'], 'version' => self::json($t->api('GET', 'devices/' . $d['id']))['device']['version']];

        $collect = self::json($this->api('POST', 'loans/to-collection', ['devices' => [$current($this, $a), $current($this, $b), $current($this, $c)]]));

        self::assertSame(['applied', 'applied', 'error'], array_column($collect['results'], 'outcome'));
        self::assertStringContainsString('gehört ins Lager', $collect['results'][2]['error']['message']);
        $place = fn (array $d): int => self::json($this->api('GET', 'devices/' . $d['id']))['device']['location']['id'];
        self::assertSame([$this->collection, $this->collection, $this->returnPoint], [$place($a), $place($b), $place($c)]);
        self::assertSame('defective', self::json($this->api('GET', 'devices/' . $a['id']))['device']['status']);

        $store = self::json($this->api('POST', 'loans/to-store', ['devices' => [$current($this, $c)]]));
        self::assertSame(1, $store['summary']['applied']);
        self::assertSame([$this->store, 'ready'], [$place($c), self::json($this->api('GET', 'devices/' . $c['id']))['device']['status']]);
    }

    public function testProcessRefusesEquipmentOutsideTheReturnAndMissingTargets(): void
    {
        $outside = $this->createViaApi('Z-9');

        $body = self::json($this->api('POST', 'loans/to-store', ['devices' => [['id' => $outside['id'], 'version' => 1]]]));

        self::assertSame('rule_violation', $body['results'][0]['error']['error']);
        self::assertStringContainsString('nicht in der Rückgabe', $body['results'][0]['error']['message']);
        $this->pdo()->prepare('UPDATE fe_locations SET is_default_store = 0 WHERE id = ?')->execute([$this->store]);
        self::assertSame(409, $this->api('POST', 'loans/to-store', ['devices' => [['id' => $outside['id'], 'version' => 1]]])->statusCode());
    }

    public function testProcessNeedsTheRightAndNoDataFilter(): void
    {
        self::assertSame(403, $this->api('POST', 'loans/to-store', ['devices' => []], $this->user)->statusCode());
    }
}
