<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Invitation;

use FirefightingEquipment\Invitation\InvitationCodeStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(InvitationCodeStatus::class)]
final class InvitationCodeStatusTest extends TestCase
{
    /**
     * @return iterable<string, array{bool, int, int, bool, InvitationCodeStatus}>
     */
    public static function cases(): iterable
    {
        yield 'aktiv' => [false, 0, 5, false, InvitationCodeStatus::Active];
        yield 'fast aufgebraucht' => [false, 4, 5, false, InvitationCodeStatus::Active];
        yield 'abgelaufen' => [false, 0, 5, true, InvitationCodeStatus::Expired];
        yield 'aufgebraucht' => [false, 5, 5, false, InvitationCodeStatus::Exhausted];
        yield 'aufgebraucht vor abgelaufen' => [false, 5, 5, true, InvitationCodeStatus::Exhausted];
        yield 'widerrufen vor allem' => [true, 5, 5, true, InvitationCodeStatus::Revoked];
    }

    #[DataProvider('cases')]
    public function testPrecedence(bool $revoked, int $useCount, int $maxUses, bool $expired, InvitationCodeStatus $expected): void
    {
        self::assertSame($expected, InvitationCodeStatus::of($revoked, $useCount, $maxUses, $expired));
    }

    public function testGermanLabels(): void
    {
        self::assertSame(
            ['aktiv', 'abgelaufen', 'aufgebraucht', 'widerrufen'],
            array_map(static fn (InvitationCodeStatus $s): string => $s->label(), InvitationCodeStatus::cases()),
        );
    }
}
