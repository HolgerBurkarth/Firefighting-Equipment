<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Notification;

use FirefightingEquipment\Device\DeviceEvent;
use FirefightingEquipment\Device\DeviceEventLocation;
use FirefightingEquipment\Device\DeviceEventType;
use FirefightingEquipment\Device\DeviceStatus;
use FirefightingEquipment\Notification\MailTopic;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MailTopic::class)]
final class MailTopicTest extends TestCase
{
    private static function state(
        ?DeviceStatus $to,
        bool $moved = false,
        ?int $userId = 7,
        bool $applied = true,
        bool $lent = false,
        ?string $swapId = null,
    ): DeviceEvent {
        return new DeviceEvent(
            1,
            DeviceEventType::StateChanged,
            $userId,
            'Erika',
            $moved ? new DeviceEventLocation(2, 'G1', 'HLF 20') : null,
            $moved ? new DeviceEventLocation(3, 'Lager', null) : null,
            $to === null ? null : DeviceStatus::Ready,
            $to,
            null,
            null,
            null,
            null,
            null,
            null,
            new \DateTimeImmutable('2026-11-09 10:00:00', new \DateTimeZone('UTC')),
            applied: $applied,
            lent: $lent,
            swapId: $swapId,
        );
    }

    /**
     * @return iterable<string, array{DeviceEvent, MailTopic}>
     */
    public static function events(): iterable
    {
        yield 'defekt' => [self::state(DeviceStatus::Defective), MailTopic::DefectReported];
        yield 'umgebucht und defekt' => [self::state(DeviceStatus::Defective, moved: true), MailTopic::DefectReported];
        yield 'benutzt' => [self::state(DeviceStatus::Used, moved: true), MailTopic::UsedReported];
        yield 'Rückgabe ohne Anmeldung' => [self::state(null, moved: true, userId: null), MailTopic::ReturnReceived];
        yield 'verliehen' => [self::state(null, moved: true, lent: true), MailTopic::Lent];
        yield 'Tausch geht vor benutzt' => [self::state(DeviceStatus::Used, moved: true, swapId: str_repeat('a', 32)), MailTopic::Changes];
        yield 'umgebucht' => [self::state(null, moved: true), MailTopic::Changes];
        yield 'in Wartung' => [self::state(DeviceStatus::Maintenance), MailTopic::Changes];
        yield 'nicht übernommen' => [self::state(DeviceStatus::Defective, applied: false), MailTopic::Changes];
    }

    #[DataProvider('events')]
    public function testTopicOfAnEvent(DeviceEvent $event, MailTopic $topic): void
    {
        self::assertSame($topic, MailTopic::forEvent($event));
    }

    public function testOtherEventTypesAreMasterData(): void
    {
        $event = new DeviceEvent(1, DeviceEventType::DueChanged, 7, 'Erika', null, null, null, null, null, null, null, null, null, null, new \DateTimeImmutable('2026-11-09 10:00:00', new \DateTimeZone('UTC')));

        self::assertSame(MailTopic::MasterData, MailTopic::forEvent($event));
        self::assertSame([MailTopic::MasterData, MailTopic::Changes], MailTopic::forEvents([$event, self::state(null, moved: true), $event]));
    }

    public function testCatalogHasEveryTopicWithGermanTexts(): void
    {
        $catalog = MailTopic::catalog();

        self::assertSame(array_map(static fn (MailTopic $t): string => $t->value, MailTopic::cases()), array_column($catalog, 'id'));
        foreach ($catalog as $entry) {
            self::assertNotSame('', $entry['label']);
            self::assertNotSame('', $entry['hint']);
        }
    }
}
