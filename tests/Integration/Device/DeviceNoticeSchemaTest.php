<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Device;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * fe_device_notices und fe_device_notice_actions: gültige Werte, ein Hinweis je Buchung, alle
 * Bezüge aus derselben Stadt, Notiz nur beim Erledigen.
 */
#[CoversNothing]
final class DeviceNoticeSchemaTest extends DeviceSchemaTestCase
{
    private int $tenantId;
    private int $userId;
    private int $locationId;
    private int $deviceId;
    private int $bookingId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = $this->createTenant();
        $this->userId = $this->createUser($this->tenantId);
        $this->locationId = $this->createLocation($this->tenantId);
        $this->deviceId = $this->createDevice($this->tenantId, $this->createDeviceType($this->tenantId), $this->locationId);
        $this->bookingId = $this->insert('fe_device_bookings', [
            'tenant_id' => $this->tenantId, 'client_ref' => '3f2c8a1e-5b7d-4c9e-8f10-2a3b4c5d6e7f', 'user_id' => $this->userId,
            'device_id' => $this->deviceId, 'request_hash' => str_repeat("\0", 32), 'outcome' => 'unchanged',
        ]);
    }

    public function testValidNoticeWithDefaults(): void
    {
        $id = $this->notice([]);

        $row = $this->pdo()->query('SELECT status, stale_confirmed FROM fe_device_notices WHERE id = ' . $id)->fetch();
        self::assertSame(['status' => 'open', 'stale_confirmed' => 0], $row);
        $this->assertRejected(self::ER_DUP_ENTRY, fn () => $this->notice([]));
    }

    /**
     * @return iterable<string, array{array<string, scalar|null>}>
     */
    public static function invalidNotices(): iterable
    {
        yield 'unbekannte Art' => [['kind' => 'conflict']];
        yield 'alte Buchung ohne Kennzeichen' => [['kind' => 'stale_confirmed', 'stale_confirmed' => 0]];
        yield 'ohne Wunsch' => [['requested_status' => null]];
        yield 'Wunsch ausgesondert' => [['requested_status' => 'retired']];
        yield 'anderer Status unbekannt' => [['other_status' => 'lost']];
        yield 'Status unbekannt' => [['status' => 'done']];
    }

    /**
     * @param array<string, scalar|null> $values
     */
    #[DataProvider('invalidNotices')]
    public function testInvalidNoticeIsRejected(array $values): void
    {
        $this->assertRejected(self::ER_CONSTRAINT_FAILED, fn () => $this->notice($values));
    }

    public function testReferencesFromSameTenant(): void
    {
        $other = $this->createTenant('tuttlingen');
        $otherUser = $this->createUser($other);
        $otherLocation = $this->createLocation($other);

        $this->assertRejected(self::ER_NO_REFERENCED_ROW, fn () => $this->notice(['user_id' => $otherUser]));
        $this->assertRejected(self::ER_NO_REFERENCED_ROW, fn () => $this->notice(['other_user_id' => $otherUser]));
        $this->assertRejected(self::ER_NO_REFERENCED_ROW, fn () => $this->notice(['other_location_id' => $otherLocation]));
        $this->assertRejected(self::ER_NO_REFERENCED_ROW, fn () => $this->notice(['requested_location_id' => $otherLocation]));

        $notice = $this->notice([]);
        $this->assertRejected(self::ER_NO_REFERENCED_ROW, fn () => $this->insert('fe_device_notice_actions', [
            'tenant_id' => $other, 'notice_id' => $notice, 'action' => 'resolve', 'user_id' => $otherUser,
        ]));
    }

    public function testActions(): void
    {
        $notice = $this->notice([]);
        $action = fn (array $values): int => $this->insert('fe_device_notice_actions', [
            'tenant_id' => $this->tenantId, 'notice_id' => $notice, 'user_id' => $this->userId, ...$values,
        ]);

        $action(['action' => 'resolve', 'note' => 'Geklärt']);
        $action(['action' => 'reopen']);
        $this->assertRejected(self::ER_CONSTRAINT_FAILED, fn () => $action(['action' => 'reopen', 'note' => 'x']));
        $this->assertRejected(self::ER_CONSTRAINT_FAILED, fn () => $action(['action' => 'resolve', 'note' => '  ']));
        $this->assertRejected(self::ER_CONSTRAINT_FAILED, fn () => $action(['action' => 'close']));
        self::assertSame(2, $this->countRows('fe_device_notice_actions'));
    }

    /**
     * @param array<string, scalar|null> $values
     */
    private function notice(array $values): int
    {
        return $this->insert('fe_device_notices', array_merge([
            'tenant_id' => $this->tenantId,
            'device_id' => $this->deviceId,
            'booking_id' => $this->bookingId,
            'kind' => 'history_only',
            'user_id' => $this->userId,
            'occurred_at' => '2026-01-01 08:00:00',
            'requested_status' => 'defective',
            'other_occurred_at' => '2026-01-01 09:00:00',
            'other_location_id' => $this->locationId,
            'other_status' => 'ready',
        ], $values));
    }
}
