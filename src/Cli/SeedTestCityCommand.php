<?php

declare(strict_types=1);

namespace FirefightingEquipment\Cli;

use FirefightingEquipment\Config\ConfigException;
use FirefightingEquipment\Config\ConfigLoader;
use FirefightingEquipment\Database\ConnectionException;
use FirefightingEquipment\Database\ConnectionFactory;
use FirefightingEquipment\Database\DatabaseError;
use FirefightingEquipment\Device\DeviceException;
use FirefightingEquipment\Device\Seed\SeedStep;
use FirefightingEquipment\Device\Seed\TestCityData;
use FirefightingEquipment\Device\Seed\TestCitySeeder;
use FirefightingEquipment\Device\Seed\TestCitySeedException;
use FirefightingEquipment\Device\TenantDeviceDataPurge;
use FirefightingEquipment\Tenant\TenantException;
use FirefightingEquipment\Tenant\TenantNotFoundException;
use FirefightingEquipment\Tenant\TenantRepository;
use FirefightingEquipment\Tenant\TenantSlug;
use FirefightingEquipment\Time\Clock;
use FirefightingEquipment\Time\LocalDate;
use FirefightingEquipment\Time\SystemClock;
use FirefightingEquipment\User\User;
use FirefightingEquipment\User\UserRepository;
use FirefightingEquipment\User\UserStatus;

/**
 * Testdaten für die Test-Stadt (bin/seed-test-city.php): Standorte, Geräte mit Scan-Codes und
 * Prüfdaten, einige mit Verlauf, eines ausgesondert. NUR für das Kürzel TestCityData::SLUG („test“),
 * fest im Code und nicht abschaltbar; nur in eine Stadt ohne Geräte und Standorte. Ohne --execute
 * Probelauf. Agenten führen den Befehl nur gegen die Testdatenbank aus (Tests), live der Nutzer.
 */
