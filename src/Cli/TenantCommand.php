<?php

declare(strict_types=1);

namespace FirefightingEquipment\Cli;

use FirefightingEquipment\Config\ConfigException;
use FirefightingEquipment\Config\ConfigLoader;
use FirefightingEquipment\Database\ConnectionException;
use FirefightingEquipment\Database\ConnectionFactory;
use FirefightingEquipment\Database\DatabaseError;
use FirefightingEquipment\Tenant\Tenant;
use FirefightingEquipment\Tenant\TenantException;
use FirefightingEquipment\Tenant\TenantName;
use FirefightingEquipment\Tenant\TenantRepository;
use FirefightingEquipment\Tenant\TenantSlug;

/**
 * Kommandozeile für Städte (bin/tenant.php). Kein Umbenennen des Kürzels, kein Löschen.
 */
final readonly class TenantCommand
{
    public const int EXIT_OK = 0;
    public const int EXIT_ERROR = 1;
    public const int EXIT_USAGE = 2;

    private const string USAGE = <<<'TXT'
        Aufruf:
          php bin/tenant.php list [--config=PFAD]                                alle Städte anzeigen
          php bin/tenant.php create --slug=KÜRZEL --name="NAME" [--config=PFAD]  Stadt anlegen
          php bin/tenant.php deactivate --slug=KÜRZEL [--config=PFAD]            Stadt sperren
          php bin/tenant.php activate --slug=KÜRZEL [--config=PFAD]              Stadt entsperren

        Kürzel: Kleinbuchstaben a-z, Ziffern und Bindestrich, höchstens 50 Zeichen;
        nach dem Anlegen unveränderlich. "service" ist reserviert.
        Standard-Konfiguration: config/config.local.php
        Auf dem Server immer php85 statt php verwenden.

        TXT;

    private const array COMMANDS = ['list', 'create', 'deactivate', 'activate'];

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
        try {
            $options = self::parseArguments($args);
        } catch (\InvalidArgumentException $e) {
            fwrite($this->err, 'FEHLER: ' . $e->getMessage() . PHP_EOL . PHP_EOL . self::USAGE);

            return self::EXIT_USAGE;
        }

        if ($options['command'] === 'help') {
            fwrite($this->out, self::USAGE);

            return self::EXIT_OK;
        }

        try {
            // Eingaben vor dem Verbindungsaufbau prüfen.
            $slug = $options['slug'] === null ? null : TenantSlug::fromString($options['slug']);
            $name = $options['name'] === null ? null : TenantName::fromString($options['name']);

            $config = new ConfigLoader()->loadDatabaseConfig($options['config'] ?? $this->defaultConfigPath);
            $repository = new TenantRepository(new ConnectionFactory()->create($config));

            match ($options['command']) {
                'list' => $this->printList($repository->all()),
                'create' => $this->create($repository, self::required($slug), self::required($name)),
                'deactivate' => $this->setActive($repository, self::required($slug), false),
                'activate' => $this->setActive($repository, self::required($slug), true),
            };
        } catch (ConfigException | ConnectionException | TenantException $e) {
            fwrite($this->err, 'FEHLER: ' . $e->getMessage() . PHP_EOL);

            return self::EXIT_ERROR;
        } catch (\PDOException $e) {
            fwrite($this->err, 'FEHLER: Datenbankfehler (' . DatabaseError::describe($e) . ').' . PHP_EOL);

            return self::EXIT_ERROR;
        }

        return self::EXIT_OK;
    }

    /**
     * @param list<string> $args
     *
     * @return array{command: 'list'|'create'|'deactivate'|'activate'|'help', slug: ?string, name: ?string, config: ?string}
     *
     * @throws \InvalidArgumentException bei falschem Aufruf
     */
    public static function parseArguments(array $args): array
    {
        $command = null;
        $values = ['slug' => null, 'name' => null, 'config' => null];

        foreach ($args as $arg) {
            if ($arg === '--help' || $arg === '-h' || $arg === 'help') {
                return ['command' => 'help', 'slug' => null, 'name' => null, 'config' => null];
            }

            if (preg_match('/^--(slug|name|config)=(.*)$/sD', $arg, $match) === 1) {
                $key = $match[1];
                if ($values[$key] !== null) {
                    throw new \InvalidArgumentException(sprintf('--%s ist doppelt angegeben.', $key));
                }
                if ($match[2] === '') {
                    throw new \InvalidArgumentException(sprintf(
                        '--%s= braucht %s.',
                        $key,
                        $key === 'config' ? 'einen Pfad' : 'einen Wert',
                    ));
                }
                $values[$key] = $match[2];
            } elseif (str_starts_with($arg, '-')) {
                throw new \InvalidArgumentException(sprintf('Unbekannte Option: %s', $arg));
            } elseif ($command !== null) {
                throw new \InvalidArgumentException(sprintf('Unerwartetes Argument: %s', $arg));
            } elseif (in_array($arg, self::COMMANDS, true)) {
                $command = $arg;
            } else {
                throw new \InvalidArgumentException(sprintf('Unbekannter Befehl: %s', $arg));
            }
        }

        if ($command === null) {
            throw new \InvalidArgumentException('Kein Befehl angegeben.');
        }

        $needsSlug = $command !== 'list';
        $needsName = $command === 'create';
        if ($needsSlug && $values['slug'] === null) {
            throw new \InvalidArgumentException(sprintf('%s braucht --slug=KÜRZEL.', $command));
        }
        if (!$needsSlug && $values['slug'] !== null) {
            throw new \InvalidArgumentException(sprintf('--slug ist bei %s nicht möglich.', $command));
        }
        if ($needsName && $values['name'] === null) {
            throw new \InvalidArgumentException(sprintf('%s braucht --name="NAME".', $command));
        }
        if (!$needsName && $values['name'] !== null) {
            throw new \InvalidArgumentException(sprintf('--name ist bei %s nicht möglich.', $command));
        }

        return ['command' => $command, ...$values];
    }

    /**
     * @param list<Tenant> $tenants
     */
    private function printList(array $tenants): void
    {
        if ($tenants === []) {
            $this->line('Keine Städte vorhanden.');

            return;
        }

        $rows = [['ID', 'Kürzel', 'Status', 'Angelegt (UTC)', 'Name']];
        foreach ($tenants as $tenant) {
            $rows[] = [
                (string) $tenant->id,
                $tenant->slug->value,
                $tenant->active ? 'aktiv' : 'gesperrt',
                $tenant->createdAt->format('Y-m-d H:i:s'),
                $tenant->name->value,
            ];
        }

        $widths = [];
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, mb_strlen($cell));
            }
        }

        foreach ($rows as $row) {
            $cells = [];
            foreach ($row as $i => $cell) {
                // Die letzte Spalte (Name) wird nicht aufgefüllt.
                $cells[] = $i === count($row) - 1 ? $cell : mb_str_pad($cell, $widths[$i]);
            }
            $this->line(implode('  ', $cells));
        }

        $this->line(count($tenants) === 1 ? '1 Stadt.' : sprintf('%d Städte.', count($tenants)));
    }

    /**
     * @throws TenantException
     */
    private function create(TenantRepository $repository, TenantSlug $slug, TenantName $name): void
    {
        $tenant = $repository->create($slug, $name);

        $this->line(sprintf('Stadt angelegt: %s (ID %d, „%s“)', $tenant->slug->value, $tenant->id, $tenant->name->value));
    }

    /**
     * @throws TenantException
     */
    private function setActive(TenantRepository $repository, TenantSlug $slug, bool $active): void
    {
        $changed = $repository->setActive($slug, $active);

        $this->line(match (true) {
            $changed && $active => sprintf('Stadt entsperrt: %s', $slug->value),
            $changed => sprintf('Stadt gesperrt: %s', $slug->value),
            $active => sprintf('Stadt ist bereits aktiv: %s', $slug->value),
            default => sprintf('Stadt ist bereits gesperrt: %s', $slug->value),
        });
    }

    /**
     * @template T of object
     *
     * @param T|null $value
     *
     * @return T
     */
    private static function required(?object $value): object
    {
        return $value ?? throw new \LogicException('Pflichtangabe fehlt trotz Prüfung.');
    }

    private function line(string $text): void
    {
        fwrite($this->out, $text . PHP_EOL);
    }
}
