<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Auth;

use FirefightingEquipment\Auth\IpPrefix;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(IpPrefix::class)]
final class IpPrefixTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function addresses(): iterable
    {
        yield 'IPv4' => ['192.0.2.123', 'c0000200'];
        yield 'IPv4 gleiches /24' => ['192.0.2.1', 'c0000200'];
        yield 'IPv6' => ['2001:db8:abcd:12::1', '20010db8abcd00000000000000000000'];
        yield 'IPv6 gleiches /48' => ['2001:db8:abcd:ffff:1:2:3:4', '20010db8abcd00000000000000000000'];
        yield 'IPv4 in IPv6' => ['::ffff:198.51.100.7', 'c6336400'];
        yield 'leer' => ['', '00000000'];
        yield 'ungültig' => ['kein-ip', '00000000'];
        yield 'mit Port' => ['192.0.2.1:8080', '00000000'];
    }

    #[DataProvider('addresses')]
    public function testPrefix(string $address, string $expectedHex): void
    {
        self::assertSame($expectedHex, bin2hex(IpPrefix::fromAddress($address)));
    }
}
