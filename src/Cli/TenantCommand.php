<?php

declare(strict_types=1);

namespace FirefightingEquipment\Cli;

use FirefightingEquipment\Config\ConfigException;
use FirefightingEquipment\Config\ConfigLoader;
use FirefightingEquipment\Database\ConnectionException;
use FirefightingEquipment\Database\ConnectionFactory;
use FirefightingEquipment\Database\DatabaseError;
use FirefightingEquipment\Http\AppUrls;
use FirefightingEquipment\Http\ErrorReporter;
use FirefightingEquipment\Mail\AdminInvitationMail;
use FirefightingEquipment\Mail\ConfiguredMailer;
use FirefightingEquipment\Mail\Mailer;
use FirefightingEquipment\Mail\Notifier;
use FirefightingEquipment\Tenant\Tenant;
use FirefightingEquipment\Tenant\TenantAdministration;
use FirefightingEquipment\Tenant\TenantException;
use FirefightingEquipment\Tenant\TenantName;
use FirefightingEquipment\Tenant\TenantNotFoundException;
use FirefightingEquipment\Tenant\TenantRepository;
use FirefightingEquipment\Tenant\TenantSlug;
use FirefightingEquipment\User\DuplicateUserEmailException;
use FirefightingEquipment\User\EmailAddress;
use FirefightingEquipment\User\UserException;
use FirefightingEquipment\User\UserRepository;
use FirefightingEquipment\User\UserStatus;

