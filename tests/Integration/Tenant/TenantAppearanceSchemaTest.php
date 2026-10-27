<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Tenant;

use FirefightingEquipment\Tenant\Logo\PngInspector;
use FirefightingEquipment\Tests\Integration\User\AuthSchemaTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Migrationen 0021 (fe_tenants.accent_color) und 0022 (fe_tenant_logos): Die Datenbank lehnt
 * ungültige Werte auch am Code vorbei ab.
 */
#[CoversNothing]
final class TenantAppearanceSchemaTest extends AuthSchemaTestCase
{
    private const string PNG = PngInspector::SIGNATURE . 'rest';

    public function testAccentColorIsNullByDefaultAndAcceptsLowercaseHex(): void
    {
        $tenant = $this->createTenant();
        self::assertNull($this->pdo()->query('SELECT accent_color FROM fe_tenants')->fetchColumn());

        $this->pdo()->prepare('UPDATE fe_tenants SET accent_color = ? WHERE id = ?')->execute(['#b71c1c', $tenant]);
        self::assertSame('#b71c1c', $this->pdo()->query('SELECT accent_color FROM fe_tenants')->fetchColumn());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidColors(): iterable
    {
        yield 'Großbuchstaben' => ['#B71C1C'];
        yield 'ohne #' => ['b71c1c '];
        yield 'zu kurz' => ['#b71c1'];
        yield 'kurz' => ['#abc'];
        yield 'Zeilenumbruch am Ende' => ["#b71c1\n"];
        yield 'kein Hex' => ['#b71c1g'];
        yield 'leer' => [''];
    }

    #[DataProvider('invalidColors')]
    public function testDatabaseRejectsInvalidAccentColor(string $color): void
    {
        $tenant = $this->createTenant();

        $this->assertRejected(self::ER_CONSTRAINT_FAILED, fn () => $this->pdo()
            ->prepare('UPDATE fe_tenants SET accent_color = ? WHERE id = ?')->execute([$color, $tenant]));
    }

    public function testValidLogoRowIsAccepted(): void
    {
        $tenant = $this->createTenant();
        $this->insertLogo($tenant, $this->createUser($tenant));

        self::assertSame(1, $this->countRows('fe_tenant_logos'));
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function invalidLogoValues(): iterable
    {
        yield 'Version groß' => [['version' => '0123456789ABCDEF']];
        yield 'Version zu kurz' => [['version' => '0123456789abcde']];
        yield 'Version kein Hex' => [['version' => '0123456789abcdeg']];
        yield '512 keine PNG' => [['png_512' => 'GIF89a']];
        yield '192 leer' => [['png_192' => '']];
        yield '180 keine PNG' => [['png_180' => "\x89PNG"]];
    }

    /**
     * @param array<string, string> $overrides
     */
    #[DataProvider('invalidLogoValues')]
    public function testDatabaseRejectsInvalidLogo(array $overrides): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $this->assertRejected(self::ER_CONSTRAINT_FAILED, fn () => $this->insertLogo($tenant, $user, $overrides));
    }

    public function testOneLogoPerTenant(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $this->insertLogo($tenant, $user);

        $this->assertRejected(self::ER_DUP_ENTRY, fn () => $this->insertLogo($tenant, $user));
    }

    public function testUpdatedByMustBelongToTheSameTenant(): void
    {
        $tenant = $this->createTenant();
        $otherUser = $this->createUser($this->createTenant('tuttlingen'));

        $this->assertRejected(self::ER_NO_REFERENCED_ROW, fn () => $this->insertLogo($tenant, $otherUser));
        $this->assertRejected(self::ER_NO_REFERENCED_ROW, fn () => $this->insertLogo($tenant, $this->createSuperadmin()));
    }

    /**
     * @param array<string, string> $overrides
     */
    private function insertLogo(int $tenant, int $user, array $overrides = []): void
    {
        $this->insert('fe_tenant_logos', [
            'tenant_id' => $tenant,
            'version' => '0123456789abcdef',
            'png_512' => self::PNG,
            'png_192' => self::PNG,
            'png_180' => self::PNG,
            'updated_by' => $user,
            'updated_at' => self::CREATED_AT,
            ...$overrides,
        ]);
    }
}
