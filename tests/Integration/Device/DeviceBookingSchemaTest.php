<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Device;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * fe_device_bookings: client_ref (UUID v4, klein) eindeutig je Stadt, Ergebnis und Grund passend,
 * Gerät und Konto aus derselben Stadt.
 */
#[CoversNothing]
final class DeviceBookingSchemaTest extends DeviceSchemaTestCase
{
    private const string REF = '3f2c8a1e-5b7d-4c9e-8f10-2a3b4c5d6e7f';

    private int $tenantId;
    private int $userId;
    private int $deviceId;
    private int $eventId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = $this->createTenant();
        $this->userId = $this->createUser($this->tenantId);
        $location = $this->createLocation($this->tenantId);
        $this->deviceId = $this->createDevice($this->tenantId, $this->createDeviceType($this->tenantId), $location);
        $this->eventId = $this->createEvent($this->tenantId, $this->deviceId, $this->userId, ['event_type' => 'created', 'to_location_id' => $location, 'to_status' => 'ready']);
    }

    /**
     * @return iterable<string, array{array<string, scalar|null>}>
     */
    public static function validBookings(): iterable
    {
        yield 'übernommen' => [['outcome' => 'applied']];
        yield 'überschrieben, alt nach Rückfrage' => [['outcome' => 'applied', 'reason' => 'overwritten', 'stale_confirmed' => 1]];
        yield 'nur Historie' => [['outcome' => 'history_only', 'reason' => 'older']];
        yield 'ausgesondert' => [['outcome' => 'not_applied', 'reason' => 'retired']];
        yield 'Standort gesperrt' => [['outcome' => 'not_applied', 'reason' => 'location_locked']];
        yield 'keine Änderung' => [['outcome' => 'unchanged', 'event_id' => null]];
    }

    /**
     * @param array<string, scalar|null> $values
     */
    #[DataProvider('validBookings')]
    public function testValidBookingIsAccepted(array $values): void
    {
        $this->booking($values);

        self::assertSame(1, $this->countRows('fe_device_bookings'));
    }

    /**
     * @return iterable<string, array{array<string, scalar|null>}>
     */
    public static function invalidBookings(): iterable
    {
        yield 'client_ref groß' => [['client_ref' => strtoupper(self::REF)]];
        yield 'client_ref Version 1' => [['client_ref' => '3f2c8a1e-5b7d-1c9e-8f10-2a3b4c5d6e7f']];
        yield 'unbekanntes Ergebnis' => [['outcome' => 'done']];
        yield 'übernommen ohne Ereignis' => [['event_id' => null]];
        yield 'übernommen mit Grund älter' => [['reason' => 'older']];
        yield 'nur Historie ohne Grund' => [['outcome' => 'history_only']];
        yield 'nicht übernommen, Grund älter' => [['outcome' => 'not_applied', 'reason' => 'older']];
        yield 'keine Änderung mit Ereignis' => [['outcome' => 'unchanged']];
        yield 'keine Änderung mit Grund' => [['outcome' => 'unchanged', 'reason' => 'overwritten', 'event_id' => null]];
        yield 'stale_confirmed 2' => [['stale_confirmed' => 2]];
    }

    /**
     * @param array<string, scalar|null> $values
     */
    #[DataProvider('invalidBookings')]
    public function testInvalidBookingIsRejected(array $values): void
    {
        $this->assertRejected(self::ER_CONSTRAINT_FAILED, fn () => $this->booking($values));
    }

    public function testClientRefIsUniquePerTenant(): void
    {
        $this->booking([]);
        $this->assertRejected(self::ER_DUP_ENTRY, fn () => $this->booking(['event_id' => null, 'outcome' => 'unchanged']));

        $otherTenant = $this->createTenant('tuttlingen');
        $otherUser = $this->createUser($otherTenant);
        $otherDevice = $this->createDevice($otherTenant, $this->createDeviceType($otherTenant), $this->createLocation($otherTenant));
        $this->insert('fe_device_bookings', [
            'tenant_id' => $otherTenant, 'client_ref' => self::REF, 'user_id' => $otherUser, 'device_id' => $otherDevice,
            'request_hash' => str_repeat("\0", 32), 'outcome' => 'unchanged',
        ]);
        self::assertSame(2, $this->countRows('fe_device_bookings'));
    }

    public function testDeviceAndUserFromSameTenant(): void
    {
        $otherTenant = $this->createTenant('tuttlingen');
        $otherUser = $this->createUser($otherTenant);
        $otherDevice = $this->createDevice($otherTenant, $this->createDeviceType($otherTenant), $this->createLocation($otherTenant));

        $this->assertRejected(self::ER_NO_REFERENCED_ROW, fn () => $this->booking(['user_id' => $otherUser]));
        $this->assertRejected(self::ER_NO_REFERENCED_ROW, fn () => $this->booking(['device_id' => $otherDevice]));
    }

    /**
     * @param array<string, scalar|null> $values
     */
    private function booking(array $values): int
    {
        return $this->insert('fe_device_bookings', array_merge([
            'tenant_id' => $this->tenantId,
            'client_ref' => self::REF,
            'user_id' => $this->userId,
            'device_id' => $this->deviceId,
            'request_hash' => str_repeat("\1", 32),
            'outcome' => 'applied',
            'event_id' => $this->eventId,
        ], $values));
    }
}
