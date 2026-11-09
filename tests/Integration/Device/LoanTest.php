<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Device;

use FirefightingEquipment\Api\V1\Loans\LoanLendHandler;
use FirefightingEquipment\Api\V1\Loans\LoanListHandler;
use FirefightingEquipment\Device\Loan\LoanRepository;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Verleih (Phase 2, P2.5): POST loans und GET loans. Rechte der Routen: TenantRoutePermissionsTest.
 */
#[CoversClass(LoanLendHandler::class)]
#[CoversClass(LoanListHandler::class)]
#[CoversClass(LoanRepository::class)]
final class LoanTest extends DeviceApiTestCase
{
    use FilteredRoles;

    private int $nachbar;
    private int $schule;
    private int $returnPoint;

    protected function setUp(): void
    {
        parent::setUp();

        $this->nachbar = $this->createLocation($this->tenantId, ['name' => 'FF Nachbar', 'kind' => 'external']);
        $this->schule = $this->createLocation($this->tenantId, ['name' => 'Schule', 'kind' => 'external']);
        $this->returnPoint = $this->createLocation($this->tenantId, ['name' => 'Rückgabe', 'kind' => 'room']);
    }

    private function enableTasks(): void
    {
        foreach (['borrower', 'returns'] as $task) {
            $this->insert('fe_tenant_location_tasks', ['tenant_id' => $this->tenantId, 'task' => $task, 'enabled' => 1]);
        }
        $this->pdo()->prepare("UPDATE fe_locations SET task = 'borrower' WHERE id IN (?, ?)")->execute([$this->nachbar, $this->schule]);
        $this->pdo()->prepare("UPDATE fe_locations SET task = 'returns' WHERE id = ?")->execute([$this->returnPoint]);
    }

    /** @param list<array<string, mixed>> $devices */
    private static function entries(array $devices): array
    {
        return array_map(static fn (array $device): array => ['id' => $device['id'], 'version' => $device['version']], $devices);
    }

    private static function day(string $offset): string
    {
        return new \DateTimeImmutable($offset, new \DateTimeZone('Europe/Berlin'))->format('Y-m-d');
    }

    public function testAreaExistsOnlyWithBorrowerOrReturnsTask(): void
    {
        self::assertSame(403, $this->api('GET', 'loans')->statusCode());
        self::assertArrayNotHasKey('loans', (array) self::json($this->api('GET', 'auth/me'))['permissions']['areas']);

        $this->enableTasks();

        self::assertSame(200, $this->api('GET', 'loans')->statusCode());
        self::assertSame('write', ((array) self::json($this->api('GET', 'auth/me'))['permissions']['areas'])['loans']);
        self::assertSame(403, $this->api('GET', 'loans', null, $this->user)->statusCode());
        self::assertSame(403, $this->api('POST', 'loans', ['devices' => []], $this->user)->statusCode());
    }

    public function testRoleWithDataFilterHasNoAccess(): void
    {
        $this->enableTasks();
        $this->filteredAccount('filter@example.org', ['filter_locations' => 1, 'filter_device_types' => 0], [$this->hlf], [], ['loans' => 'write']);

        self::assertSame(403, $this->api('GET', 'loans', null, $this->loginCookie('immendingen', 'filter@example.org'))->statusCode(), 'F3');
    }

