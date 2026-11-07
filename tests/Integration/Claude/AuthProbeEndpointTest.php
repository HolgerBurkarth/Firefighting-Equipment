<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Claude;

use FirefightingEquipment\Api\V1\OAuth\AuthProbeHandler;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Vorübergehende Prüf-Route GET api/v1/auth-probe (Live-Test Runde 5): nur Test-Stadt, nur Namen.
 */
#[CoversClass(AuthProbeHandler::class)]
final class AuthProbeEndpointTest extends ClaudeOAuthTestCase
{
    public function testShowsNamesButNoValuesInTheTestCity(): void
    {
        $saved = $_SERVER;
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer ffa_geheim';
        try {
            $response = $this->send('GET', self::AREA . 'api/v1/auth-probe');
        } finally {
            $_SERVER = $saved;
        }

        self::assertSame(200, $response->statusCode());
        self::assertStringNotContainsString('geheim', $response->body());
        $data = self::json($response);
        self::assertSame(PHP_SAPI, $data['sapi']);
        self::assertIsArray($data['server']);
        self::assertTrue($data['server']['REDIRECT_HTTP_AUTHORIZATION']);
        self::assertIsArray($data['env']);
    }

    public function testDoesNotExistInOtherAreas(): void
    {
        self::assertError(404, 'not_found', $this->send('GET', '/firefighting/immendingen/api/v1/auth-probe'));
        self::assertError(404, 'not_found', $this->send('GET', '/firefighting/service/api/v1/auth-probe'));
        self::assertError(405, 'method_not_allowed', $this->send('POST', self::AREA . 'api/v1/auth-probe', []));
    }
}
