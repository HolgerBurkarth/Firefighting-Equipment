<?php

declare(strict_types=1);

namespace FirefightingEquipment\Cli;

use FirefightingEquipment\Config\ConfigException;
use FirefightingEquipment\Config\ConfigLoader;
use FirefightingEquipment\Database\ConnectionException;
use FirefightingEquipment\Database\ConnectionFactory;
use FirefightingEquipment\Database\DatabaseError;
use FirefightingEquipment\Tenant\TenantException;
use FirefightingEquipment\Tenant\TenantNotFoundException;
use FirefightingEquipment\Tenant\TenantRepository;
use FirefightingEquipment\Tenant\TenantSlug;
use FirefightingEquipment\User\DuplicateUserEmailException;
use FirefightingEquipment\User\EmailAddress;
use FirefightingEquipment\User\Password\PasswordContext;
use FirefightingEquipment\User\Password\PasswordHasher;
use FirefightingEquipment\User\Password\PasswordPolicy;
use FirefightingEquipment\User\PersonName;
use FirefightingEquipment\User\User;
use FirefightingEquipment\User\UserException;
use FirefightingEquipment\User\UserNotFoundException;
use FirefightingEquipment\User\UserRepository;

/**
 * Kommandozeile für Benutzerkonten (bin/user.php): Super-Admins verwalten, Konten einer Stadt
 * anzeigen. Passwörter nie als Argument, sondern verdeckt (mit Wiederholung) oder für Tests und
 * Skripte über --password-stdin. Hashes werden nie ausgegeben.
 */