    public function testLendBooksToTheBorrowerAndListsTheLoan(): void
    {
        $this->enableTasks();
        $a = $this->createViaApi('HRT 20');
        $b = $this->createViaApi('HRT 3', ['status' => 'maintenance']);

        $response = $this->api('POST', 'loans', [
            'devices' => self::entries([$a, $b]),
            'borrower_location_id' => $this->nachbar,
            'contact' => 'Herr Maier',
            'due_back_on' => self::day('+10 days'),
            'comment' => 'Übung',
        ]);

        self::assertSame(200, $response->statusCode(), $response->body());
        $body = self::json($response);
        self::assertSame(['total' => 2, 'applied' => 2, 'unchanged' => 0, 'errors' => 0], $body['summary']);
        self::assertSame(['id' => $this->nachbar, 'name' => 'FF Nachbar'], $body['borrower']);
        $device = self::json($this->api('GET', 'devices/' . $a['id']))['device'];
        self::assertSame(['FF Nachbar', 'ready', 2], [$device['location']['name'], $device['status'], $device['version']]);
        self::assertSame('maintenance', self::json($this->api('GET', 'devices/' . $b['id']))['device']['status'], 'der Status bleibt');
        $events = $this->events($a['id']);
        self::assertSame(['state_changed', $this->g1, $this->nachbar, 'Übung'], [...array_slice(self::state($events[1]), 0, 1), ...array_slice(self::state($events[1]), 2, 2), self::state($events[1])[6]]);

        $list = self::json($this->api('GET', 'loans'));
        self::assertSame(['open' => 2, 'overdue' => 0], $list['summary']);
        self::assertSame(['HRT 20', 'HRT 3'], array_map(static fn (array $l): string => $l['device']['identifier'], $list['loans']), 'gleicher Leihnehmer, kein Datum: in der Reihenfolge des Verleihs');
        self::assertSame(['Herr Maier', 'Übung'], [$list['loans'][0]['contact'], $list['loans'][0]['comment']]);
    }

    public function testListOrdersByBorrowerThenDueDateAndCountsOverdue(): void
    {
        $this->enableTasks();
        $a = $this->createViaApi('A-1');
        $b = $this->createViaApi('B-2');
        $c = $this->createViaApi('C-3');
        $this->api('POST', 'loans', ['devices' => self::entries([$a]), 'borrower_location_id' => $this->schule, 'due_back_on' => self::day('-3 days')]);
        $this->api('POST', 'loans', ['devices' => self::entries([$b]), 'borrower_location_id' => $this->nachbar]);
        $this->api('POST', 'loans', ['devices' => self::entries([$c]), 'borrower_location_id' => $this->nachbar, 'due_back_on' => self::day('+1 day')]);

        $list = self::json($this->api('GET', 'loans'));

        self::assertSame(['C-3', 'B-2', 'A-1'], array_map(static fn (array $l): string => $l['device']['identifier'], $list['loans']), 'Leihnehmer, dann Datum, ohne Datum zuletzt');
        self::assertSame(['open' => 3, 'overdue' => 1], $list['summary']);
        self::assertSame([false, false, true], array_column($list['loans'], 'overdue'));
        self::assertSame(3, $list['loans'][2]['days_overdue']);
        self::assertSame([$this->nachbar, $this->schule], array_column($list['borrowers'], 'id'));
        self::assertSame([$this->returnPoint], array_column($list['returns'], 'id'));
    }

    public function testAlreadyLentDeviceIsAnErrorOfThatEntryOnly(): void
    {
        $this->enableTasks();
        $a = $this->createViaApi('A-1');
        $b = $this->createViaApi('B-2');
        $this->api('POST', 'loans', ['devices' => self::entries([$a]), 'borrower_location_id' => $this->nachbar]);
        $a = self::json($this->api('GET', 'devices/' . $a['id']))['device'];

        $body = self::json($this->api('POST', 'loans', ['devices' => self::entries([$a, $b]), 'borrower_location_id' => $this->schule]));

        self::assertSame(['error', 'applied'], array_column($body['results'], 'outcome'));
        self::assertSame('rule_violation', $body['results'][0]['error']['error']);
        self::assertStringContainsString('schon verliehen', $body['results'][0]['error']['message']);
        self::assertSame($this->nachbar, (int) self::json($this->api('GET', 'devices/' . $a['id']))['device']['location']['id']);
    }

    public function testLendingAgainAfterAManualRebookClosesTheOldLoan(): void
    {
        $this->enableTasks();
        $a = $this->createViaApi('A-1');
        $this->api('POST', 'loans', ['devices' => self::entries([$a]), 'borrower_location_id' => $this->nachbar]);
        $device = self::json($this->api('GET', 'devices/' . $a['id']))['device'];
        $back = self::json($this->api('POST', 'devices/' . $a['id'] . '/state', ['version' => $device['version'], 'location_id' => $this->g1]))['device'];

        self::assertSame(['open' => 0, 'overdue' => 0], self::json($this->api('GET', 'loans'))['summary'], 'von Hand zurückgebucht: nicht mehr verliehen');

        $again = self::json($this->api('POST', 'loans', ['devices' => self::entries([$back]), 'borrower_location_id' => $this->schule]));

        self::assertSame(1, $again['summary']['applied']);
        self::assertSame(1, self::json($this->api('GET', 'loans'))['summary']['open']);
        self::assertSame(2, (int) $this->pdo()->query('SELECT COUNT(*) FROM fe_device_loans')->fetchColumn());
    }

