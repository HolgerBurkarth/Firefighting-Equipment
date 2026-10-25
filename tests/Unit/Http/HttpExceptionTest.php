<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Http;

use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HttpException::class)]
#[UsesClass(JsonResponse::class)]
final class HttpExceptionTest extends TestCase
{
    public function testInvalidInvitationCode(): void
    {
        $response = HttpException::invalidInvitationCode()->toResponse();

        self::assertSame(400, $response->statusCode());
        self::assertSame(
            '{"error":"invalid_invitation_code","message":"Der Einladungscode ist ungültig, abgelaufen, aufgebraucht oder widerrufen. Bitte wenden Sie sich an Ihre Feuerwehr."}',
            $response->body(),
        );
    }

    public function testRuleViolationCarriesMessage(): void
    {
        $response = HttpException::ruleViolation('Sie können sich nicht selbst sperren.')->toResponse();

        self::assertSame(409, $response->statusCode());
        self::assertSame('{"error":"rule_violation","message":"Sie können sich nicht selbst sperren."}', $response->body());
    }
}
