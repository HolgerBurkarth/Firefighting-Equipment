<?php

declare(strict_types=1);

namespace FirefightingEquipment\Cli;

use FirefightingEquipment\Config\ConfigException;
use FirefightingEquipment\Config\ConfigLoader;
use FirefightingEquipment\Database\ConnectionException;
use FirefightingEquipment\Database\ConnectionFactory;
use FirefightingEquipment\Database\DatabaseError;
use FirefightingEquipment\Role\RoleAssignmentCheck;
use FirefightingEquipment\Role\RoleAssignmentGroup;
use FirefightingEquipment\Role\RoleAssignmentReport;

/**
 * Probelauf zu Migration 0036 (bin/role-assignment-check.php): zeigt je Stadt, Kontoart und Status,
 * wie viele Konten eine Rolle bekämen, und ob 0037 danach durchläuft. Ändert nichts (nur SELECT).
 * Exit-Code 0 = alles passt, 3 = 0037 schlüge fehl oder Rollen passen nicht zur Kontoart, 1 = Fehler,
 * 2 = falscher Aufruf.
 */
final readonly class RoleAssignmentCheckCommand
{
    public const int EXIT_OK = 0;
    public const int EXIT_ERROR = 1;
    public const int EXIT_USAGE = 2;
    public const int EXIT_PROBLEMS = 3;

    private const string USAGE = <<<'TXT'
        Aufruf:
          php bin/role-assignment-check.php [--config=PFAD]

        Probelauf zu Migration 0036: zählt je Stadt, Kontoart und Status die Konten, die ihre
        Standard-Rolle (Admin bzw. Benutzer) bekämen, und prüft, ob danach jedes Konto einer Stadt
        eine Rolle hat und kein Super-Admin eine (Voraussetzung für Migration 0037).
        Ändert nichts. Exit-Code 0 = passt, 3 = Probleme gefunden.
        Standard-Konfiguration: config/config.local.php
        Auf dem Server immer php85 statt php verwenden.

        TXT;

    /**
     * @param resource $out
     * @param resource $err
     */
    public function __construct(
        private string $defaultConfigPath,
        private mixed $out,
        private mixed $err,
    ) {
    }

    /**
     * @param list<string> $args Argumente ohne Skriptnamen
     */
    public function run(array $args): int
    {
        $config = null;
        foreach ($args as $arg) {
            if ($arg === '--help' || $arg === '-h' || $arg === 'help') {
                fwrite($this->out, self::USAGE);

                return self::EXIT_OK;
            }
            $problem = match (true) {
                preg_match('/^--config=(.+)$/sD', $arg, $match) !== 1 => sprintf('Unerwartetes Argument: %s', $arg),
                $config !== null => '--config ist doppelt angegeben.',
                default => null,
            };
            if ($problem !== null) {
                fwrite($this->err, 'FEHLER: ' . $problem . PHP_EOL . PHP_EOL . self::USAGE);

                return self::EXIT_USAGE;
            }
            $config = $match[1];
        }

        try {
            $dbConfig = new ConfigLoader()->loadDatabaseConfig($config ?? $this->defaultConfigPath);
            $report = new RoleAssignmentCheck(new ConnectionFactory()->create($dbConfig))->run();
        } catch (ConfigException | ConnectionException $e) {
            fwrite($this->err, 'FEHLER: ' . $e->getMessage() . PHP_EOL);

            return self::EXIT_ERROR;
        } catch (\PDOException $e) {
            fwrite($this->err, 'FEHLER: Datenbankfehler (' . DatabaseError::describe($e) . ').' . PHP_EOL);

            return self::EXIT_ERROR;
        }

        $this->print($report);

        return $report->isConsistentAfterMigration() ? self::EXIT_OK : self::EXIT_PROBLEMS;
    }

    private function print(RoleAssignmentReport $report): void
    {
        $this->line('Probelauf Migration 0036 (es wird nichts geändert)');
        $this->line('');
        $this->line(sprintf('Konten, die eine Rolle bekämen: %d', $report->totalToAssign()));
        $this->table($report->toAssign, static fn (RoleAssignmentGroup $g): string => $g->accountRole === 'admin' ? 'Admin' : 'Benutzer');
        $this->line('');
        $this->line(sprintf('Konten einer Stadt, die schon eine Rolle haben: %d', $report->alreadyAssigned));
        $this->line(sprintf('Konten einer Stadt, die danach ohne Rolle blieben: %d', $report->totalUnassigned()));
        $this->table($report->unassigned, static fn (): string => '– (keine Standard-Rolle)');
        $this->line(sprintf('Konten, deren Rolle nicht zur Kontoart passt: %d', $report->mismatched));
        $this->line(sprintf('Super-Admins: %d, davon mit Rolle: %d', $report->superAdmins, $report->superAdminsWithRole));
        $this->line('');
        $this->line($report->isConsistentAfterMigration()
            ? 'Ergebnis: Danach hat jedes Konto einer Stadt eine passende Rolle und kein Super-Admin eine; Migration 0037 läuft durch.'
            : 'Ergebnis: PROBLEME – Migration 0037 schlüge fehl bzw. Rollen passen nicht zur Kontoart. Nicht migrieren.');
    }

    /**
     * @param list<RoleAssignmentGroup>              $groups
     * @param \Closure(RoleAssignmentGroup): string $roleName
     */
    private function table(array $groups, \Closure $roleName): void
    {
        if ($groups === []) {
            return;
        }
        $rows = [['Stadt', 'Kontoart', 'Status', 'Rolle danach', 'Konten']];
        foreach ($groups as $group) {
            $rows[] = [$group->tenantSlug, $group->accountRole, $group->status, $roleName($group), (string) $group->accounts];
        }
        foreach (TextTable::render($rows) as $line) {
            $this->line('  ' . $line);
        }
    }

    private function line(string $text): void
    {
        fwrite($this->out, $text . PHP_EOL);
    }
}
