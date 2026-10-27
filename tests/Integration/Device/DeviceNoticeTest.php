<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Device;

use FirefightingEquipment\Api\V1\Devices\DeviceSummaryHandler;
use FirefightingEquipment\Api\V1\Notices\NoticeActionHandler;
use FirefightingEquipment\Api\V1\Notices\NoticeJson;
use FirefightingEquipment\Api\V1\Notices\NoticeListHandler;
use FirefightingEquipment\Config\OfflineConfig;
use FirefightingEquipment\Device\Notice\DeviceNoticeRepository;
use FirefightingEquipment\User\UserRepository;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Hinweise an die Admins zu Offline-Buchungen: welche Ergebnisse einen Hinweis anlegen, Inhalt
 * (beteiligte Konten mit E-Mail, beide Stände, beide Aktionszeiten), erledigen und wieder öffnen
 * mit Verlauf, Filter, Zusammenfassung, Mandantentrennung.
 */
#[CoversClass(DeviceNoticeRepository::class)]
#[CoversClass(NoticeListHandler::class)]
#[CoversClass(NoticeActionHandler::class)]
#[CoversClass(NoticeJson::class)]
#[CoversClass(DeviceSummaryHandler::class)]
final class DeviceNoticeTest extends DeviceApiTestCase
{
    use OfflineBookings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOffline(new OfflineConfig(queueMaxAgeDays: 3));
        $this->pdo()->prepare("UPDATE fe_users SET display_name = 'Kommandant' WHERE id = ?")->execute([$this->adminId]);
        $this->pdo()->prepare("UPDATE fe_users SET display_name = 'Gerätewart' WHERE id = ?")->execute([$this->userId]);
    }

    public function testOverwrittenStateCreatesNoticeWithBothStates(): void
    {
        $id = $this->createViaApi('MA-01')['id'];
        $this->moveOnline($id, ['version' => 1, 'status' => 'defective']);
        $this->setStateChangedAt($id, '-3 hours');

        $json = $this->offline($id, ['version' => 1, 'location_id' => $this->room, 'status' => 'maintenance', 'occurred_at' => $this->at('-1 hour')]);
        $noticeId = $json['result']['notice_id'];
        self::assertIsInt($noticeId);

        $notice = $this->notice($noticeId);
        self::assertSame(['overwritten', 'Anderen Stand überschrieben', 'open', 'offen', false], [
            $notice['kind'], $notice['kind_label'], $notice['status'], $notice['status_label'], $notice['stale_confirmed'],
        ]);
        self::assertSame(['id' => $id, 'identifier' => 'MA-01', 'device_type' => 'Atemschutzmaske'], $notice['device']);
        self::assertSame([
            'client_ref' => $this->lastRef(),
            'event_id' => $this->lastEventId($id),
            'user' => ['id' => $this->userId, 'name' => 'Gerätewart', 'email' => 'user@example.org'],
            'occurred_at' => $this->at('-1 hour'),
            'received_at' => $notice['created_at'],
            'location' => ['id' => $this->room, 'name' => 'Lager', 'path' => 'Lager'],
            'status' => 'maintenance',
            'status_label' => 'in Wartung',
        ], $notice['booking']);
        self::assertSame([
            'user' => ['id' => $this->adminId, 'name' => 'Kommandant', 'email' => 'admin@example.org'],
            'occurred_at' => $this->at('-3 hours'),
            'location' => ['id' => $this->g1, 'name' => 'G1', 'path' => 'HLF 20 / G1'],
            'status' => 'defective',
            'status_label' => 'defekt',
        ], $notice['other']);
        self::assertSame([], $notice['history']);
    }

    public function testWhichResultsCreateNotices(): void
    {
        $older = $this->createViaApi('MA-01')['id'];
        $this->moveOnline($older, ['version' => 1, 'status' => 'defective']);
        $retired = $this->createViaApi('MA-02')['id'];
        $this->api('POST', "devices/{$retired}/retire", ['version' => 1]);
        $locked = $this->createViaApi('MA-03')['id'];
        $lockedRoom = $this->createLocation($this->tenantId, ['name' => 'Keller', 'kind' => 'room']);
        $this->api('POST', "locations/{$lockedRoom}/lock", []);
        $plain = $this->createViaApi('MA-04')['id'];
        $stale = $this->createViaApi('MA-05')['id'];
        $this->setStateChangedAt($stale, '-10 days');
        $staleOverwritten = $this->createViaApi('MA-06')['id'];
        $this->moveOnline($staleOverwritten, ['version' => 1, 'status' => 'defective']);
        $this->setStateChangedAt($staleOverwritten, '-10 days');

        $kinds = [
            'history_only' => $this->offline($older, ['version' => 1, 'location_id' => $this->room, 'occurred_at' => $this->at('-1 day')]),
            'not_applied_retired' => $this->offline($retired, ['version' => 1, 'status' => 'defective', 'occurred_at' => $this->at('-1 minute')]),
            'not_applied_location_locked' => $this->offline($locked, ['version' => 1, 'location_id' => $lockedRoom, 'occurred_at' => $this->at('-1 minute')]),
            'stale_confirmed' => $this->offline($stale, ['version' => 1, 'status' => 'defective', 'occurred_at' => $this->at('-5 days'), 'confirmed_stale' => true]),
            'overwritten' => $this->offline($staleOverwritten, ['version' => 1, 'status' => 'maintenance', 'occurred_at' => $this->at('-5 days'), 'confirmed_stale' => true]),
        ];
        $none = [
            $this->offline($plain, ['version' => 1, 'status' => 'defective', 'occurred_at' => $this->at('-1 minute')]),
            $this->offline($plain, ['version' => 2, 'status' => 'defective', 'occurred_at' => $this->at('-1 minute')]),
        ];
        $online = $this->api('POST', "devices/{$plain}/state", ['version' => 2, 'status' => 'ready', 'client_ref' => $this->ref()]);

        foreach ($kinds as $kind => $json) {
            $notice = $this->notice($json['result']['notice_id']);
            self::assertSame($kind, $notice['kind']);
            self::assertSame(in_array($kind, ['stale_confirmed', 'overwritten'], true), $notice['stale_confirmed'], $kind);
        }
        self::assertSame([null, null], array_map(static fn (array $json): ?int => $json['result']['notice_id'], $none));
        self::assertNull(self::json($online)['result']['notice_id']);
        self::assertSame(5, $this->countRows('fe_device_notices'));

        $retiredNotice = $this->notice($kinds['not_applied_retired']['result']['notice_id']);
        self::assertSame(['retired', null, 'defective'], [$retiredNotice['other']['status'], $retiredNotice['booking']['location'], $retiredNotice['booking']['status']]);
    }

    public function testResolveAndReopenKeepHistory(): void
    {
        $noticeId = $this->historyOnlyNotice('MA-01');

        $resolved = $this->change($noticeId, 'resolve', ['note' => "Mit Kamerad geklärt.\nMaske ist in G1."]);
        self::assertSame(['resolved', 'erledigt'], [$resolved['status'], $resolved['status_label']]);
        $again = $this->change($noticeId, 'resolve', ['note' => 'doppelt']);
        $reopened = $this->change($noticeId, 'reopen', []);
        $this->change($noticeId, 'reopen', []);
        $final = $this->change($noticeId, 'resolve', []);

        self::assertCount(1, $again['history']);
        self::assertSame('open', $reopened['status']);
        self::assertSame(
            [
                ['resolve', 'Erledigt', "Mit Kamerad geklärt.\nMaske ist in G1.", $this->adminId],
                ['reopen', 'Wieder geöffnet', null, $this->adminId],
                ['resolve', 'Erledigt', null, $this->adminId],
            ],
            array_map(static fn (array $h): array => [$h['action'], $h['action_label'], $h['note'], $h['user']['id']], $final['history']),
        );
        self::assertSame(['name' => 'Kommandant', 'email' => 'admin@example.org'], array_slice($final['history'][0]['user'], 1));
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $final['history'][0]['at']);
    }

    public function testListFilterAndCounts(): void
    {
        $first = $this->historyOnlyNotice('MA-01');
        $second = $this->historyOnlyNotice('MA-02');
        $third = $this->historyOnlyNotice('MA-03');
        $this->change($second, 'resolve', []);

        self::assertSame([[$third, $first], 2, 2], $this->list('notices'));
        self::assertSame([[$third, $first], 2, 2], $this->list('notices?status=open'));
        self::assertSame([[$second], 1, 2], $this->list('notices?status=resolved'));
        self::assertSame([[$third, $second, $first], 3, 2], $this->list('notices?status=all'));
        self::assertSame([[$second], 3, 2], $this->list('notices?status=all&limit=1&offset=1'));
        self::assertError(400, 'invalid_request', $this->api('GET', 'notices?status=offen'));
        self::assertError(400, 'invalid_request', $this->api('GET', 'notices?limit=0'));

        $summary = self::json($this->api('GET', 'device-summary'));
        self::assertSame(['open' => 2], $summary['notices']);
        self::assertArrayNotHasKey('notices', self::json($this->api('GET', 'device-summary', cookie: $this->user)));
    }

    public function testInvalidRequestsAndOtherTenants(): void
    {
        $noticeId = $this->historyOnlyNotice('MA-01');
        $this->account('tuttlingen', 'admin@example.org', ['role' => 'admin']);
        $otherAdmin = $this->loginCookie('tuttlingen', 'admin@example.org');

        self::assertError(400, 'invalid_request', $this->api('POST', "notices/{$noticeId}/resolve", ['note' => str_repeat('x', 501)]));
        self::assertError(400, 'invalid_request', $this->api('POST', "notices/{$noticeId}/resolve", ['note' => "Tab\tim Text"]));
        self::assertError(400, 'invalid_request', $this->api('POST', "notices/{$noticeId}/resolve", ['status' => 'resolved']));
        self::assertError(400, 'invalid_request', $this->api('POST', "notices/{$noticeId}/reopen", ['note' => 'x']));
        self::assertError(400, 'invalid_request', $this->api('POST', 'notices/999999999/resolve', ['comment' => 'x']));
        self::assertError(404, 'not_found', $this->api('POST', 'notices/999999999/resolve', []));
        self::assertError(404, 'not_found', $this->api('GET', 'notices/999999999'));

        self::assertError(404, 'not_found', $this->api('GET', "notices/{$noticeId}", cookie: $otherAdmin, slug: 'tuttlingen'));
        self::assertError(404, 'not_found', $this->api('POST', "notices/{$noticeId}/resolve", [], $otherAdmin, 'tuttlingen'));
        self::assertSame([[], 0, 0], $this->list('notices?status=all', $otherAdmin, 'tuttlingen'));
        self::assertSame('open', $this->notice($noticeId)['status']);
        self::assertSame(0, $this->countRows('fe_device_notice_actions'));
    }

    public function testAnonymizedUsersAreShownWithoutEmail(): void
    {
        $noticeId = $this->historyOnlyNotice('MA-01');
        new UserRepository($this->pdo())->anonymize($this->userId);

        $notice = $this->notice($noticeId);

        self::assertSame(['id' => $this->userId, 'name' => sprintf('Gelöschter Benutzer #%d', $this->userId), 'email' => null], $notice['booking']['user']);
    }

    public function testReplayReturnsNoticeId(): void
    {
        $id = $this->createViaApi('MA-01')['id'];
        $this->moveOnline($id, ['version' => 1, 'status' => 'defective']);
        $body = ['version' => 1, 'location_id' => $this->room, 'occurred_at' => $this->at('-1 day'), 'client_ref' => $this->ref()];

        $first = $this->offline($id, $body);
        $replay = $this->offline($id, $body);

        self::assertTrue($replay['result']['replayed']);
        self::assertSame($first['result']['notice_id'], $replay['result']['notice_id']);
        self::assertSame(1, $this->countRows('fe_device_notices'));
    }

    /**
     * Gerät, das inzwischen anders gebucht wurde, mit einer älteren Offline-Buchung: Hinweis
     * history_only.
     */
    private function historyOnlyNotice(string $identifier): int
    {
        $id = $this->createViaApi($identifier)['id'];
        $this->moveOnline($id, ['version' => 1, 'status' => 'defective']);
        $json = $this->offline($id, ['version' => 1, 'location_id' => $this->room, 'occurred_at' => $this->at('-1 day')]);
        self::assertSame('history_only', $json['result']['outcome']);

        return $json['result']['notice_id'];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function moveOnline(int $id, array $body): void
    {
        $response = $this->api('POST', "devices/{$id}/state", $body);
        self::assertSame(200, $response->statusCode(), $response->body());
    }

    /**
     * @return array<string, mixed>
     */
    private function notice(int $id): array
    {
        $response = $this->api('GET', "notices/{$id}");
        self::assertSame(200, $response->statusCode(), $response->body());

        return self::json($response)['notice'];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function change(int $id, string $action, array $body): array
    {
        $response = $this->api('POST', "notices/{$id}/{$action}", $body);
        self::assertSame(200, $response->statusCode(), $response->body());

        return self::json($response)['notice'];
    }

    /**
     * @return array{list<int>, int, int} IDs, total, open
     */
    private function list(string $path, ?string $cookie = null, string $slug = 'immendingen'): array
    {
        $response = $this->api('GET', $path, cookie: $cookie, slug: $slug);
        self::assertSame(200, $response->statusCode(), $response->body());
        $json = self::json($response);

        return [array_column($json['notices'], 'id'), $json['total'], $json['open']];
    }

    private function lastEventId(int $deviceId): int
    {
        $events = $this->events($deviceId);

        return (int) end($events)['id'];
    }
}
