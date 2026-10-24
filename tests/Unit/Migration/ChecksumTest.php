<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Migration;

use FirefightingEquipment\Migration\Checksum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Checksum::class)]
final class ChecksumTest extends TestCase
{
    public function testChecksumIsSha256Hex(): void
    {
        self::assertSame(hash('sha256', "SELECT 1;\n"), Checksum::of("SELECT 1;\n"));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', Checksum::of('x'));
    }

    public function testChecksumIsStable(): void
    {
        self::assertSame(Checksum::of('CREATE TABLE fe_x (id INT);'), Checksum::of('CREATE TABLE fe_x (id INT);'));
    }

    public function testChangedContentChangesChecksum(): void
    {
        self::assertNotSame(Checksum::of('CREATE TABLE fe_x (id INT);'), Checksum::of('CREATE TABLE fe_x (id BIGINT);'));
        self::assertNotSame(Checksum::of("SELECT 1;\n"), Checksum::of("SELECT 1;\n\n"));
    }

    public function testCrlfAndLfGiveSameChecksum(): void
    {
        self::assertSame(Checksum::of("SELECT 1;\nSELECT 2;\n"), Checksum::of("SELECT 1;\r\nSELECT 2;\r\n"));
    }

    public function testSingleCarriageReturnIsNotNormalized(): void
    {
        self::assertNotSame(Checksum::of("a\nb"), Checksum::of("a\rb"));
    }
}