    public function testRejectsBadRequestsWithoutChangingAnything(): void
    {
        $this->enableTasks();
        $a = $this->createViaApi('A-1');
        $before = $this->deviceSnapshot();
        $entries = self::entries([$a]);

        foreach ([
            ['devices' => $entries],
            ['devices' => $entries, 'borrower_location_id' => $this->hlf],
            ['devices' => $entries, 'borrower_location_id' => $this->returnPoint],
            ['devices' => $entries, 'borrower_location_id' => 999999],
            ['devices' => $entries, 'borrower_location_id' => $this->nachbar, 'due_back_on' => '31.12.2026'],
            ['devices' => $entries, 'borrower_location_id' => $this->nachbar, 'contact' => str_repeat('x', 101)],
            ['devices' => $entries, 'borrower_location_id' => $this->nachbar, 'status' => 'defective'],
            ['devices' => [], 'borrower_location_id' => $this->nachbar],
            ['devices' => [...$entries, ...$entries], 'borrower_location_id' => $this->nachbar],
        ] as $body) {
            self::assertSame(400, $this->api('POST', 'loans', $body)->statusCode(), json_encode($body, JSON_THROW_ON_ERROR));
        }
        $this->pdo()->prepare('UPDATE fe_locations SET active = 0 WHERE id = ?')->execute([$this->nachbar]);
        self::assertSame(400, $this->api('POST', 'loans', ['devices' => $entries, 'borrower_location_id' => $this->nachbar])->statusCode(), 'gesperrt');
        self::assertSame($before, $this->deviceSnapshot());
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM fe_device_loans')->fetchColumn());
    }

    public function testVersionConflictAndUnknownDeviceAreErrorsOfTheirEntries(): void
    {
        $this->enableTasks();
        $a = $this->createViaApi('A-1');
        $foreign = $this->createDevice($this->otherTenantId, $this->otherTypeId, $this->otherLocation);

        $body = self::json($this->api('POST', 'loans', [
            'devices' => [['id' => $a['id'], 'version' => 99], ['id' => $foreign, 'version' => 1]],
            'borrower_location_id' => $this->nachbar,
        ]));

        self::assertSame(['error', 'error'], array_column($body['results'], 'outcome'));
        self::assertSame(['version_conflict', 'not_found'], array_map(static fn (array $r): string => $r['error']['error'], $body['results']));
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM fe_device_loans')->fetchColumn());
    }

    public function testTaskOfTheBorrowerCannotBeRemovedWhileEquipmentIsLent(): void
    {
        $this->enableTasks();
        $a = $this->createViaApi('A-1');
        $this->api('POST', 'loans', ['devices' => self::entries([$a]), 'borrower_location_id' => $this->nachbar]);

        $blocked = $this->api('POST', 'locations/' . $this->nachbar . '/task', ['task' => null]);

        self::assertSame(409, $blocked->statusCode(), $blocked->body());
        self::assertStringContainsString('1 Ausrüstung', self::json($blocked)['message']);
        self::assertSame(200, $this->api('POST', 'locations/' . $this->schule . '/task', ['task' => null])->statusCode(), 'ohne Verleih geht es');
    }

    public function testRetiredDeviceIsNotListedAndNotLendable(): void
    {
        $this->enableTasks();
        $a = $this->createViaApi('A-1');
        $this->api('POST', 'loans', ['devices' => self::entries([$a]), 'borrower_location_id' => $this->nachbar]);
        $device = self::json($this->api('GET', 'devices/' . $a['id']))['device'];
        $this->api('POST', 'devices/' . $a['id'] . '/retire', ['version' => $device['version']]);

        self::assertSame(0, self::json($this->api('GET', 'loans'))['summary']['open']);
    }
}
