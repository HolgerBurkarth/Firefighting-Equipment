<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Invitation;

use FirefightingEquipment\Invitation\InvitationCodeValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Gemeinsame Testfälle mit dem Frontend (tests/Fixtures/invitation-code-cases.json): Dieselbe Datei
 * prüft Vitest gegen frontend/src/auth/invitationCode.ts. So ist belegt, dass die Prüfung im
 * Frontend vor dem Absenden nie strenger ist als InvitationCodeValue::fromInput.
 */
#[CoversClass(InvitationCodeValue::class)]
final class InvitationCodeCasesTest extends TestCase
{
    public const string CASES_PATH = __DIR__ . '/../../Fixtures/invitation-code-cases.json';

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function cases(): iterable
    {
        $data = json_decode((string) file_get_contents(self::CASES_PATH), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsArray($data['cases']);
        foreach ($data['cases'] as $case) {
            self::assertIsArray($case);
            self::assertIsString($case['description']);
            self::assertIsString($case['input']);
            self::assertTrue($case['server'] === null || is_string($case['server']));
            yield $case['description'] => [$case['input'], $case['server']];
        }
    }

    #[DataProvider('cases')]
    public function testServerResult(string $input, ?string $expected): void
    {
        self::assertSame($expected, InvitationCodeValue::fromInput($input)?->value());
    }

    public function testCoversAcceptedAndRejectedInput(): void
    {
        $results = array_map(static fn (array $case): ?string => $case[1], iterator_to_array(self::cases()));

        self::assertContains(null, $results);
        self::assertNotEmpty(array_filter($results, is_string(...)));
    }
}
