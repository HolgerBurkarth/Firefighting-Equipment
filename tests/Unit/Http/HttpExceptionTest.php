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

    public function testRuleViolationWithDetails(): void
    {
        $response = HttpException::ruleViolation('Nicht leer.', ['devices' => 3, 'sublocations' => 1])->toResponse();

        self::assertSame(409, $response->statusCode());
        self::assertSame(
            '{"error":"rule_violation","message":"Nicht leer.","details":{"devices":3,"sublocations":1}}',
            $response->body(),
        );
    }

    public function testNameTakenIsConflict(): void
    {
        $response = HttpException::nameTaken('Es gibt bereits einen Gerätetyp „Funkgerät“.')->toResponse();

        self::assertSame(409, $response->statusCode());
        self::assertSame('{"error":"name_taken","message":"Es gibt bereits einen Gerätetyp „Funkgerät“."}', $response->body());
    }

    public function testIdentifierTakenIsConflict(): void
    {
        $response = HttpException::identifierTaken('Die Kennung „MA-01“ ist bereits vergeben.')->toResponse();

        self::assertSame(409, $response->statusCode());
        self::assertSame('{"error":"identifier_taken","message":"Die Kennung „MA-01“ ist bereits vergeben."}', $response->body());
    }

    public function testScanCodeTakenIsConflict(): void
    {
        $response = HttpException::scanCodeTaken('Der Scan-Code „4006381333931“ ist bereits vergeben.')->toResponse();

        self::assertSame(409, $response->statusCode());
        self::assertSame('{"error":"scan_code_taken","message":"Der Scan-Code „4006381333931“ ist bereits vergeben."}', $response->body());
    }

    public function testVersionConflictCarriesCurrentState(): void
    {
        $response = HttpException::versionConflict('Inzwischen geändert.', ['device' => ['id' => 7, 'version' => 3]])->toResponse();

        self::assertSame(409, $response->statusCode());
        self::assertSame(
            '{"error":"version_conflict","message":"Inzwischen geändert.","details":{"device":{"id":7,"version":3}}}',
            $response->body(),
        );
    }

    public function testImportOutdatedIsConflictWithOptionalDetails(): void
    {
        $response = HttpException::importOutdated('Vorschau neu erstellen.', ['summary' => ['errors' => 1]])->toResponse();
        self::assertSame(409, $response->statusCode());
        self::assertSame('{"error":"import_outdated","message":"Vorschau neu erstellen.","details":{"summary":{"errors":1}}}', $response->body());

        self::assertSame('{"error":"import_outdated","message":"Vorschau neu erstellen."}', HttpException::importOutdated('Vorschau neu erstellen.')->toResponse()->body());
    }

    public function testOfflineBookingCodes(): void
    {
        $response = HttpException::clientRefConflict('Andere Buchung.')->toResponse();
        self::assertSame(409, $response->statusCode());
        self::assertSame('{"error":"client_ref_conflict","message":"Andere Buchung."}', $response->body());

        $response = HttpException::staleAction('Alt.', ['queue_max_age_days' => 7])->toResponse();
        self::assertSame(409, $response->statusCode());
        self::assertSame('{"error":"stale_action","message":"Alt.","details":{"queue_max_age_days":7}}', $response->body());

        $response = HttpException::clockImplausible('Uhr falsch.', ['max_skew_seconds' => 300])->toResponse();
        self::assertSame(422, $response->statusCode());
        self::assertSame('{"error":"clock_implausible","message":"Uhr falsch.","details":{"max_skew_seconds":300}}', $response->body());
    }

    public function testInvalidRequestWithDetails(): void
    {
        $response = HttpException::invalidRequest('Zeile 4 ist kaputt.', ['line' => 4])->toResponse();

        self::assertSame(400, $response->statusCode());
        self::assertSame('{"error":"invalid_request","message":"Zeile 4 ist kaputt.","details":{"line":4}}', $response->body());
    }

    public function testSlugAndEmailTakenAreConflicts(): void
    {
        $slug = HttpException::slugTaken('Das Kürzel „immendingen“ ist bereits vergeben.')->toResponse();
        $email = HttpException::emailTaken('Es gibt bereits ein Konto.')->toResponse();

        self::assertSame(409, $slug->statusCode());
        self::assertSame('{"error":"slug_taken","message":"Das Kürzel „immendingen“ ist bereits vergeben."}', $slug->body());
        self::assertSame(409, $email->statusCode());
        self::assertSame('{"error":"email_taken","message":"Es gibt bereits ein Konto."}', $email->body());
    }
}
