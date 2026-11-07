<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\OAuth;

use FirefightingEquipment\Api\V1\OAuth\AuthProbe;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuthProbe::class)]
final class AuthProbeTest extends TestCase
{
    public function testOnlyNamesWithAuthAndWhetherTheyAreEmpty(): void
    {
        $result = AuthProbe::describe('cgi-fcgi', [
            'GATEWAY_INTERFACE' => 'CGI/1.1',
            'REDIRECT_REDIRECT_HTTP_AUTHORIZATION' => 'Bearer geheim1',
            'HTTP_AUTHORIZATION' => '',
            'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer geheim2',
            'auth_type' => 'Bearer geheim3',
            'HTTP_USER_AGENT' => 'geheim4',
            'AUTH-MIT-STRICH' => 'geheim5',
            7 => 'geheim6',
            'PHP_AUTH_DIGEST' => ['geheim7'],
        ], ['XAUTHORITY' => 'geheim8', 'PATH' => 'geheim9']);

        self::assertSame([
            'sapi' => 'cgi-fcgi',
            'gateway_interface' => 'CGI/1.1',
            'server' => [
                'HTTP_AUTHORIZATION' => false,
                'PHP_AUTH_DIGEST' => true,
                'REDIRECT_HTTP_AUTHORIZATION' => true,
                'REDIRECT_REDIRECT_HTTP_AUTHORIZATION' => true,
                'auth_type' => true,
            ],
            'env' => ['XAUTHORITY' => true],
        ], $result);
        self::assertStringNotContainsString('geheim', (string) json_encode($result));
    }

    public function testGatewayInterfaceOnlyInItsFormat(): void
    {
        self::assertNull(AuthProbe::describe('cli', ['GATEWAY_INTERFACE' => 'Bearer x'], [])['gateway_interface']);
        self::assertNull(AuthProbe::describe('cli', [], [])['gateway_interface']);
    }
}