final readonly class UserCommand
{
    public const int EXIT_OK = 0;
    public const int EXIT_ERROR = 1;
    public const int EXIT_USAGE = 2;

    private const string USAGE = <<<'TXT'
        Aufruf:
          php bin/user.php superadmin:create --email=ADRESSE --name="NAME"  Super-Admin anlegen
          php bin/user.php superadmin:list                                 alle Super-Admins anzeigen
          php bin/user.php superadmin:password --email=ADRESSE             Passwort neu setzen
          php bin/user.php superadmin:lock --email=ADRESSE                 Super-Admin sperren
          php bin/user.php superadmin:unlock --email=ADRESSE               Super-Admin entsperren
          php bin/user.php list --tenant=KÜRZEL                            Konten einer Stadt anzeigen

        Weitere Optionen:
          --config=PFAD      andere Konfiguration (Standard: config/config.local.php)
          --password-stdin   Passwort aus der Standardeingabe lesen (nur für Tests und Skripte,
                             nicht per echo von Hand: landet in der Befehlshistorie)

        Das Passwort wird verdeckt abgefragt (zweimal), nie als Argument übergeben.
        Auf dem Server immer php85 statt php verwenden.

        TXT;

    /** Erlaubte Optionen je Befehl (außer --config); Pflichtangaben in REQUIRED. */
    private const array OPTIONS = [
        'superadmin:create' => ['email', 'name', 'password-stdin'],
        'superadmin:list' => [],
        'superadmin:password' => ['email', 'password-stdin'],
        'superadmin:lock' => ['email'],
        'superadmin:unlock' => ['email'],
        'list' => ['tenant'],
    ];

    private const array REQUIRED = [
        'superadmin:create' => ['email', 'name'],
        'superadmin:password' => ['email'],
        'superadmin:lock' => ['email'],
        'superadmin:unlock' => ['email'],
        'list' => ['tenant'],
    ];

    private const array PLACEHOLDERS = ['email' => '--email=ADRESSE', 'name' => '--name="NAME"', 'tenant' => '--tenant=KÜRZEL'];

    /** Mehr Bytes kann ein gültiges Passwort (128 Zeichen à höchstens 4 Byte) samt Zeilenende nicht haben. */
    private const int STDIN_LIMIT = 1024;

    private PasswordPrompt $prompt;

    /**
     * @param resource $in
     * @param resource $out
     * @param resource $err
     */
    public function __construct(
        private string $defaultConfigPath,
        private mixed $in,
        private mixed $out,
        private mixed $err,
        ?PasswordPrompt $prompt = null,
    ) {
        $this->prompt = $prompt ?? new TerminalPasswordPrompt($in, $err);
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
            $email = $options['email'] === null ? null : EmailAddress::fromString($options['email']);
            $name = $options['name'] === null ? null : PersonName::fromString($options['name']);
            $slug = $options['tenant'] === null ? null : TenantSlug::fromString($options['tenant']);

            $config = new ConfigLoader()->loadDatabaseConfig($options['config'] ?? $this->defaultConfigPath);
            $pdo = new ConnectionFactory()->create($config);
            $users = new UserRepository($pdo);
            $stdin = $options['passwordStdin'];

            match ($options['command']) {
                'superadmin:create' => $this->createSuperAdmin($users, self::required($email), self::required($name), $stdin),
                'superadmin:list' => $this->printSuperAdmins($users->listSuperAdmins()),
                'superadmin:password' => $this->setPassword($users, self::required($email), $stdin),
                'superadmin:lock' => $this->setLocked($users, self::required($email), true),
                'superadmin:unlock' => $this->setLocked($users, self::required($email), false),
                'list' => $this->printTenantUsers(new TenantRepository($pdo), $users, self::required($slug)),
            };
        } catch (ConfigException | ConnectionException | UserException | TenantException | PasswordPromptException $e) {
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
     * @return array{command: string, email: ?string, name: ?string, tenant: ?string, config: ?string, passwordStdin: bool}
     *
     * @throws \InvalidArgumentException bei falschem Aufruf
     */
    public static function parseArguments(array $args): array
    {
        $command = null;
        $values = ['email' => null, 'name' => null, 'tenant' => null, 'config' => null];
        $passwordStdin = false;

        foreach ($args as $arg) {
            if ($arg === '--help' || $arg === '-h' || $arg === 'help') {
                return ['command' => 'help', ...$values, 'passwordStdin' => false];
            }

            if ($arg === '--password-stdin') {
                if ($passwordStdin) {
                    throw new \InvalidArgumentException('--password-stdin ist doppelt angegeben.');
                }
                $passwordStdin = true;
            } elseif (preg_match('/^--(email|name|tenant|config)=(.*)$/sD', $arg, $match) === 1) {
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
            } elseif (preg_match('/^--?password(=|$)/i', $arg) === 1) {
                throw new \InvalidArgumentException(
                    'Passwörter werden nie als Argument übergeben (Befehlshistorie, Prozessliste).'
                    . ' Ohne Option wird das Passwort verdeckt abgefragt.',
                );
            } elseif (str_starts_with($arg, '-')) {
                // Nur den Namen der Option zeigen, nie einen Wert (könnte vertraulich sein).
                throw new \InvalidArgumentException(sprintf('Unbekannte Option: %s', explode('=', $arg, 2)[0]));
            } elseif ($command !== null) {
                throw new \InvalidArgumentException('Unerwartetes weiteres Argument.');
            } elseif (array_key_exists($arg, self::OPTIONS)) {
                $command = $arg;
            } else {
                throw new \InvalidArgumentException(sprintf('Unbekannter Befehl: %s', $arg));
            }
        }

        if ($command === null) {
            throw new \InvalidArgumentException('Kein Befehl angegeben.');
        }

        foreach (self::REQUIRED[$command] ?? [] as $key) {
            if ($values[$key] === null) {
                throw new \InvalidArgumentException(sprintf('%s braucht %s.', $command, self::PLACEHOLDERS[$key]));
            }
        }
        foreach (['email', 'name', 'tenant'] as $key) {
            if ($values[$key] !== null && !in_array($key, self::OPTIONS[$command], true)) {
                throw new \InvalidArgumentException(sprintf('--%s ist bei %s nicht möglich.', $key, $command));
            }
        }
        if ($passwordStdin && !in_array('password-stdin', self::OPTIONS[$command], true)) {
            throw new \InvalidArgumentException(sprintf('--password-stdin ist bei %s nicht möglich.', $command));
        }

        return ['command' => $command, ...$values, 'passwordStdin' => $passwordStdin];
    }

    /**
     * @throws UserException
     * @throws PasswordPromptException
     */
    private function createSuperAdmin(UserRepository $users, EmailAddress $email, PersonName $name, bool $stdin): void
    {
        // Vor der Passworteingabe prüfen; die Datenbank entscheidet trotzdem beim Anlegen.
        if ($users->findSuperAdminByEmail($email) !== null) {
            throw DuplicateUserEmailException::for($email);
        }

        $password = $this->readNewPassword(new PasswordContext($email, [$name]), $stdin);
        $user = $users->createSuperAdmin($email, $name, new PasswordHasher()->hash($password));

        $this->line(sprintf('Super-Admin angelegt: %s (ID %d)', $email->value, $user->id));
    }

    /**
     * @throws UserException
     * @throws PasswordPromptException
     */
    private function setPassword(UserRepository $users, EmailAddress $email, bool $stdin): void
    {
        $user = $users->findSuperAdminByEmail($email) ?? throw UserNotFoundException::superAdmin($email);

        $names = array_values(array_filter([$user->name, $user->displayName]));
        $password = $this->readNewPassword(new PasswordContext($email, $names), $stdin);
        $users->setPassword($user->id, new PasswordHasher()->hash($password));

        $this->line(sprintf('Passwort geändert: %s. Alle Sitzungen des Kontos wurden beendet.', $email->value));
    }

    /**
     * @throws UserException
     */
    private function setLocked(UserRepository $users, EmailAddress $email, bool $locked): void
    {
        $user = $users->findSuperAdminByEmail($email) ?? throw UserNotFoundException::superAdmin($email);

        $changed = $locked ? $users->lock($user->id) : $users->unlock($user->id);

        $this->line(match (true) {
            $changed && $locked => sprintf('Super-Admin gesperrt: %s. Alle Sitzungen des Kontos wurden beendet.', $email->value),
            $changed => sprintf('Super-Admin entsperrt: %s', $email->value),
            $locked => sprintf('Super-Admin ist bereits gesperrt: %s', $email->value),
            default => sprintf('Super-Admin ist nicht gesperrt: %s', $email->value),
        });
    }

    /**
     * Liest ein neues Passwort und prüft es. Verdeckt: Prüfung vor der Wiederholung, damit ein zu
     * schwaches Passwort nicht zweimal eingegeben werden muss.
     *
     * @throws UserException
     * @throws PasswordPromptException
     */
    private function readNewPassword(PasswordContext $context, bool $stdin): string
    {
        $policy = PasswordPolicy::default();

        if ($stdin) {
            $password = $this->readStdin();
            $policy->check($password, $context);

            return $password;
        }

        $password = $this->prompt->ask('Neues Passwort: ');
        $policy->check($password, $context);
        if (!hash_equals($password, $this->prompt->ask('Passwort wiederholen: '))) {
            throw new PasswordPromptException('Die Passwörter stimmen nicht überein.');
        }

        return $password;
    }

    /**
     * Ganze Standardeingabe; genau ein abschließendes Zeilenende wird entfernt.
     */
    private function readStdin(): string
    {
        $content = stream_get_contents($this->in, self::STDIN_LIMIT + 1);
        if ($content === false || strlen($content) > self::STDIN_LIMIT) {
            throw new PasswordPromptException('Die Standardeingabe ist kein gültiges Passwort (zu lang).');
        }

        return (string) preg_replace('/\r?\n\z/', '', $content);
    }

    /**
     * @param list<User> $users
     */
    private function printSuperAdmins(array $users): void
    {
        if ($users === []) {
            $this->line('Keine Super-Admins vorhanden.');

            return;
        }

        $rows = [['ID', 'E-Mail', 'Status', 'Angelegt (UTC)', 'Name']];
        foreach ($users as $user) {
            $rows[] = [
                (string) $user->id,
                $user->email->value ?? '–',
                self::statusText($user),
                $user->createdAt->format('Y-m-d H:i:s'),
                $user->label(),
            ];
        }
        $this->table($rows);

        $this->line(count($users) === 1 ? '1 Super-Admin.' : sprintf('%d Super-Admins.', count($users)));
    }

    /**
     * @throws TenantNotFoundException
     */
    private function printTenantUsers(TenantRepository $tenants, UserRepository $users, TenantSlug $slug): void
    {
        $tenant = $tenants->findBySlug($slug) ?? throw TenantNotFoundException::for($slug);
        $list = $users->listByTenant($tenant);

        if ($list === []) {
            $this->line(sprintf('Keine Konten in %s.', $tenant->slug->value));

            return;
        }

        $rows = [['ID', 'Rolle', 'Status', 'E-Mail', 'Angelegt (UTC)', 'Name']];
        foreach ($list as $user) {
            $rows[] = [
                (string) $user->id,
                $user->role->label(),
                self::statusText($user),
                $user->email->value ?? '–',
                $user->createdAt->format('Y-m-d H:i:s'),
                $user->label(),
            ];
        }
        $this->table($rows);

        $this->line(count($list) === 1 ? '1 Konto.' : sprintf('%d Konten.', count($list)));
    }

    private static function statusText(User $user): string
    {
        return $user->isLocked() && !$user->isDeleted()
            ? $user->status->label() . ', gesperrt'
            : $user->status->label();
    }

    /**
     * @param list<list<string>> $rows
     */
    private function table(array $rows): void
    {
        foreach (TextTable::render($rows) as $line) {
            $this->line($line);
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
