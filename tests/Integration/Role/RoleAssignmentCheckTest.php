<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Role;

use FirefightingEquipment\Cli\RoleAssignmentCheckCommand;
use FirefightingEquipment\Cli\TextTable;
use FirefightingEquipment\Role\RoleAssignmentCheck;
use FirefightingEquipment\Role\RoleAssignmentGroup;
use FirefightingEquipment\Role\RoleAssignmentReport;
use FirefightingEquipment\Tests\Integration\User\AuthSchemaTestCase;
use FirefightingEquipment\Tests\Support\TestDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Probelauf zu Migration 0036 (bin/role-assignment-check.php): Konten ohne Rolle werden je Stadt,
 * Kontoart und Status gezählt, Probleme für 0037 erkannt; es wird nichts geändert. Den Stand vor
 * 0036 (Konten ohne Rolle) legt der Test mit abgeschalteten CHECKs an.
 */
#[CoversClass(RoleAssignmentCheck::class)]
#[CoversClass(RoleAssignmentGroup::class)]
#[CoversClass(RoleAssignmentReport::class)]
#[CoversClass(RoleAssignmentCheckCommand::class)]
#[UsesClass(TextTable::class)]
final class RoleAssignmentCheckTest extends AuthSchemaTestCase
{
    use LegacyRoleAccounts;

    public function testCountsAccountsWithoutRolePerTenantKindAndStatus(): void
    {
        $this->legacyAccounts();

        $report = new RoleAssignmentCheck($this->pdo())->run();

        self::assertSame([
            ['immendingen', 'admin', 'active', 1],
            ['immendingen', 'admin', 'invited', 1],
            ['immendingen', 'user', 'active', 2],
            ['immendingen', 'user', 'deleted', 1],
            ['immendingen', 'user', 'pending', 1],
            ['immendingen', 'user', 'unverified', 1],
            ['tuttlingen', 'user', 'active', 1],
        ], self::rows($report->toAssign));
        self::assertSame(8, $report->totalToAssign());
        self::assertSame([], $report->unassigned);
        self::assertSame(1, $report->alreadyAssigned);
        self::assertSame(0, $report->mismatched);
        self::assertSame(1, $report->superAdmins);
        self::assertSame(0, $report->superAdminsWithRole);
        self::assertTrue($report->isConsistentAfterMigration());
    }

    public function testFindsProblemsFor0037(): void
    {
        $this->legacyAccounts();
        $bare = $this->createTenant('geisingen', false);
        $this->withoutChecks(function () use ($bare): void {
            $this->createUser($bare, ['role_id' => null]);
            $this->createSuperadminWithRole();
            // Kontoart admin, aber Rolle Benutzer.
            $this->createUser($this->tenantId('tuttlingen'), ['role' => 'admin', 'role_id' => $this->systemRoleId($this->tenantId('tuttlingen'), 'user')]);
        });

        $report = new RoleAssignmentCheck($this->pdo())->run();

        self::assertSame([['geisingen', 'user', 'active', 1]], self::rows($report->unassigned));
        self::assertSame(1, $report->totalUnassigned());
        self::assertSame(1, $report->superAdminsWithRole);
        self::assertSame(1, $report->mismatched);
        self::assertFalse($report->isConsistentAfterMigration());

        // Sonst meldete DomainTestCase das Konto mit unpassender Rolle beim nächsten Test.
        TestDatabase::clearDomainTables($this->pdo());
    }

    public function testChangesNothing(): void
    {
        $this->legacyAccounts();
        $before = $this->pdo()->query('SELECT * FROM fe_users ORDER BY id')->fetchAll();

        new RoleAssignmentCheck($this->pdo())->run();

        self::assertSame($before, $this->pdo()->query('SELECT * FROM fe_users ORDER BY id')->fetchAll());
    }

    public function testCommandPrintsTableAndExitCode(): void
    {
        $this->legacyAccounts();

        [$exitCode, $out, $err] = $this->runCommand([]);

        self::assertSame(RoleAssignmentCheckCommand::EXIT_OK, $exitCode, $err);
        self::assertSame('', $err);
        self::assertStringContainsString('Probelauf Migration 0036 (es wird nichts geändert)', $out);
        self::assertStringContainsString('Konten, die eine Rolle bekämen: 8', $out);
        self::assertMatchesRegularExpression('/^  immendingen +user +active +Benutzer +2\r?$/m', $out);
        self::assertMatchesRegularExpression('/^  immendingen +admin +invited +Admin +1\r?$/m', $out);
        self::assertStringContainsString('Konten einer Stadt, die danach ohne Rolle blieben: 0', $out);
        self::assertStringContainsString('Super-Admins: 1, davon mit Rolle: 0', $out);
        self::assertStringContainsString('Migration 0037 läuft durch.', $out);

        $this->withoutChecks(fn () => $this->createUser($this->createTenant('geisingen', false), ['role_id' => null]));
        [$exitCode, $out] = $this->runCommand([]);

        self::assertSame(RoleAssignmentCheckCommand::EXIT_PROBLEMS, $exitCode);
        self::assertMatchesRegularExpression('/^  geisingen +user +active +– \(keine Standard-Rolle\) +1\r?$/m', $out);
        self::assertStringContainsString('Ergebnis: PROBLEME', $out);
    }

    public function testCommandRejectsInvalidCallsWithoutConnecting(): void
    {
        foreach ([['--slug=test'], ['--config=a.php', '--config=b.php'], ['--config=']] as $args) {
            [$exitCode, $out, $err] = $this->runCommand($args, 'gibt-es-nicht.php');

            self::assertSame(RoleAssignmentCheckCommand::EXIT_USAGE, $exitCode, implode(' ', $args));
            self::assertSame('', $out);
            self::assertStringStartsWith('FEHLER: ', $err);
        }

        [$exitCode, , $err] = $this->runCommand(['--config=gibt-es-nicht.php']);
        self::assertSame(RoleAssignmentCheckCommand::EXIT_ERROR, $exitCode);
        self::assertStringStartsWith('FEHLER: ', $err);
    }

    /**
     * @param list<RoleAssignmentGroup> $groups
     *
     * @return list<array{string, string, string, int}>
     */
    private static function rows(array $groups): array
    {
        return array_map(
            static fn (RoleAssignmentGroup $g): array => [$g->tenantSlug, $g->accountRole, $g->status, $g->accounts],
            $groups,
        );
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string, string}
     */
    private function runCommand(array $args, string $defaultConfig = TestDatabase::CONFIG_PATH): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);

        $exitCode = new RoleAssignmentCheckCommand($defaultConfig, $out, $err)->run($args);
        rewind($out);
        rewind($err);
        $result = [$exitCode, (string) stream_get_contents($out), (string) stream_get_contents($err)];
        fclose($out);
        fclose($err);

        return $result;
    }
}
