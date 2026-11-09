<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Claude;

use FirefightingEquipment\Mcp\McpServer;
use FirefightingEquipment\Mcp\Tool\ReadTools;
use FirefightingEquipment\Mcp\Tool\ToolApi;
use FirefightingEquipment\Mcp\Tool\WriteTools;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Werkzeuge für die Abläufe der Phase 2 (P2.12): report_used, swap_equipment, lend_equipment,
 * list_loans, list_maintenance. Adapter auf dieselben Routen wie die App; die Bereiche Verleih und
 * Wartung gibt es nur mit freigeschalteten Aufgaben; „über Claude“ im Verlauf.
 */
#[CoversClass(WriteTools::class)]
#[CoversClass(ReadTools::class)]
#[CoversClass(ToolApi::class)]
final class McpPhase2ToolsTest extends McpToolsTestCase
{
    private int $collection;
    private int $workshop;
    private int $neighbour;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->collection = $this->createLocation($this->tenantId, ['name' => 'Sammelstelle', 'kind' => 'room']);
        $this->workshop = $this->createLocation($this->tenantId, ['name' => 'Werkstatt', 'kind' => 'room']);
        $this->neighbour = $this->createLocation($this->tenantId, ['name' => 'FF Nachbar', 'kind' => 'external']);
        $this->token = $this->tokenFor(self::ADMIN_EMAIL);
    }

    private function enableTasks(): void
    {
        foreach (['collection', 'workshop', 'borrower'] as $task) {
            $this->insert('fe_tenant_location_tasks', ['tenant_id' => $this->tenantId, 'task' => $task, 'enabled' => 1]);
        }
        $this->pdo()->prepare("UPDATE fe_locations SET task = 'collection', collection_accepts = 'both' WHERE id = ?")->execute([$this->collection]);
        $this->pdo()->prepare("UPDATE fe_locations SET task = 'workshop' WHERE id = ?")->execute([$this->workshop]);
        $this->pdo()->prepare("UPDATE fe_locations SET task = 'borrower' WHERE id = ?")->execute([$this->neighbour]);
    }

    /** @return list<array<string, mixed>> */
    private function events(int $device): array
    {
        return self::json($this->send('GET', self::AREA . 'api/v1/devices/' . $device . '/events', null, $this->adminCookie))['events'];
    }

    public function testToolsAreVisibleAndLoansAndMaintenanceNeedTheirTasks(): void
    {
        $names = $this->toolNames($this->token);
        foreach (['report_used', 'swap_equipment'] as $always) {
            self::assertContains($always, $names);
        }
        foreach (['list_loans', 'list_maintenance', 'lend_equipment'] as $withTask) {
            self::assertNotContains($withTask, $names, 'ohne freigeschaltete Aufgabe wie unbekannt');
        }
        self::assertSame(McpServer::INVALID_PARAMS, $this->rpc($this->token, 'tools/call', ['name' => 'list_loans', 'arguments' => new \stdClass()])['error']['code']);

        $this->enableTasks();

        $names = $this->toolNames($this->token);
        foreach (['list_loans', 'list_maintenance', 'lend_equipment'] as $withTask) {
            self::assertContains($withTask, $names);
        }
        $read = $this->tokenFor(self::ADMIN_EMAIL, 'read');
        $readNames = $this->toolNames($read);
        self::assertContains('list_loans', $readNames);
        self::assertContains('list_maintenance', $readNames);
        foreach (['report_used', 'swap_equipment', 'lend_equipment'] as $write) {
            self::assertNotContains($write, $readNames, 'nur mit Zugang „Ändern“');
        }
    }

    public function testReportUsedGoesToTheCollectionPointAndIsMarkedAsClaude(): void
    {
        $this->enableTasks();

        $result = $this->data($this->token, 'report_used', ['id' => $this->m1, 'version' => 1, 'comment' => 'Im Einsatz benutzt']);

        self::assertSame(['used', 'Sammelstelle', 2], [$result['equipment']['status'], $result['equipment']['location'], $result['equipment']['version']]);
        self::assertSame(['claude', 'app'], array_column($this->events($this->m1), 'channel'));
        self::assertSame('Benutzt gemeldet', $this->events($this->m1)[0]['type_label']);

        $again = $this->callTool($this->token, 'report_used', ['id' => $this->m1, 'version' => 1, 'comment' => 'Im Einsatz benutzt']);
        self::assertFalse($again['isError']);
        self::assertStringContainsString('schon angekommen', $again['content'][0]['text']);
        self::assertCount(2, $this->events($this->m1), 'gleicher Aufruf meldet nicht doppelt');

        $stale = $this->callTool($this->token, 'report_used', ['id' => $this->m3, 'version' => 1]);
        self::assertSame('version_conflict', $stale['structuredContent']['error']);
        self::assertSame('equipment', array_keys($stale['structuredContent']['details'])[0]);
    }

    public function testSwapBooksBothTogetherOnceAndMarksThemAsClaude(): void
    {
        $this->enableTasks();
        $spare = $this->createViaApi(['identifier' => 'M7', 'device_type_id' => $this->mask, 'location_id' => $this->store]);

        $result = $this->data($this->token, 'swap_equipment', ['out_id' => $this->m1, 'out_version' => 1, 'in_id' => $spare, 'in_version' => 1, 'out_status' => 'defective', 'comment' => 'Maske gerissen']);

        self::assertSame(['M1', 'defective', 'Sammelstelle'], [$result['equipment_out']['number'], $result['equipment_out']['status'], $result['equipment_out']['location']]);
        self::assertSame(['M7', 'ready', 'HLF 20 / G1'], [$result['equipment_in']['number'], $result['equipment_in']['status'], $result['equipment_in']['location']]);
        $out = $this->events($this->m1)[0];
        $in = $this->events($spare)[0];
        self::assertSame(['claude', 'claude'], [$out['channel'], $in['channel']]);
        self::assertSame('Getauscht gegen M7', $out['type_label']);
        self::assertSame('Als Ersatz für M1', $in['type_label']);

        $again = $this->callTool($this->token, 'swap_equipment', ['out_id' => $this->m1, 'out_version' => 1, 'in_id' => $spare, 'in_version' => 1, 'out_status' => 'defective', 'comment' => 'Maske gerissen']);
        self::assertFalse($again['isError'], json_encode($again, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertStringContainsString('schon angekommen', $again['content'][0]['text']);
        self::assertCount(2, $this->events($this->m1), 'nicht doppelt gebucht');
    }

    public function testSwapBelowTheStockCannotBeOverriddenViaClaude(): void
    {
        $this->enableTasks();
        $rule = $this->send('POST', self::AREA . 'api/v1/locations/' . $this->hlf . '/stock-rules', ['rules' => [['min_count' => 1, 'device_type_ids' => [$this->mask]]]], $this->adminCookie);
        self::assertSame(200, $rule->statusCode(), $rule->body());
        // Ersatz ist M2 (Funkgerät, defekt) nicht möglich: stattdessen ein einsatzbereiter anderer Typ, der die Regel nicht füllt.
        $other = $this->createViaApi(['identifier' => 'F9', 'device_type_id' => $this->radio, 'location_id' => $this->store]);

        $result = $this->callTool($this->token, 'swap_equipment', ['out_id' => $this->m1, 'out_version' => 1, 'in_id' => $other, 'in_version' => 1]);

        self::assertTrue($result['isError']);
        self::assertSame('rule_violation', $result['structuredContent']['error']);
        self::assertFalse($result['structuredContent']['details']['stock']['can_override']);
        self::assertSame('G1', array_slice(explode(' / ', (string) $this->deviceLocation($this->m1)), 1)[0] ?? null, 'nichts gebucht');
    }

    public function testLendAndListLoans(): void
    {
        $this->enableTasks();

        $lent = $this->data($this->token, 'lend_equipment', [
            'entries' => [['id' => $this->m1, 'version' => 1], ['id' => $this->m3, 'version' => 2]],
            'borrower_id' => $this->neighbour,
            'contact' => 'Herr Maier',
            'return_by' => '2099-01-31',
            'comment' => 'Übung',
        ]);

        self::assertSame(['total' => 2, 'applied' => 2, 'unchanged' => 0, 'errors' => 0], $lent['summary']);
        self::assertSame('FF Nachbar', $lent['borrower']);
        self::assertSame('Verliehen', $this->events($this->m1)[0]['type_label']);
        self::assertSame('claude', $this->events($this->m1)[0]['channel']);

        $loans = $this->data($this->token, 'list_loans');
        self::assertSame(['open' => 2, 'overdue' => 0], $loans['summary']);
        self::assertSame(['M1', 'M3'], array_column(array_column($loans['loans'], 'equipment'), 'number'));
        self::assertSame(['FF Nachbar', 'Herr Maier', '2099-01-31'], [$loans['loans'][0]['borrower'], $loans['loans'][0]['contact'], $loans['loans'][0]['return_by']]);
        self::assertSame([$this->neighbour], array_column($loans['borrowers'], 'id'));
        self::assertContains('loans[].comment', $loans['free_text_fields']);

        $stale = $this->callTool($this->token, 'lend_equipment', ['entries' => [['id' => $this->m1, 'version' => 1]], 'borrower_id' => $this->neighbour]);
        self::assertFalse($stale['isError'], 'Teilerfolg: der Fehler steht im Ergebnis des Eintrags');
        self::assertSame(1, $stale['structuredContent']['summary']['errors']);
    }

    public function testListMaintenanceShowsWaitingEquipment(): void
    {
        $this->enableTasks();
        $this->data($this->token, 'report_used', ['id' => $this->m1, 'version' => 1]);

        $data = $this->data($this->token, 'list_maintenance');

        self::assertSame(['Sammelstelle'], array_column($data['collection']['locations'], 'name'));
        self::assertSame(['M1'], array_column($data['collection']['equipment'], 'number'));
        self::assertNotNull($data['collection']['equipment'][0]['waiting_since']);
        self::assertSame([], $data['workshop']['equipment']);
        self::assertSame(1, $data['collection']['paging']['total']);
    }

    private function deviceLocation(int $id): ?string
    {
        $device = self::json($this->send('GET', self::AREA . 'api/v1/devices/' . $id, null, $this->adminCookie))['device'];

        return $device['location']['path'] ?? null;
    }
}
