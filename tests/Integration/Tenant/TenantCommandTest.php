<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Tenant;

use FirefightingEquipment\Cli\TenantCommand;
use FirefightingEquipment\Config\ConfigLoader;
use FirefightingEquipment\Database\ConnectionFactory;
use FirefightingEquipment\Database\DatabaseConfig;
use FirefightingEquipment\Tenant\DuplicateTenantSlugException;
use FirefightingEquipment\Tenant\Tenant;
use FirefightingEquipment\Tenant\TenantException;
use FirefightingEquipment\Tenant\TenantName;
use FirefightingEquipment\Tenant\TenantNotFoundException;
use FirefightingEquipment\Tenant\TenantRepository;
use FirefightingEquipment\Tenant\TenantSlug;
use FirefightingEquipment\Tests\Integration\DomainTestCase;
use FirefightingEquipment\Tests\Support\TestDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(TenantCommand::class)]
#[UsesClass(ConfigLoader::class)]
#[UsesClass(ConnectionFactory::class)]
#[UsesClass(DatabaseConfig::class)]
#[UsesClass(DuplicateTenantSlugException::class)]
#[UsesClass(Tenant::class)]
#[UsesClass(TenantException::class)]
#[UsesClass(TenantName::class)]
#[UsesClass(TenantNotFoundException::class)]
#[UsesClass(TenantRepository::class)]
#[UsesClass(TenantSlug::class)]
final class TenantCommandTest extends DomainTestCase
{
    public function testListWithoutTenants(): void
    {
        [$exitCode, $out, $err] = $this->runCommand(['list']);

        self::assertSame(TenantCommand::EXIT_OK, $exitCode);
        self::assertSame('Keine Städte vorhanden.' . PHP_EOL, $out);
        self::assertSame('', $err);
    }

    public function testCreateThenList(): void
    {
        [$exitCode, $out, $err] = $this->runCommand(['create', '--slug=immendingen', '--name=Feuerwehr Immendingen']);

        self::assertSame(TenantCommand::EXIT_OK, $exitCode);
        self::assertMatchesRegularExpression('/^Stadt angelegt: immendingen \(ID \d+, „Feuerwehr Immendingen“\)\r?$/m', $out);
        self::assertSame('', $err);

        $this->runCommand(['create', '--slug=bad-duerrheim', '--name=  Feuerwehr Bad Dürrheim  ']);
        [$exitCode, $out] = $this->runCommand(['list']);

        self::assertSame(TenantCommand::EXIT_OK, $exitCode);
        $lines = explode(PHP_EOL, rtrim($out));
        self::assertCount(4, $lines);
        self::assertMatchesRegularExpression('/^ID\s+Kürzel\s+Status\s+Angelegt \(UTC\)\s+Name$/u', $lines[0]);
        self::assertMatchesRegularExpression(
            '/^\d+\s+bad-duerrheim\s+aktiv\s+\d{4}-\d\d-\d\d \d\d:\d\d:\d\d  Feuerwehr Bad Dürrheim$/u',
            $lines[1],
        );
        self::assertMatchesRegularExpression('/^\d+\s+immendingen\s+aktiv\s+\S+ \S+  Feuerwehr Immendingen$/u', $lines[2]);
        self::assertSame('2 Städte.', $lines[3]);
        // Spalten stehen trotz Umlaut in der Überschrift untereinander.
        self::assertSame(mb_strpos($lines[0], 'Status'), mb_strpos($lines[1], 'aktiv'));
    }

    public function testDuplicateSlugGivesExitCode1(): void
    {
        $this->runCommand(['create', '--slug=immendingen', '--name=Feuerwehr Immendingen']);

        [$exitCode, $out, $err] = $this->runCommand(['create', '--slug=immendingen', '--name=Andere']);

        self::assertSame(TenantCommand::EXIT_ERROR, $exitCode);
        self::assertSame('', $out);
        self::assertSame('FEHLER: Das Kürzel „immendingen“ ist bereits vergeben.' . PHP_EOL, $err);
        self::assertSame(1, $this->pdo()->query('SELECT COUNT(*) FROM fe_tenants')->fetchColumn());
    }

    public function testUppercaseSlugIsRejectedAndNothingIsCreated(): void
    {
        [$exitCode, , $err] = $this->runCommand(['create', '--slug=Immendingen', '--name=Feuerwehr Immendingen']);

        self::assertSame(TenantCommand::EXIT_ERROR, $exitCode);
        self::assertStringContainsString('Gemeint ist vermutlich „immendingen“.', $err);
        self::assertSame(0, $this->pdo()->query('SELECT COUNT(*) FROM fe_tenants')->fetchColumn());
    }

    public function testDeactivateAndActivate(): void
    {
        $this->runCommand(['create', '--slug=immendingen', '--name=Feuerwehr Immendingen']);

        [$exitCode, $out] = $this->runCommand(['deactivate', '--slug=immendingen']);
        self::assertSame(TenantCommand::EXIT_OK, $exitCode);
        self::assertSame('Stadt gesperrt: immendingen' . PHP_EOL, $out);
        self::assertStringContainsString('immendingen  gesperrt', $this->runCommand(['list'])[1]);

        [$exitCode, $out] = $this->runCommand(['deactivate', '--slug=immendingen']);
        self::assertSame(TenantCommand::EXIT_OK, $exitCode);
        self::assertSame('Stadt ist bereits gesperrt: immendingen' . PHP_EOL, $out);

        [$exitCode, $out] = $this->runCommand(['activate', '--slug=immendingen']);
        self::assertSame(TenantCommand::EXIT_OK, $exitCode);
        self::assertSame('Stadt entsperrt: immendingen' . PHP_EOL, $out);
        self::assertStringContainsString('immendingen  aktiv', $this->runCommand(['list'])[1]);

        [$exitCode, $out] = $this->runCommand(['activate', '--slug=immendingen']);
        self::assertSame(TenantCommand::EXIT_OK, $exitCode);
        self::assertSame('Stadt ist bereits aktiv: immendingen' . PHP_EOL, $out);
    }

    public function testUnknownSlugGivesExitCode1(): void
    {
        [$exitCode, $out, $err] = $this->runCommand(['deactivate', '--slug=tuttlingen']);

        self::assertSame(TenantCommand::EXIT_ERROR, $exitCode);
        self::assertSame('', $out);
        self::assertSame('FEHLER: Keine Stadt mit dem Kürzel „tuttlingen“ gefunden.' . PHP_EOL, $err);
    }

    public function testConfigOptionOverridesDefault(): void
    {
        [$out, $err] = $this->streams();
        $command = new TenantCommand('gibt-es-nicht.php', $out, $err);

        try {
            self::assertSame(TenantCommand::EXIT_OK, $command->run(['list', '--config=' . TestDatabase::CONFIG_PATH]));
        } finally {
            fclose($out);
            fclose($err);
        }
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string, string}
     */
    private function runCommand(array $args): array
    {
        [$out, $err] = $this->streams();

        $exitCode = new TenantCommand(TestDatabase::CONFIG_PATH, $out, $err)->run($args);

        rewind($out);
        rewind($err);
        $result = [$exitCode, (string) stream_get_contents($out), (string) stream_get_contents($err)];
        fclose($out);
        fclose($err);

        return $result;
    }

    /**
     * @return array{resource, resource}
     */
    private function streams(): array
    {
        return [
            fopen('php://memory', 'w+') ?: throw new \RuntimeException('php://memory'),
            fopen('php://memory', 'w+') ?: throw new \RuntimeException('php://memory'),
        ];
    }
}