/**
 * Kommandozeile für Städte (bin/tenant.php). Kein Umbenennen des Kürzels (nur des Namens), kein
 * Löschen. invite-admin ist der Notfallweg für Einladungen ohne API (z. B. ohne Super-Admin-Zugang).
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
          php bin/tenant.php rename --slug=KÜRZEL --name="NAME" [--config=PFAD]  Namen ändern
          php bin/tenant.php deactivate --slug=KÜRZEL [--config=PFAD]            Stadt sperren
          php bin/tenant.php activate --slug=KÜRZEL [--config=PFAD]              Stadt entsperren
          php bin/tenant.php invite-admin --slug=KÜRZEL --email=ADRESSE [--print-link] [--config=PFAD]
                                                                                 Admin einladen

        Kürzel: Kleinbuchstaben a-z, Ziffern und Bindestrich, höchstens 50 Zeichen;
        nach dem Anlegen unveränderlich. "service" ist reserviert.
        deactivate beendet alle Sitzungen der Konten dieser Stadt.
        invite-admin legt ein Konto (Admin, eingeladen) an und mailt den Link zum Annehmen
        (24 Stunden gültig); für eine offene Einladung wird der Link erneuert. --print-link
        zeigt den Link zusätzlich an (Notfall, z. B. wenn keine Mail ankommt): nur an die
        eingeladene Person weitergeben.
        Standard-Konfiguration: config/config.local.php
        Auf dem Server immer php85 statt php verwenden.

        TXT;

    private const array COMMANDS = ['list', 'create', 'rename', 'deactivate', 'activate', 'invite-admin'];

    private ErrorReporter $errors;

    /**
     * @param resource $out
     * @param resource $err
     */
    public function __construct(
        private string $defaultConfigPath,
        private mixed $out,
        private mixed $err,
        /** null = laut Konfiguration (--config); andere nur für Tests. */
        private ?Mailer $mailer = null,
        ?ErrorReporter $errors = null,
    ) {
        $this->errors = $errors ?? new ErrorReporter();
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
            $email = $options['email'] === null ? null : EmailAddress::fromString($options['email']);

            $configPath = $options['config'] ?? $this->defaultConfigPath;
            $config = new ConfigLoader()->loadDatabaseConfig($configPath);
            $pdo = new ConnectionFactory()->create($config);
            $repository = new TenantRepository($pdo);

            match ($options['command']) {
                'list' => $this->printList($repository->all()),
                'create' => $this->create($repository, self::required($slug), self::required($name)),
                'rename' => $this->rename($repository, self::required($slug), self::required($name)),
                'deactivate' => $this->setActive($repository, self::required($slug), false),
                'activate' => $this->setActive($repository, self::required($slug), true),
                'invite-admin' => $this->inviteAdmin($pdo, self::required($slug), self::required($email), $options['print_link'], $configPath),
            };
        } catch (ConfigException | ConnectionException | TenantException | UserException $e) {
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
     * @return array{command: 'list'|'create'|'rename'|'deactivate'|'activate'|'invite-admin'|'help', slug: ?string, name: ?string, email: ?string, config: ?string, print_link: bool}
     *
     * @throws \InvalidArgumentException bei falschem Aufruf
     */
    public static function parseArguments(array $args): array
    {
        $command = null;
        $values = ['slug' => null, 'name' => null, 'email' => null, 'config' => null];
        $printLink = false;

        foreach ($args as $arg) {
            if ($arg === '--help' || $arg === '-h' || $arg === 'help') {
                return ['command' => 'help', 'slug' => null, 'name' => null, 'email' => null, 'config' => null, 'print_link' => false];
            }

            if ($arg === '--print-link') {
                if ($printLink) {
                    throw new \InvalidArgumentException('--print-link ist doppelt angegeben.');
                }
                $printLink = true;
            } elseif (preg_match('/^--(slug|name|email|config)=(.*)$/sD', $arg, $match) === 1) {
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
        $needsName = $command === 'create' || $command === 'rename';
        $needsEmail = $command === 'invite-admin';
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
        if ($needsEmail && $values['email'] === null) {
            throw new \InvalidArgumentException(sprintf('%s braucht --email=ADRESSE.', $command));
        }
        if (!$needsEmail && $values['email'] !== null) {
            throw new \InvalidArgumentException(sprintf('--email ist bei %s nicht möglich.', $command));
        }
        if (!$needsEmail && $printLink) {
            throw new \InvalidArgumentException(sprintf('--print-link ist bei %s nicht möglich.', $command));
        }

        return ['command' => $command, ...$values, 'print_link' => $printLink];
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

        foreach (TextTable::render($rows) as $line) {
            $this->line($line);
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
    private function rename(TenantRepository $repository, TenantSlug $slug, TenantName $name): void
    {
        $changed = $repository->rename($slug, $name);

        $this->line($changed
            ? sprintf('Stadt umbenannt: %s („%s“)', $slug->value, $name->value)
            : sprintf('Stadt heißt bereits „%s“: %s', $name->value, $slug->value));
    }

    /**
     * @throws TenantException
     */
    private function setActive(TenantRepository $repository, TenantSlug $slug, bool $active): void
    {
        $changed = $repository->setActive($slug, $active);

        $this->line(match (true) {
            $changed && $active => sprintf('Stadt entsperrt: %s', $slug->value),
            $changed => sprintf('Stadt gesperrt: %s. Alle Sitzungen ihrer Konten wurden beendet.', $slug->value),
            $active => sprintf('Stadt ist bereits aktiv: %s', $slug->value),
            default => sprintf('Stadt ist bereits gesperrt: %s', $slug->value),
        });
    }

    /**
     * Neue Adresse: Konto invited anlegen; offene Einladung: Link erneuern; sonst Fehler. Danach
     * Mail mit Link; der Link selbst erscheint nur mit --print-link.
     *
     * @throws TenantException
     * @throws UserException
     * @throws ConfigException
     */
    private function inviteAdmin(\PDO $pdo, TenantSlug $slug, EmailAddress $email, bool $printLink, string $configPath): void
    {
        $tenants = new TenantRepository($pdo);
        $users = new UserRepository($pdo);
        $admin = new TenantAdministration($tenants, $users);
        $tenant = $tenants->findBySlug($slug) ?? throw TenantNotFoundException::for($slug);

        $existing = $users->findByTenantAndEmail($tenant, $email);
        if ($existing === null) {
            $invitation = $admin->inviteAdmin($tenant, $email);
            $this->line(sprintf('Admin eingeladen: %s (Konto #%d, Stadt %s)', $email->value, $invitation->user->id, $slug->value));
        } elseif ($existing->status === UserStatus::Invited) {
            $invitation = $admin->renewInvitation($tenant, $existing->id);
            $this->line(sprintf('Einladung erneuert: %s (Konto #%d, Stadt %s); der alte Link ist ungültig.', $email->value, $existing->id, $slug->value));
        } else {
            throw DuplicateUserEmailException::notInvitable($email, $existing->status);
        }

        $urls = AppUrls::fromConfigFile($configPath);
        $mailer = $this->mailer ?? ConfiguredMailer::fromConfigFile($configPath, dirname(__DIR__, 2));
        if (AdminInvitationMail::send(new Notifier($mailer, $this->errors), $urls, $invitation)) {
            $this->line('Einladung per E-Mail gesendet (Link 24 Stunden gültig).');
        } else {
            fwrite($this->err, 'HINWEIS: Die Einladung konnte nicht per E-Mail gesendet werden (Details im Fehlerprotokoll).' . PHP_EOL);
        }

        if ($printLink) {
            fwrite($this->err, 'WARNUNG: Der Link ist ein Zugang zum Konto. Nur an die eingeladene Person weitergeben.' . PHP_EOL);
            $this->line($urls->acceptInvitation($tenant, $invitation->token));
        }
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
