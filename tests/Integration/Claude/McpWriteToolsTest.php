<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Claude;

use FirefightingEquipment\Device\EventChannel;
use FirefightingEquipment\Mcp\Tool\ToolApi;
use FirefightingEquipment\Mcp\Tool\WriteTools;
use FirefightingEquipment\Notification\DeviceLogNotifier;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Schreibwerkzeuge (Schritt M6): Adapter auf die REST-Routen mit Versionsprüfung wie in der App,
 * Kommentar bei defekt, keine doppelte Buchung, CSV-Import nur mit dem Kennzeichen der Vorschau,
 * „über Claude“ im Verlauf (JSON, CSV, Hinweise, Log-Mail). Die App schreibt weiter „app“.
 */
#[CoversClass(WriteTools::class)]
#[CoversClass(ToolApi::class)]
#[CoversClass(EventChannel::class)]
#[CoversClass(DeviceLogNotifier::class)]
final class McpWriteToolsTest extends McpToolsTestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = $this->tokenFor(self::ADMIN_EMAIL);
    }

    public function testBookingNeedsTheCurrentVersionAndIsMarkedAsClaude(): void
    {
        $booked = $this->callTool($this->token, 'book_equipment', ['id' => $this->m1, 'version' => 1, 'location_id' => $this->store, 'status' => 'maintenance', 'comment' => 'Zur Prüfung']);
        self::assertFalse($booked['isError'], json_encode($booked, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame(['Lager', 'maintenance', 2], [$booked['structuredContent']['equipment']['location'], $booked['structuredContent']['equipment']['status'], $booked['structuredContent']['equipment']['version']]);
        self::assertSame('applied', $booked['structuredContent']['outcome']);

        $events = $this->restEvents($this->m1);
        self::assertSame(['claude', 'app'], array_column($events, 'channel'), 'Anlegen über die App, Buchung über Claude');

        $again = $this->callTool($this->token, 'book_equipment', ['id' => $this->m1, 'version' => 1, 'location_id' => $this->store, 'status' => 'maintenance', 'comment' => 'Zur Prüfung']);
        self::assertFalse($again['isError']);
        self::assertStringContainsString('schon angekommen', $again['content'][0]['text']);
        self::assertCount(2, $this->restEvents($this->m1), 'gleicher Aufruf bucht nicht doppelt');

        $stale = $this->callTool($this->token, 'book_equipment', ['id' => $this->m1, 'version' => 1, 'status' => 'ready']);
        self::assertTrue($stale['isError']);
        self::assertSame('version_conflict', $stale['structuredContent']['error']);
        self::assertSame(2, $stale['structuredContent']['details']['equipment']['version']);
    }

    public function testErrorDetailsUseTheTermsOfTheTools(): void
    {
        $this->data($this->token, 'book_equipment', ['id' => $this->m1, 'version' => 1, 'status' => 'maintenance']);

        foreach ([
            'book_equipment' => ['status' => 'ready'],
            'set_maintenance_date' => ['maintenance_due' => '2030-01-01'],
            'update_equipment' => ['model' => 'X'],
            'retire_equipment' => [],
        ] as $tool => $arguments) {
            $stale = $this->callTool($this->token, $tool, ['id' => $this->m1, 'version' => 1, ...$arguments]);
            self::assertSame('version_conflict', $stale['structuredContent']['error'], $tool);
            $details = $stale['structuredContent']['details'];
            self::assertSame(['equipment'], array_keys($details), $tool);
            $equipment = $details['equipment'];
            self::assertSame(['M1', 'maintenance', 2], [$equipment['number'], $equipment['status'], $equipment['version']], $tool);
            self::assertArrayHasKey('maintenance_due', $equipment);
            self::assertArrayHasKey('equipment_type', $equipment);
            foreach (['identifier', 'next_inspection_due', 'device_type', 'state_changed_at'] as $rest) {
                self::assertArrayNotHasKey($rest, $equipment, $tool . ': ' . $rest);
            }
            self::assertStringNotContainsString('"device"', $stale['content'][0]['text'], $tool);
            self::assertStringContainsString('"equipment"', $stale['content'][0]['text'], $tool);
        }

        $lock = $this->callTool($this->token, 'lock_location', ['id' => $this->store]);
        self::assertSame('rule_violation', $lock['structuredContent']['error']);
        self::assertSame(['equipment_count', 'sublocation_count'], array_keys($lock['structuredContent']['details']));
    }

    public function testDefectiveNeedsACommentLikeInTheApp(): void
    {
        $missing = $this->callTool($this->token, 'book_equipment', ['id' => $this->m1, 'version' => 1, 'status' => 'defective', 'comment' => 'kaputt'[0]]);
        self::assertSame('Was ist defekt? Bitte einen Kommentar mit mindestens 5 Zeichen angeben.', $missing['structuredContent']['message']);

        self::assertSame('defective', $this->data($this->token, 'book_equipment', ['id' => $this->m1, 'version' => 1, 'status' => 'defective', 'comment' => 'Glas gesprungen'])['equipment']['status']);
        self::assertTrue($this->callTool($this->token, 'book_equipment', ['id' => $this->m1, 'version' => 2])['isError'], 'ohne Ziel');
    }

    public function testUserBooksButMayNotEdit(): void
    {
        $token = $this->tokenFor(self::EMAIL);

        self::assertSame('Lager', $this->data($token, 'book_equipment', ['id' => $this->m1, 'version' => 1, 'location_id' => $this->store])['equipment']['location']);
        self::assertSame(-32602, $this->rpc($token, 'tools/call', ['name' => 'update_equipment', 'arguments' => ['id' => $this->m1, 'version' => 2, 'model' => 'X']])['error']['code']);
    }

    public function testFilteredRoleCannotTouchHiddenEquipment(): void
    {
        $token = $this->tokenFor(self::FILTERED_EMAIL);

        $hidden = $this->callTool($token, 'set_maintenance_date', ['id' => $this->m2, 'version' => 2, 'maintenance_due' => '2030-01-01']);
        self::assertSame('not_found', $hidden['structuredContent']['error']);
        $target = $this->callTool($token, 'book_equipment', ['id' => $this->m1, 'version' => 1, 'location_id' => $this->store]);
        self::assertTrue($target['isError'], 'Lager liegt außerhalb des Datenfilters');
    }

    public function testEquipmentFieldsCodeDateRetireAndReinstate(): void
    {
        $updated = $this->data($this->token, 'update_equipment', ['id' => $this->m1, 'version' => 1, 'serial_number' => 'SN-77', 'note' => '']);
        self::assertSame(['SN-77', null, 2], [$updated['equipment']['serial_number'], $updated['equipment']['note'], $updated['equipment']['version']]);

        self::assertSame('NEU-1', $this->data($this->token, 'assign_code', ['id' => $this->m1, 'version' => 2, 'scan_code' => 'NEU-1'])['equipment']['scan_code']);
        self::assertSame('2031-05-01', $this->data($this->token, 'set_maintenance_date', ['id' => $this->m1, 'version' => 3, 'maintenance_due' => '2031-05-01'])['equipment']['maintenance_due']);
        self::assertTrue($this->data($this->token, 'retire_equipment', ['id' => $this->m1, 'version' => 4, 'comment' => 'Ausgemustert'])['equipment']['retired']);
        $back = $this->data($this->token, 'reinstate_equipment', ['id' => $this->m1, 'version' => 5, 'status' => 'ready', 'location_id' => $this->store]);
        self::assertSame(['ready', 'Lager'], [$back['equipment']['status'], $back['equipment']['location']]);

        $channels = array_count_values(array_column($this->restEvents($this->m1), 'channel'));
        self::assertSame(['claude' => 6, 'app' => 1], $channels, 'zwei Felder, Code, Datum, Aussondern, Wiederaufnehmen');

        $nothing = $this->callTool($this->token, 'update_equipment', ['id' => $this->m1, 'version' => 6]);
        self::assertSame('invalid_arguments', $nothing['structuredContent']['error']);
    }

    public function testCreateAndBulkTools(): void
    {
        $created = $this->data($this->token, 'create_equipment', ['number' => 'M4', 'equipment_type_id' => $this->radio, 'location_id' => $this->g1, 'maintenance_due' => '2030-01-01']);
        self::assertSame(['M4', 'Funkgerät', 'HLF 20 / G1', 1], [$created['equipment']['number'], $created['equipment']['equipment_type'], $created['equipment']['location'], $created['equipment']['version']]);
        self::assertSame('identifier_taken', $this->callTool($this->token, 'create_equipment', ['number' => 'm4', 'equipment_type_id' => $this->radio, 'location_id' => $this->g1])['structuredContent']['error']);

        $due = $this->data($this->token, 'bulk_set_maintenance_date', ['entries' => [
            ['id' => $this->m1, 'version' => 1, 'maintenance_due' => ''],
            ['id' => $this->m2, 'version' => 1, 'maintenance_due' => '2030-02-02'],
        ]]);
        self::assertSame(['applied', 'error'], array_column($due['results'], 'outcome'));
        self::assertSame('version_conflict', $due['results'][1]['error']['error']);

        $book = $this->data($this->token, 'bulk_book_equipment', ['entries' => [
            ['id' => $this->m1, 'version' => 2, 'location_id' => $this->store],
            ['id' => $created['equipment']['id'], 'version' => 1, 'status' => 'maintenance'],
        ], 'comment' => 'Umzug']);
        self::assertSame(['total' => 2, 'applied' => 2, 'unchanged' => 0, 'errors' => 0], $book['summary']);
        self::assertSame('claude', $this->restEvents($this->m1)[0]['channel']);
    }

    public function testImportOnlyWithTheTokenOfThePreview(): void
    {
        $csv = "Nummer;Ausrüstungstyp;Standort\nM9;Atemschutzmaske;Lager\nM1;Atemschutzmaske;Lager\n";

        $preview = $this->data($this->token, 'preview_import', ['csv' => $csv]);
        self::assertSame(['rows' => 2, 'new' => 1, 'changed' => 1], array_intersect_key($preview['summary'], ['rows' => 0, 'new' => 0, 'changed' => 0]));
        self::assertSame('changes', $preview['rows_filter']);
        self::assertSame(['Standort: HLF 20 / G1 → Lager'], $preview['rows'][1]['changes']);
        self::assertIsString($preview['token']);

        $wrong = $this->callTool($this->token, 'apply_import', ['csv' => $csv, 'token' => str_repeat('0', 64)]);
        self::assertSame('import_outdated', $wrong['structuredContent']['error']);
        self::assertSame([], $this->numbersOf('M9'));

        $this->account(self::CITY, 'log@example.org', ['role' => 'admin', 'notify_device_log' => 1]);
        $this->mailer->sent = [];
        $applied = $this->data($this->token, 'apply_import', ['csv' => $csv, 'token' => $preview['token'], 'comment' => 'Inventur']);
        self::assertSame(1, $applied['summary']['new']);
        self::assertCount(1, $this->mailer->sent);
        self::assertStringContainsString('Wer: Erika (über Claude)', $this->mailer->sent[0]->text);
        $m9 = $this->numbersOf('M9');
        self::assertSame(['claude'], array_column($this->restEvents($m9[0]), 'channel'));

        $errors = $this->data($this->token, 'preview_import', ['csv' => "Nummer;Ausrüstungstyp;Standort\nM10;Atemschutzmaske;HLF20\n"]);
        self::assertNull($errors['token']);
        self::assertSame('errors', $errors['rows_filter']);
        self::assertSame([['value' => 'HLF20', 'suggestions' => ['HLF 20']]], $errors['suggestions']['locations']);
    }

    public function testLocationsTypesAndNotices(): void
    {
        $location = $this->data($this->token, 'create_location', ['name' => 'G2', 'parent_id' => $this->hlf])['location'];
        self::assertSame(['G2', 2, $this->hlf], [$location['name'], $location['level'], $location['parent_id']]);
        self::assertSame('G 2', $this->data($this->token, 'rename_location', ['id' => $location['id'], 'name' => 'G 2'])['location']['name']);
        self::assertFalse($this->data($this->token, 'lock_location', ['id' => $location['id']])['location']['active']);
        self::assertTrue($this->data($this->token, 'unlock_location', ['id' => $location['id']])['location']['active']);
        self::assertSame('rule_violation', $this->callTool($this->token, 'lock_location', ['id' => $this->store])['structuredContent']['error']);

        $type = $this->data($this->token, 'create_equipment_type', ['name' => 'Wärmebildkamera'])['equipment_type'];
        self::assertSame('WBK', $this->data($this->token, 'rename_equipment_type', ['id' => $type['id'], 'name' => 'WBK'])['equipment_type']['name']);
        self::assertFalse($this->data($this->token, 'deactivate_equipment_type', ['id' => $type['id']])['equipment_type']['active']);
        self::assertTrue($this->data($this->token, 'activate_equipment_type', ['id' => $type['id']])['equipment_type']['active']);

        self::assertSame('resolved', $this->data($this->token, 'resolve_notice', ['id' => $this->notice, 'note' => "Geklärt\nmit Erika"])['notice']['status']);
        self::assertSame('open', $this->data($this->token, 'reopen_notice', ['id' => $this->notice])['notice']['status']);
        $history = self::json($this->send('GET', self::AREA . 'api/v1/notices/' . $this->notice, cookie: $this->adminCookie))['notice']['history'];
        self::assertSame(['claude', 'claude'], array_column($history, 'channel'));
    }

    public function testHistoryToolsShowOverClaude(): void
    {
        $this->data($this->token, 'book_equipment', ['id' => $this->m1, 'version' => 1, 'status' => 'maintenance']);

        $history = $this->callTool($this->token, 'get_equipment_history', ['id' => $this->m1]);
        $events = $history['structuredContent']['events'];
        self::assertTrue($events[0]['via_claude'], 'Buchung über Claude');
        self::assertArrayNotHasKey('via_claude', $events[1], 'Anlegen über die App ohne Vermerk');
        self::assertStringContainsString('1 Eintrag davon über Claude.', $history['content'][0]['text']);

        $city = $this->callTool($this->token, 'list_city_history', []);
        $first = $city['structuredContent']['events'][0];
        self::assertSame([$this->m1, true], [$first['equipment']['id'], $first['via_claude']]);
        self::assertStringContainsString('davon über Claude.', $city['content'][0]['text']);

        $this->data($this->token, 'resolve_notice', ['id' => $this->notice]);
        $notice = $this->data($this->token, 'get_notice', ['id' => $this->notice])['notice'];
        self::assertTrue($notice['history'][0]['via_claude']);
    }

    public function testHistoryExportAndLogMailSayOverClaude(): void
    {
        $this->pdo()->prepare('UPDATE fe_users SET notify_device_log = 1 WHERE id = ?')->execute([$this->admin]);
        $this->mailer->sent = [];
        $token = $this->tokenFor(self::EMAIL);
        $this->mailer->sent = [];

        $this->data($token, 'book_equipment', ['id' => $this->m1, 'version' => 1, 'location_id' => $this->store]);

        $mails = array_values(array_filter($this->mailer->sent, static fn ($m): bool => str_starts_with($m->subject, 'Log ')));
        self::assertCount(1, $mails);
        self::assertStringContainsString('Wer: Erika (über Claude)', $mails[0]->text);

        $csv = self::responseBody($this->sendAny('GET', self::AREA . 'api/v1/device-events/export', cookie: $this->adminCookie));
        $lines = array_values(array_filter(explode("\r\n", $csv)));
        self::assertStringEndsWith(';Über Claude', $lines[0]);
        self::assertStringEndsWith(';Ja', $lines[1], 'neuester Eintrag zuerst: die Buchung über Claude');
        self::assertStringEndsWith(';', $lines[2], 'Einträge der App ohne „Ja“');
    }

    /**
     * Verlauf einer Ausrüstung über die REST-API (neueste zuerst).
     *
     * @return list<array<string, mixed>>
     */
    private function restEvents(int $device): array
    {
        return self::json($this->send('GET', self::AREA . 'api/v1/devices/' . $device . '/events', cookie: $this->adminCookie))['events'];
    }

    /**
     * IDs der Ausrüstung mit dieser Nummer.
     *
     * @return list<int>
     */
    private function numbersOf(string $number): array
    {
        $stmt = $this->pdo()->prepare('SELECT id FROM fe_devices WHERE identifier = ?');
        $stmt->execute([$number]);

        return array_map(intval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }
}