final readonly class SeedTestCityCommand
{
    public const int EXIT_OK = 0;
    public const int EXIT_ERROR = 1;
    public const int EXIT_USAGE = 2;

    private const string USAGE = <<<'TXT'
        Aufruf:
          php bin/seed-test-city.php --slug=test [--devices=ANZAHL] [--execute] [--config=PFAD]

        Legt Testdaten in der Test-Stadt an: Standorte (Fahrzeuge mit Fächern, Räume, Extern),
        rund 60 Geräte mit Scan-Codes (auch GS1), Seriennummern, Herstellern und Prüfdaten
        (überfällig, bald fällig, später, ohne), einige Geräte mit Verlauf, eines ausgesondert.
        --devices=ANZAHL (1 bis 5000) legt stattdessen ANZAHL Geräte T0001 … an (z. B. 2500,
        um die Suche auf dem Server zu prüfen).
        Nur für das Kürzel "test" (andere Städte sind gesperrt) und nur, wenn die Stadt noch
        keine Geräte und Standorte hat (sonst vorher: php bin/tenant.php purge-device-data).
        Ohne --execute nur Probelauf (zeigt, was angelegt würde).
        Standard-Konfiguration: config/config.local.php
        Auf dem Server immer php85 statt php verwenden.

        TXT;

    private Clock $clock;

    /**
     * @param resource $out
     * @param resource $err
     */
    public function __construct(
        private string $defaultConfigPath,
        private mixed $out,
        private mixed $err,
        /** null = Systemzeit; andere nur für Tests (Prüfdaten relativ zu „heute“). */
        ?Clock $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
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

        if ($options['help']) {
            fwrite($this->out, self::USAGE);

            return self::EXIT_OK;
        }

        try {
            $config = new ConfigLoader()->loadDatabaseConfig($options['config'] ?? $this->defaultConfigPath);
            $this->seed(new ConnectionFactory()->create($config), $options['devices'], $options['execute']);
        } catch (ConfigException | ConnectionException | TenantException | DeviceException | TestCitySeedException $e) {
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
     * @return array{help: bool, devices: ?int, execute: bool, config: ?string}
     *
     * @throws \InvalidArgumentException bei falschem Aufruf, auch bei jedem anderen Kürzel als "test"
     */
    public static function parseArguments(array $args): array
    {
        $values = ['slug' => null, 'devices' => null, 'config' => null];
        $execute = false;

        foreach ($args as $arg) {
            if ($arg === '--help' || $arg === '-h' || $arg === 'help') {
                return ['help' => true, 'devices' => null, 'execute' => false, 'config' => null];
            }
            if ($arg === '--execute') {
                if ($execute) {
                    throw new \InvalidArgumentException('--execute ist doppelt angegeben.');
                }
                $execute = true;
            } elseif (preg_match('/^--(slug|devices|config)=(.*)$/sD', $arg, $match) === 1) {
                $key = $match[1];
                if ($values[$key] !== null) {
                    throw new \InvalidArgumentException(sprintf('--%s ist doppelt angegeben.', $key));
                }
                if ($match[2] === '') {
                    throw new \InvalidArgumentException(sprintf('--%s= braucht einen Wert.', $key));
                }
                $values[$key] = $match[2];
            } elseif (str_starts_with($arg, '-')) {
                throw new \InvalidArgumentException(sprintf('Unbekannte Option: %s', $arg));
            } else {
                throw new \InvalidArgumentException(sprintf('Unerwartetes Argument: %s', $arg));
            }
        }

        if ($values['slug'] === null) {
            throw new \InvalidArgumentException('--slug=test fehlt.');
        }
        // Exakter Vergleich ohne Normalisierung: Testdaten nie in eine echte Stadt.
        if ($values['slug'] !== TestCityData::SLUG) {
            throw new \InvalidArgumentException(sprintf(
                'Testdaten gibt es nur für die Test-Stadt (--slug=%s); andere Städte sind gesperrt.',
                TestCityData::SLUG,
            ));
        }

        $devices = null;
        if ($values['devices'] !== null) {
            if (preg_match('/^[1-9]\d{0,3}\z/', $values['devices']) !== 1 || (int) $values['devices'] > TestCityData::MAX_DEVICES) {
                throw new \InvalidArgumentException(sprintf('--devices= braucht eine Zahl von 1 bis %d.', TestCityData::MAX_DEVICES));
            }
            $devices = (int) $values['devices'];
        }

        return ['help' => false, 'devices' => $devices, 'execute' => $execute, 'config' => $values['config']];
    }

    /**
     * @throws TenantException
     * @throws DeviceException
     * @throws TestCitySeedException
     */
    private function seed(\PDO $pdo, ?int $deviceCount, bool $execute): void
    {
        $slug = TenantSlug::fromString(TestCityData::SLUG);
        $tenant = new TenantRepository($pdo)->findBySlug($slug) ?? throw TenantNotFoundException::for($slug);

        $existing = new TenantDeviceDataPurge($pdo)->count($tenant);
        if ($existing->devices > 0 || $existing->locations > 0) {
            throw new TestCitySeedException(sprintf(
                'Die Stadt %1$s hat schon Gerätedaten (%2$d Geräte, %3$d Standorte). Testdaten nur in eine leere Stadt;'
                . ' vorher von Hand: php bin/tenant.php purge-device-data --slug=%1$s',
                $slug->value,
                $existing->devices,
                $existing->locations,
            ));
        }

        $users = new UserRepository($pdo);
        $admins = $users->listActiveAdmins($tenant);
        $admin = $admins[0] ?? throw new TestCitySeedException(sprintf(
            'Die Stadt %s hat keinen aktiven Admin; die Testdaten brauchen ein Konto für die Historie.',
            $slug->value,
        ));
        $bookers = array_values(array_map(
            static fn (User $user): int => $user->id,
            array_filter($users->listByTenant($tenant, UserStatus::Active), static fn (User $user): bool => !$user->isLocked()),
        ));
        if ($bookers === []) {
            $bookers = [$admin->id];
        }

        $devices = TestCityData::devices(LocalDate::today($this->clock), $deviceCount);
        $steps = TestCityData::steps($devices, $deviceCount !== null);
        $children = array_sum(array_map(static fn (array $location): int => count($location[1]), TestCityData::LOCATIONS));
        $withHistory = count(array_unique(array_map(static fn (SeedStep $step): string => $step->identifier, $steps)));
        $retired = count(array_filter($steps, static fn (SeedStep $step): bool => $step->retire));

        if (!$execute) {
            $this->line(sprintf('Probelauf für %s („%s“) – es wird nichts geändert. Angelegt würden:', $slug->value, $tenant->name->value));
            $this->line(sprintf('  Standorte: %d (%d Standorte, %d Fächer)', count(TestCityData::LOCATIONS) + $children, count(TestCityData::LOCATIONS), $children));
            $this->line(sprintf('  Geräte:    %d (%s)', count($devices), $deviceCount === null ? 'ausgesuchte Testgeräte' : 'Kennungen T0001 …'));
            $this->line(sprintf('  Verlauf:   %d Schritte an %d Geräten, davon %d ausgesondert', count($steps), $withHistory, $retired));
            $this->line(sprintf('  Konten:    Admin #%d legt an; Verlauf reihum von %d Konto/Konten', $admin->id, count($bookers)));
            $this->line(sprintf(
                'Zum Anlegen: php bin/seed-test-city.php --slug=%s%s --execute',
                $slug->value,
                $deviceCount === null ? '' : ' --devices=' . $deviceCount,
            ));

            return;
        }

        $result = new TestCitySeeder($pdo)->seed($tenant, $admin->id, $bookers, $devices, $steps);
        $this->line(sprintf('Testdaten angelegt: %s („%s“).', $slug->value, $tenant->name->value));
        $this->line(sprintf('  Standorte: %d', $result->locations));
        $this->line(sprintf('  Geräte:    %d', $result->devices));
        $this->line(sprintf('  Verlauf:   %d Schritte, davon %d ausgesondert', $result->steps, $result->retired));
    }

    private function line(string $text): void
    {
        fwrite($this->out, $text . PHP_EOL);
    }
}
