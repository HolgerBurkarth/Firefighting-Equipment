<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Device;

use FirefightingEquipment\Device\DeviceIdentifier;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Gemeinsame Testfälle mit dem Frontend (tests/Fixtures/identifier-key-cases.json): server ist die
 * Spalte identifier_key, die MariaDB aus der Kennung berechnet (nach DeviceIdentifier::fromString).
 * Dieselbe Datei prüft Vitest gegen codeKey in frontend/src/data/deviceSearch.ts; ohne intl können
 * LOWER() und toLowerCase() bei seltenen Buchstaben verschieden kleinschreiben.
 */
#[CoversNothing]
final class IdentifierKeyCasesTest extends DeviceSchemaTestCase
{
    public const string CASES_PATH = __DIR__ . '/../../Fixtures/identifier-key-cases.json';

    /**
     * @return iterable<string, array{string, string}>
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
            self::assertIsString($case['server']);
            yield $case['description'] => [$case['input'], $case['server']];
        }
    }

    #[DataProvider('cases')]
    public function testServerKey(string $input, string $expected): void
    {
        $tenantId = $this->createTenant();
        $deviceId = $this->createDevice(
            $tenantId,
            $this->createDeviceType($tenantId),
            $this->createLocation($tenantId),
            ['identifier' => DeviceIdentifier::fromString($input)->value],
        );

        self::assertSame(bin2hex($expected), bin2hex((string) $this->deviceValue($deviceId, 'identifier_key')));
    }

    /** Jeder Buchstabe mit Großschreibung einzeln, mit demselben Ausdruck wie identifier_key. */
    public function testAllUppercaseLetters(): void
    {
        $data = json_decode((string) file_get_contents(self::CASES_PATH), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsArray($data['letters']);
        $input = $data['letters']['input'];
        $expected = $data['letters']['server'];
        self::assertIsString($input);
        self::assertIsString($expected);
        self::assertGreaterThan(1800, mb_strlen($input));

        $stmt = $this->pdo()->prepare(
            "SELECT REPLACE(LOWER(CAST(? AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci), 'ẞ', 'ß')",
        );
        $stmt->execute([$input]);
        $actual = (string) $stmt->fetchColumn();

        // Je Buchstabe vergleichen, damit eine Abweichung die Stelle nennt.
        $inputChars = mb_str_split($input);
        $actualChars = mb_str_split($actual);
        self::assertCount(count($inputChars), $actualChars);
        foreach (mb_str_split($expected) as $index => $char) {
            self::assertSame($char, $actualChars[$index] ?? null, sprintf('U+%04X', mb_ord($inputChars[$index])));
        }
    }

    /** Der Ausdruck oben ist derselbe wie in der Spalte (Migration). */
    public function testColumnUsesSameExpression(): void
    {
        $stmt = $this->pdo()->query(
            "SELECT GENERATION_EXPRESSION FROM information_schema.COLUMNS"
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fe_devices' AND COLUMN_NAME = 'identifier_key'",
        );
        self::assertNotFalse($stmt);
        $expression = (string) $stmt->fetchColumn();

        self::assertStringContainsString('lcase(`identifier`)', strtolower($expression));
        self::assertStringContainsString('ẞ', $expression);
    }
}
