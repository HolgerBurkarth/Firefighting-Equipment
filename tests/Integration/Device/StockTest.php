<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Device;

use FirefightingEquipment\Api\V1\Stock\StockHandler;
use FirefightingEquipment\Device\Stock\StockRepository;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Sollbestand (Phase 2, P2.7): GET stock und POST locations/{id}/stock-rules. Rechte der Routen:
 * TenantRoutePermissionsTest.
 */
#[CoversClass(StockHandler::class)]
#[CoversClass(StockRepository::class)]
final class StockTest extends DeviceApiTestCase
{
    use FilteredRoles;

    private int $fach;
    private int $mask;
    private int $radio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fach = $this->createSubLocation($this->tenantId, $this->hlf, ['name' => 'G9', 'kind' => 'vehicle']);
        $this->mask = $this->typeId;
        $this->radio = (int) self::json($this->api('POST', 'device-types', ['name' => 'Funkgerät']))['device_type']['id'];
    }

    /** @param list<array{min_count: int, device_type_ids: list<int>}> $rules */
    private function setRules(int $location, array $rules): void
    {
        $response = $this->api('POST', 'locations/' . $location . '/stock-rules', ['rules' => $rules]);
        self::assertSame(200, $response->statusCode(), $response->body());
    }

    public function testNothingWithoutRules(): void
    {
        $body = self::json($this->api('GET', 'stock'));

        self::assertSame([[], ['locations' => 0, 'out_of_service' => 0]], [$body['locations'], $body['summary']]);
    }

    public function testRulesCountReadyEquipmentOfTheTypesAndTheCompartments(): void
    {
        $this->createViaApi('M1', ['location_id' => $this->hlf]);
        $this->createViaApi('M2', ['location_id' => $this->fach]);
        $this->createViaApi('M3', ['location_id' => $this->fach, 'status' => 'defective']);
        $this->createViaApi('F1', ['location_id' => $this->hlf, 'device_type_id' => $this->radio]);
        $this->createViaApi('X1', ['location_id' => $this->room]);
        $this->setRules($this->hlf, [
            ['min_count' => 3, 'device_type_ids' => [$this->mask]],
            ['min_count' => 1, 'device_type_ids' => [$this->mask, $this->radio]],
        ]);

        $body = self::json($this->api('GET', 'stock'));

        self::assertSame(['locations' => 1, 'out_of_service' => 1], $body['summary']);
        $entry = $body['locations'][0];
        self::assertSame($this->hlf, $entry['location']['id']);
        self::assertTrue($entry['out_of_service']);
        self::assertSame([2, 3], array_column($entry['rules'], 'have'), 'zwei einsatzbereite Masken (das Fach zählt mit; defekte und fremde Standorte nicht), Masken oder Funk zusammen drei');
        self::assertSame([1, 0], array_column($entry['rules'], 'missing'));
        self::assertSame(['Atemschutzmaske'], array_column($entry['rules'][0]['types'], 'name'));
        self::assertSame(2, count($entry['rules'][1]['types']));
    }

    public function testCompartmentHasItsOwnRulesAndShowsItsPath(): void
    {
        $this->createViaApi('M1', ['location_id' => $this->fach]);
        $this->setRules($this->fach, [['min_count' => 1, 'device_type_ids' => [$this->mask]]]);

        $entry = self::json($this->api('GET', 'stock'))['locations'][0];

        self::assertSame([$this->fach, 2, false], [$entry['location']['id'], $entry['location']['level'], $entry['out_of_service']]);
        self::assertStringContainsString(' / G9', $entry['location']['path']);
    }

    public function testSavingReplacesAllRulesAndReturnsTheNewState(): void
    {
        $this->setRules($this->hlf, [['min_count' => 2, 'device_type_ids' => [$this->mask]]]);
        $response = $this->api('POST', 'locations/' . $this->hlf . '/stock-rules', ['rules' => [['min_count' => 1, 'device_type_ids' => [$this->radio]]]]);

        $stock = self::json($response)['stock'];

        self::assertSame([1], array_column($stock['rules'], 'min_count'));
        self::assertTrue($stock['out_of_service']);
        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM fe_stock_rules')->fetchColumn());
        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM fe_stock_rule_types')->fetchColumn(), 'die Typen der alten Regel fallen mit');
        self::assertNull(self::json($this->api('POST', 'locations/' . $this->hlf . '/stock-rules', ['rules' => []]))['stock']);
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM fe_stock_rules')->fetchColumn());
    }

    public function testRejectsInvalidRulesWithoutChangingAnything(): void
    {
        $this->setRules($this->hlf, [['min_count' => 2, 'device_type_ids' => [$this->mask]]]);
        $foreign = $this->otherTypeId;

        foreach ([
            [['min_count' => 0, 'device_type_ids' => [$this->mask]]],
            [['min_count' => 100, 'device_type_ids' => [$this->mask]]],
            [['min_count' => 1, 'device_type_ids' => []]],
            [['min_count' => 1, 'device_type_ids' => [$this->mask, $this->mask]]],
            [['min_count' => 1, 'device_type_ids' => [$foreign]]],
            [['min_count' => 1, 'device_type_ids' => [999999]]],
            [['min_count' => '2', 'device_type_ids' => [$this->mask]]],
            [['min_count' => 1, 'device_type_ids' => [$this->mask], 'extra' => 1]],
            ['kaputt'],
        ] as $rules) {
            self::assertSame(400, $this->api('POST', 'locations/' . $this->hlf . '/stock-rules', ['rules' => $rules])->statusCode(), json_encode($rules, JSON_THROW_ON_ERROR));
        }
        self::assertSame(400, $this->api('POST', 'locations/' . $this->hlf . '/stock-rules', ['rules' => [], 'x' => 1])->statusCode());
        self::assertSame(400, $this->api('POST', 'locations/' . $this->hlf . '/stock-rules', [])->statusCode());
        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM fe_stock_rules')->fetchColumn());
        self::assertSame(404, $this->api('POST', 'locations/999999/stock-rules', ['rules' => []])->statusCode());
        self::assertSame(404, $this->api('POST', 'locations/' . $this->otherLocation . '/stock-rules', ['rules' => []])->statusCode(), 'Standort einer anderen Stadt');
    }

    public function testLockedLocationTakesNoRules(): void
    {
        $this->api('POST', 'locations/' . $this->fach . '/lock', []);

        self::assertSame(409, $this->api('POST', 'locations/' . $this->fach . '/stock-rules', ['rules' => [['min_count' => 1, 'device_type_ids' => [$this->mask]]]])->statusCode());
    }

    public function testStandardUserAndDataFilterHaveNoWriteAccess(): void
    {
        $rules = ['rules' => [['min_count' => 1, 'device_type_ids' => [$this->mask]]]];
        self::assertSame(403, $this->api('GET', 'stock', null, $this->user)->statusCode());
        self::assertSame(403, $this->api('POST', 'locations/' . $this->hlf . '/stock-rules', $rules, $this->user)->statusCode());

        $this->filteredAccount('filter@example.org', ['filter_locations' => 1, 'filter_device_types' => 0], [$this->hlf], [], ['locations' => 'write']);
        $cookie = $this->loginCookie('immendingen', 'filter@example.org');
        self::assertSame(403, $this->api('POST', 'locations/' . $this->hlf . '/stock-rules', $rules, $cookie)->statusCode(), 'F3: mit Datenfilter nur ansehen');
    }

    public function testDataFilterSeesOnlyItsOwnLocationsAndEquipment(): void
    {
        $this->createViaApi('M1', ['location_id' => $this->hlf]);
        $this->createViaApi('M2', ['location_id' => $this->room]);
        $this->setRules($this->hlf, [['min_count' => 1, 'device_type_ids' => [$this->mask]]]);
        $this->setRules($this->room, [['min_count' => 1, 'device_type_ids' => [$this->mask]]]);
        $this->filteredAccount('filter@example.org', ['filter_locations' => 1, 'filter_device_types' => 0], [$this->hlf], [], ['locations' => 'read']);

        $body = self::json($this->api('GET', 'stock', null, $this->loginCookie('immendingen', 'filter@example.org')));

        self::assertSame([$this->hlf], array_column(array_column($body['locations'], 'location'), 'id'));
        self::assertSame(1, $body['locations'][0]['rules'][0]['have']);
    }
}
