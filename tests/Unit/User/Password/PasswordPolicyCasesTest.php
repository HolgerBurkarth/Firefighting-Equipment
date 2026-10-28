<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\User\Password;

use FirefightingEquipment\Tenant\Tenant;
use FirefightingEquipment\Tenant\TenantName;
use FirefightingEquipment\Tenant\TenantSlug;
use FirefightingEquipment\User\EmailAddress;
use FirefightingEquipment\User\Password\PasswordBlocklist;
use FirefightingEquipment\User\Password\PasswordContext;
use FirefightingEquipment\User\Password\PasswordPolicy;
use FirefightingEquipment\User\Password\WeakPasswordException;
use FirefightingEquipment\User\PersonName;
use FirefightingEquipment\User\UserException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Gemeinsame Testfälle mit dem Frontend (tests/Fixtures/password-policy-cases.json): Dieselbe Datei
 * prüft Vitest gegen frontend/src/auth/passwordRules.ts. So ist belegt, dass die Live-Prüfung im
 * Frontend nie strenger ist als PasswordPolicy (mit der echten Sperrliste).
 */
#[CoversClass(PasswordPolicy::class)]
#[UsesClass(PasswordContext::class)]
#[UsesClass(PasswordBlocklist::class)]
#[UsesClass(WeakPasswordException::class)]
#[UsesClass(UserException::class)]
#[UsesClass(EmailAddress::class)]
#[UsesClass(PersonName::class)]
#[UsesClass(Tenant::class)]
#[UsesClass(TenantName::class)]
#[UsesClass(TenantSlug::class)]
final class PasswordPolicyCasesTest extends TestCase
{
    public const string CASES_PATH = __DIR__ . '/../../../Fixtures/password-policy-cases.json';

    /** Ergebnis je Meldung von PasswordPolicy. */
    private const array RESULTS = [
        'Das Passwort muss mindestens 10 Zeichen lang sein.' => 'too_short',
        'Das Passwort darf höchstens 128 Zeichen lang sein.' => 'too_long',
        'Das Passwort darf keine Steuerzeichen (z. B. Zeilenumbrüche oder Tabulatoren) enthalten.' => 'control',
        'Dieses Passwort ist zu verbreitet und leicht zu erraten. Bitte ein anderes wählen.' => 'blocklist',
        'Das Passwort darf keine Teile der E-Mail-Adresse, des Namens oder des Stadtnamens enthalten.' => 'personal',
    ];

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function cases(): iterable
    {
        $data = json_decode((string) file_get_contents(self::CASES_PATH), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsArray($data['cases']);
        foreach ($data['cases'] as $case) {
            self::assertIsArray($case);
            self::assertIsString($case['description']);
            yield $case['description'] => [$case];
        }
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('cases')]
    public function testServerResult(array $case): void
    {
        self::assertIsString($case['password']);
        try {
            PasswordPolicy::default()->check($case['password'], self::context($case));
            $result = 'ok';
        } catch (WeakPasswordException $e) {
            $result = self::RESULTS[$e->getMessage()] ?? 'unbekannt: ' . $e->getMessage();
        }

        self::assertSame($case['server'], $result);
    }

    public function testCoversEveryResult(): void
    {
        $results = array_map(static fn (array $args): mixed => $args[0]['server'], iterator_to_array(self::cases()));

        foreach (['ok', ...array_values(self::RESULTS)] as $expected) {
            self::assertContains($expected, $results);
        }
    }

    /**
     * @param array<string, mixed> $case
     */
    private static function context(array $case): PasswordContext
    {
        $email = $case['email'];
        $names = $case['names'];
        $tenant = $case['tenant'];
        self::assertTrue($email === null || is_string($email));
        self::assertIsArray($names);
        self::assertTrue($tenant === null || is_array($tenant));

        return new PasswordContext(
            $email === null ? null : EmailAddress::fromString($email),
            array_map(static fn (mixed $name): PersonName => PersonName::fromString((string) $name), $names),
            $tenant === null ? null : self::tenant((string) $tenant['slug'], (string) $tenant['name']),
        );
    }

    private static function tenant(string $slug, string $name): Tenant
    {
        $now = new \DateTimeImmutable('2026-01-01 12:00:00', new \DateTimeZone('UTC'));

        return new Tenant(1, TenantSlug::fromString($slug), TenantName::fromString($name), true, $now, $now);
    }
}
