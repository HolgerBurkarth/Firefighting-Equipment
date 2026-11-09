<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Device;

use FirefightingEquipment\Api\V1\Devices\DeviceSwapHandler;
use FirefightingEquipment\Device\Stock\StockGuard;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Sollbestand bei Buchungen und Tausch (Phase 2, P2.8): Eine Buchung, die eine Regel unterschreiten würde, wird
 * abgelehnt (409 rule_violation, details.stock); mit der Aktion stock.override geht sie nach Rückfrage
 * (confirmed_below_minimum) durch und hinterlässt einen Hinweis. POST devices/swap tauscht atomar. Rechte der
 * Routen: TenantRoutePermissionsTest.
 */
#[CoversClass(DeviceSwapHandler::class)]
#[CoversClass(StockGuard::class)]
final class StockSwapTest extends DeviceApiTestCase
{
    use FilteredRoles;

    private int $radio;
    private int $collection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->radio = (int) self::json($this->api('POST', 'device-types', ['name' => 'Funkgerät']))['device_type']['id'];
        $this->collection = $this->createLocation($this->tenantId, ['name' => 'Kiste', 'kind' => 'room']);
    }

    /** Regel am HLF: mindestens zwei Atemschutzmasken. */
    private function ruleOfTwo(): void
    {
        $response = $this->api('POST', 'locations/' . $this->hlf . '/stock-rules', ['rules' => [['min_count' => 2, 'device_type_ids' => [$this->typeId]]]]);
        self::assertSame(200, $response->statusCode(), $response->body());
    }

    private function collectionPoint(): void
    {
        $this->insert('fe_tenant_location_tasks', ['tenant_id' => $this->tenantId, 'task' => 'collection', 'enabled' => 1]);
        $this->pdo()->prepare("UPDATE fe_locations SET task = 'collection', collection_accepts = 'both' WHERE id = ?")->execute([$this->collection]);
    }

    private function swapper(): string
    {
        $this->filteredAccount('swap@example.org', ['filter_locations' => 0, 'filter_device_types' => 0], [], [], [], ['device.swap']);

        return $this->loginCookie('immendingen', 'swap@example.org');
    }

    /** @return array<string, mixed> */
    private static function details(\FirefightingEquipment\Http\Response $response): array
    {
        return (array) (self::json($response)['details'] ?? []);
    }

    public function testBookingBelowTheMinimumIsRejectedAndNothingChanges(): void
    {
        $a = $this->createViaApi('M1', ['location_id' => $this->hlf]);
        $this->createViaApi('M2', ['location_id' => $this->hlf]);
        $this->ruleOfTwo();

        $response = $this->api('POST', 'devices/' . $a['id'] . '/state', ['version' => $a['version'], 'location_id' => $this->room]);

        self::assertSame(409, $response->statusCode(), $response->body());
        self::assertSame('rule_violation', self::json($response)['error']);
        $stock = self::details($response)['stock'];
        self::assertSame([1, 2, 1, true], [$stock['have'], $stock['rule']['min_count'], $stock['missing'], $stock['can_override']], 'der Admin hat stock.override: die App darf fragen');
        self::assertSame($this->hlf, $stock['location']['id']);
        self::assertSame($this->hlf, self::json($this->api('GET', 'devices/' . $a['id']))['device']['location']['id']);
    }

    public function testStatusChangeToDefectiveCountsToo(): void
    {
        $a = $this->createViaApi('M1', ['location_id' => $this->hlf]);
        $this->createViaApi('M2', ['location_id' => $this->hlf]);
        $this->ruleOfTwo();

        $response = $this->api('POST', 'devices/' . $a['id'] . '/state', ['version' => $a['version'], 'status' => 'defective', 'comment' => 'Maske gerissen']);

        self::assertSame(409, $response->statusCode());
    }

    public function testWithoutOverrideRightTheConfirmationDoesNotHelp(): void
    {
        $a = $this->createViaApi('M1', ['location_id' => $this->hlf]);
        $this->createViaApi('M2', ['location_id' => $this->hlf]);
        $this->ruleOfTwo();

        $response = $this->api('POST', 'devices/' . $a['id'] . '/state', ['version' => $a['version'], 'location_id' => $this->room, 'confirmed_below_minimum' => true], $this->user);

        self::assertSame(409, $response->statusCode(), $response->body());
        self::assertFalse(self::details($response)['stock']['can_override']);
    }

    public function testOverrideBooksAfterConfirmationAndLeavesANotice(): void
    {
        $a = $this->createViaApi('M1', ['location_id' => $this->hlf]);
        $this->createViaApi('M2', ['location_id' => $this->hlf]);
        $this->ruleOfTwo();

        $response = $this->api('POST', 'devices/' . $a['id'] . '/state', [
            'version' => $a['version'],
            'location_id' => $this->room,
            'confirmed_below_minimum' => true,
            'client_ref' => 'a0a0a0a0-0000-4000-8000-000000000001',
        ]);

        self::assertSame(200, $response->statusCode(), $response->body());
        self::assertSame($this->room, self::json($response)['device']['location']['id']);
        self::assertNotNull(self::json($response)['result']['notice_id']);
        self::assertSame(['stock_below_minimum', 'open'], array_values((array) $this->pdo()->query('SELECT kind, status FROM fe_device_notices')->fetch(\PDO::FETCH_NUM)));
    }

    public function testBookingsThatDoNotReduceTheStockAreAlwaysAllowed(): void
    {
        $in = $this->createViaApi('M1', ['location_id' => $this->room]);
        $broken = $this->createViaApi('M2', ['location_id' => $this->hlf, 'status' => 'defective']);
        $radio = $this->createViaApi('F1', ['location_id' => $this->hlf, 'device_type_id' => $this->radio]);
        $this->createViaApi('M3', ['location_id' => $this->hlf]);
        $this->ruleOfTwo();

        foreach ([
            [$in, ['location_id' => $this->hlf]],
            [$broken, ['location_id' => $this->room]],
            [$radio, ['location_id' => $this->room]],
        ] as [$device, $body]) {
            $response = $this->api('POST', 'devices/' . $device['id'] . '/state', ['version' => $device['version'], ...$body]);
            self::assertSame(200, $response->statusCode(), $response->body());
        }
    }

    public function testCompartmentsCountForTheVehicle(): void
    {
        $fach = $this->createSubLocation($this->tenantId, $this->hlf, ['name' => 'G9', 'kind' => 'vehicle']);
        $a = $this->createViaApi('M1', ['location_id' => $fach]);
        $this->createViaApi('M2', ['location_id' => $this->hlf]);
        $this->ruleOfTwo();

        // Vom Fach ins Fahrzeug: bleibt im Bereich der Regel, kein Verlust.
        self::assertSame(200, $this->api('POST', 'devices/' . $a['id'] . '/state', ['version' => $a['version'], 'location_id' => $this->hlf])->statusCode());
        $a = self::json($this->api('GET', 'devices/' . $a['id']))['device'];
        self::assertSame(409, $this->api('POST', 'devices/' . $a['id'] . '/state', ['version' => $a['version'], 'location_id' => $this->room])->statusCode());
    }

    public function testSwapMovesBothAndSharesOneId(): void
    {
        $this->collectionPoint();
        $out = $this->createViaApi('M1', ['location_id' => $this->hlf]);
        $this->createViaApi('M2', ['location_id' => $this->hlf]);
        $in = $this->createViaApi('M9', ['location_id' => $this->room]);
        $this->ruleOfTwo();

        $response = $this->api('POST', 'devices/swap', [
            'out' => ['id' => $out['id'], 'version' => $out['version']],
            'in' => ['id' => $in['id'], 'version' => $in['version']],
            'comment' => 'Maske gerissen',
        ]);

        self::assertSame(200, $response->statusCode(), $response->body());
        $body = self::json($response);
        self::assertSame(['used', 'Kiste'], [$body['out']['status'], $body['out']['location']['name']]);
        self::assertSame(['ready', $this->hlf], [$body['in']['status'], $body['in']['location']['id']]);
        self::assertSame(32, strlen($body['swap_id']));
        self::assertSame(
            [$body['swap_id'], $body['swap_id']],
            $this->pdo()->query("SELECT swap_id FROM fe_device_events WHERE swap_id IS NOT NULL ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN),
        );
        self::assertSame(2, (int) $this->pdo()->query('SELECT COUNT(DISTINCT device_id) FROM fe_device_events WHERE swap_id IS NOT NULL')->fetchColumn());

        // Der Verlauf nennt den Tausch mit der Nummer der anderen Ausrüstung (vor „Benutzt gemeldet“).
        $outEvent = self::json($this->api('GET', 'devices/' . $out['id'] . '/events'))['events'][0];
        $inEvent = self::json($this->api('GET', 'devices/' . $in['id'] . '/events'))['events'][0];
        self::assertSame(['Getauscht gegen M9', $body['swap_id']], [$outEvent['type_label'], $outEvent['swap_id']]);
        self::assertSame(['Als Ersatz für M1', $body['swap_id']], [$inEvent['type_label'], $inEvent['swap_id']]);
        $history = self::json($this->api('GET', 'device-events?limit=2'))['events'];
        self::assertSame(['Als Ersatz für M1', 'Getauscht gegen M9'], array_column($history, 'type_label'));
    }

    public function testSwapWithoutCollectionPointKeepsTheLocation(): void
    {
        $out = $this->createViaApi('M1', ['location_id' => $this->hlf]);
        $in = $this->createViaApi('M9', ['location_id' => $this->room]);

        $body = self::json($this->api('POST', 'devices/swap', [
            'out' => ['id' => $out['id'], 'version' => $out['version']],
            'in' => ['id' => $in['id'], 'version' => $in['version']],
            'out_status' => 'defective',
        ]));

        self::assertSame(['defective', $this->hlf, $this->hlf], [$body['out']['status'], $body['out']['location']['id'], $body['in']['location']['id']]);
    }

    public function testSwapRules(): void
    {
        $out = $this->createViaApi('M1', ['location_id' => $this->hlf]);
        $broken = $this->createViaApi('M8', ['location_id' => $this->room, 'status' => 'defective']);
        $in = $this->createViaApi('M9', ['location_id' => $this->room]);
        $entry = static fn (array $d, ?int $version = null): array => ['id' => $d['id'], 'version' => $version ?? $d['version']];

        $swap = fn (array $body, ?string $cookie = null) => $this->api('POST', 'devices/swap', $body, $cookie);

        self::assertSame(409, $swap(['out' => $entry($out), 'in' => $entry($broken)])->statusCode(), 'der Ersatz muss einsatzbereit sein');
        self::assertSame(400, $swap(['out' => $entry($out), 'in' => $entry($out)])->statusCode(), 'nicht gegen sich selbst');
        self::assertSame(409, $swap(['out' => $entry($out, 99), 'in' => $entry($in)])->statusCode(), 'veraltete Version');
        self::assertSame(404, $swap(['out' => $entry($out), 'in' => ['id' => 999999, 'version' => 1]])->statusCode());
        self::assertSame(400, $swap(['out' => $entry($out), 'in' => $entry($in), 'out_status' => 'ready'])->statusCode());
        self::assertSame(400, $swap(['out' => $entry($out), 'in' => $entry($in), 'x' => 1])->statusCode());
        self::assertSame(403, $swap(['out' => $entry($out), 'in' => $entry($in)], $this->user)->statusCode(), 'ohne die Aktion device.swap');
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM fe_device_events WHERE swap_id IS NOT NULL')->fetchColumn(), 'nach Fehlern wurde nichts geschrieben');

        self::assertSame(200, $swap(['out' => $entry($out), 'in' => $entry($in)], $this->swapper())->statusCode(), 'mit der Aktion');
    }

    public function testSwapIsCheckedAgainstTheMinimumAsAWhole(): void
    {
        $out = $this->createViaApi('M1', ['location_id' => $this->hlf]);
        $this->createViaApi('M2', ['location_id' => $this->hlf]);
        $wrongType = $this->createViaApi('F9', ['location_id' => $this->room, 'device_type_id' => $this->radio]);
        $right = $this->createViaApi('M9', ['location_id' => $this->room]);
        $this->ruleOfTwo();
        $entry = static fn (array $d): array => ['id' => $d['id'], 'version' => $d['version']];

        $bad = $this->api('POST', 'devices/swap', ['out' => $entry($out), 'in' => $entry($wrongType)]);
        self::assertSame(409, $bad->statusCode(), $bad->body());
        self::assertSame(1, self::details($bad)['stock']['have'], 'ein Funkgerät ersetzt keine Maske');

        self::assertSame(200, $this->api('POST', 'devices/swap', ['out' => $entry($out), 'in' => $entry($right)])->statusCode(), 'gleicher Typ: der Ersatz gleicht aus');
    }

    public function testStockIsCountedOverAllEquipmentNotJustTheVisible(): void
    {
        $filtered = $this->filteredAccount('only-room@example.org', ['filter_locations' => 1, 'filter_device_types' => 0], [$this->hlf], [], [], ['stock.override']);
        $a = $this->createViaApi('M1', ['location_id' => $this->hlf]);
        $this->createViaApi('M2', ['location_id' => $this->room]);
        $this->ruleOfTwo();
        $this->pdo()->prepare('UPDATE fe_devices SET location_id = ? WHERE identifier = ?')->execute([$this->hlf, 'M2']);
        self::assertGreaterThan(0, $filtered);

        $response = $this->api('POST', 'devices/' . $a['id'] . '/state', ['version' => $a['version'], 'status' => 'maintenance'], $this->loginCookie('immendingen', 'only-room@example.org'));

        self::assertSame(409, $response->statusCode(), 'zwei am HLF, eine fiele weg');
    }
}
