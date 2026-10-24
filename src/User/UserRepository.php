<?php

declare(strict_types=1);

namespace FirefightingEquipment\User;

use FirefightingEquipment\Tenant\Tenant;

/**
 * Zugriff auf fe_users. Konten werden nie per DELETE entfernt, sondern anonymisiert.
 *
 * Ändernde Methoden laufen jeweils in einer eigenen Transaktion und sperren zuerst alle Konten
 * derselben Stadt bzw. alle Super-Admins (SELECT … FOR UPDATE). So sehen gleichzeitige Änderungen
 * immer den aktuellen Stand, und die Regeln „letzter Admin“ und „letzter Super-Admin“ lassen sich
 * nicht durch zwei parallele Aufrufe umgehen. Sperren, Rollenwechsel, Passwort setzen und
 * Anonymisieren beenden alle Sitzungen des Kontos.
 */
final readonly class UserRepository
{
    private const int ER_DUP_ENTRY = 1062;
    private const string COLUMNS = 'id, tenant_id, role, status, email, name, display_name, email_verified_at,'
        . ' locked_at, deleted_at, created_at, updated_at';

    public function __construct(private \PDO $pdo)
    {
    }

    public function findById(int $id): ?User
    {
        return $this->fetchOne('SELECT ' . self::COLUMNS . ' FROM fe_users WHERE id = ?', [$id]);
    }

    public function findByTenantAndEmail(Tenant $tenant, EmailAddress $email): ?User
    {
        return $this->fetchOne(
            'SELECT ' . self::COLUMNS . ' FROM fe_users WHERE tenant_id = ? AND email = ?',
            [$tenant->id, $email->value],
        );
    }

    public function findSuperAdminByEmail(EmailAddress $email): ?User
    {
        return $this->fetchOne(
            'SELECT ' . self::COLUMNS . ' FROM fe_users WHERE tenant_key = 0 AND email = ?',
            [$email->value],
        );
    }

    /**
     * @return list<User> nach ID sortiert, einschließlich anonymisierter Konten
     */
    public function listByTenant(Tenant $tenant): array
    {
        return $this->fetchAll('SELECT ' . self::COLUMNS . ' FROM fe_users WHERE tenant_id = ? ORDER BY id', [$tenant->id]);
    }

    /**
     * @return list<User> nach ID sortiert, einschließlich anonymisierter Konten
     */
    public function listSuperAdmins(): array
    {
        return $this->fetchAll('SELECT ' . self::COLUMNS . ' FROM fe_users WHERE tenant_key = 0 ORDER BY id', []);
    }

    /**
     * Legt einen Super-Admin an: sofort aktiv, die E-Mail gilt als bestätigt (Anlage nur per Skript).
     *
     * @throws DuplicateUserEmailException
     */
    public function createSuperAdmin(EmailAddress $email, PersonName $name, #[\SensitiveParameter] string $passwordHash): User
    {
        return $this->insert(
            'INSERT INTO fe_users (tenant_id, role, status, email, email_verified_at, password_hash, name,'
            . ' display_name, created_at, updated_at)'
            . " VALUES (NULL, 'superadmin', 'active', ?, UTC_TIMESTAMP(), ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            [$email->value, $passwordHash, $name->value, $name->value],
            $email,
        );
    }

    /**
     * Legt den ersten Admin einer Stadt an (Status invited, ohne Passwort und Namen). Beides setzt
     * er später selbst über einen Einmal-Link.
     *
     * @throws DuplicateUserEmailException
     */
    public function createInvitedAdmin(Tenant $tenant, EmailAddress $email): User
    {
        return $this->insert(
            'INSERT INTO fe_users (tenant_id, role, status, email, created_at, updated_at)'
            . " VALUES (?, 'admin', 'invited', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            [$tenant->id, $email->value],
            $email,
        );
    }

    /**
     * @return bool ob sich etwas geändert hat (false: war bereits gesperrt)
     *
     * @throws UserNotFoundException
     * @throws UserRuleViolationException letzter Admin bzw. Super-Admin, gelöschtes Konto
     */
    public function lock(int $id): bool
    {
        return $this->transaction($id, function (User $user, array $group): bool {
            self::assertNotDeleted($user);
            if ($user->isLocked()) {
                return false;
            }
            self::assertNotLastAdministrator($user, $group);

            $this->update($user->id, 'locked_at = UTC_TIMESTAMP()');
            $this->endSessions($user->id);

            return true;
        });
    }

    /**
     * @return bool ob sich etwas geändert hat (false: war nicht gesperrt)
     *
     * @throws UserNotFoundException
     * @throws UserRuleViolationException gelöschtes Konto
     */
    public function unlock(int $id): bool
    {
        return $this->transaction($id, function (User $user): bool {
            self::assertNotDeleted($user);
            if (!$user->isLocked()) {
                return false;
            }

            $this->update($user->id, 'locked_at = NULL');

            return true;
        });
    }

    /**
     * Wechselt zwischen admin und user. Die Rolle Super-Admin wird weder vergeben noch entzogen.
     *
     * @return bool ob sich etwas geändert hat (false: hatte die Rolle bereits)
     *
     * @throws UserNotFoundException
     * @throws UserRuleViolationException
     */
    public function changeRole(int $id, UserRole $role): bool
    {
        return $this->transaction($id, function (User $user, array $group) use ($role): bool {
            self::assertNotDeleted($user);
            if ($role === UserRole::SuperAdmin || $user->role === UserRole::SuperAdmin) {
                throw UserRuleViolationException::superAdminRole();
            }
            if ($user->role === $role) {
                return false;
            }
            self::assertNotLastAdministrator($user, $group);

            $this->update($user->id, 'role = ?', [$role->value]);
            $this->endSessions($user->id);

            return true;
        });
    }

    /**
     * Setzt einen neuen Passwort-Hash (die Regeln prüft der Aufrufer mit PasswordPolicy).
     * Nicht für eingeladene Konten: Deren Einladung schließt ein eigener Ablauf ab.
     *
     * @throws UserNotFoundException
     * @throws UserRuleViolationException
     */
    public function setPassword(int $id, #[\SensitiveParameter] string $passwordHash): void
    {
        $this->transaction($id, function (User $user) use ($passwordHash): void {
            self::assertNotDeleted($user);
            if ($user->status === UserStatus::Invited) {
                throw UserRuleViolationException::noPasswordYet($user->id);
            }

            $this->update($user->id, 'password_hash = ?', [$passwordHash]);
            $this->endSessions($user->id);
        });
    }

    /**
     * Löschen = Anonymisieren: entfernt E-Mail, Namen, Passwort-Hash, Sitzungen und Einmal-Links.
     * Der Datensatz bleibt für die Historie („Gelöschter Benutzer #ID“).
     *
     * @return bool ob sich etwas geändert hat (false: war bereits gelöscht)
     *
     * @throws UserNotFoundException
     * @throws UserRuleViolationException letzter Admin bzw. Super-Admin
     */
    public function anonymize(int $id): bool
    {
        return $this->transaction($id, function (User $user, array $group): bool {
            if ($user->isDeleted()) {
                return false;
            }
            self::assertNotLastAdministrator($user, $group);

            $this->endSessions($user->id);
            $this->pdo->prepare('DELETE FROM fe_user_tokens WHERE user_id = ?')->execute([$user->id]);
            $this->update(
                $user->id,
                "status = 'deleted', email = NULL, email_verified_at = NULL, password_hash = NULL, name = NULL,"
                . ' display_name = NULL, deleted_at = UTC_TIMESTAMP()',
            );

            return true;
        });
    }

    /**
     * Führt $work in einer Transaktion aus, nachdem alle Konten derselben Stadt (bzw. alle
     * Super-Admins) gesperrt wurden. $work erhält das Zielkonto und diese Gruppe.
     *
     * @template T
     *
     * @param \Closure(User, list<User>): T $work
     *
     * @return T
     *
     * @throws UserNotFoundException
     */
    private function transaction(int $id, \Closure $work): mixed
    {
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('UserRepository startet eigene Transaktionen; keine offene Transaktion erlaubt.');
        }

        $this->pdo->beginTransaction();
        try {
            // tenant_key ändert sich nie, daher darf er vor dem Sperren gelesen werden.
            $stmt = $this->pdo->prepare('SELECT tenant_key FROM fe_users WHERE id = ?');
            $stmt->execute([$id]);
            $tenantKey = $stmt->fetchColumn();
            if ($tenantKey === false) {
                throw UserNotFoundException::forId($id);
            }

            // Immer dieselbe Abfrage über denselben Index: gleichzeitige Aufrufe sperren in derselben
            // Reihenfolge, und es werden nur Konten dieser Stadt gesperrt (kein Tabellen-Scan).
            $group = $this->fetchAll(
                'SELECT ' . self::COLUMNS . ' FROM fe_users FORCE INDEX (uq_fe_users_tenant_email)'
                . ' WHERE tenant_key = ? ORDER BY id FOR UPDATE',
                [$tenantKey],
            );
            $user = array_find($group, static fn (User $u): bool => $u->id === $id)
                ?? throw UserNotFoundException::forId($id);

            $result = $work($user, $group);
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param list<User> $group alle Konten derselben Stadt bzw. alle Super-Admins
     *
     * @throws UserRuleViolationException
     */
    private static function assertNotLastAdministrator(User $user, array $group): void
    {
        if (!$user->isActiveAdministrator()) {
            return;
        }

        foreach ($group as $other) {
            if ($other->id !== $user->id && $other->isActiveAdministrator()) {
                return;
            }
        }

        throw $user->role === UserRole::SuperAdmin
            ? UserRuleViolationException::lastSuperAdmin()
            : UserRuleViolationException::lastAdmin();
    }

    /**
     * @throws UserRuleViolationException
     */
    private static function assertNotDeleted(User $user): void
    {
        if ($user->isDeleted()) {
            throw UserRuleViolationException::deleted($user->id);
        }
    }

    /**
     * @param string            $assignments SQL aus diesem Code, nie aus Eingaben
     * @param list<string|null> $params
     */
    private function update(int $id, string $assignments, array $params = []): void
    {
        $this->pdo->prepare('UPDATE fe_users SET ' . $assignments . ', updated_at = UTC_TIMESTAMP() WHERE id = ?')
            ->execute([...$params, $id]);
    }

    private function endSessions(int $userId): void
    {
        $this->pdo->prepare('DELETE FROM fe_sessions WHERE user_id = ?')->execute([$userId]);
    }

    /**
     * @param list<int|string|null> $params
     *
     * @throws DuplicateUserEmailException
     */
    private function insert(string $sql, array $params, EmailAddress $email): User
    {
        try {
            $this->pdo->prepare($sql)->execute($params);
        } catch (\PDOException $e) {
            // Erst die Datenbank entscheidet; so ist die E-Mail auch bei gleichzeitigen Aufrufen eindeutig.
            if (($e->errorInfo[1] ?? null) === self::ER_DUP_ENTRY) {
                throw DuplicateUserEmailException::for($email);
            }
            throw $e;
        }

        $id = (int) $this->pdo->lastInsertId();

        return $this->findById($id) ?? throw new \LogicException('Angelegtes Konto nicht gefunden.');
    }

    /**
     * @param list<int|string> $params
     */
    private function fetchOne(string $sql, array $params): ?User
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return is_array($row) ? self::hydrate($row) : null;
    }

    /**
     * @param list<int|string> $params
     *
     * @return list<User>
     */
    private function fetchAll(string $sql, array $params): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map(self::hydrate(...), $stmt->fetchAll());
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): User
    {
        return new User(
            (int) $row['id'],
            $row['tenant_id'] === null ? null : (int) $row['tenant_id'],
            UserRole::from((string) $row['role']),
            UserStatus::from((string) $row['status']),
            $row['email'] === null ? null : EmailAddress::fromString((string) $row['email']),
            $row['name'] === null ? null : PersonName::fromString((string) $row['name']),
            $row['display_name'] === null ? null : PersonName::fromString((string) $row['display_name']),
            self::utcOrNull($row['email_verified_at']),
            self::utcOrNull($row['locked_at']),
            self::utcOrNull($row['deleted_at']),
            self::utc((string) $row['created_at']),
            self::utc((string) $row['updated_at']),
        );
    }

    private static function utcOrNull(mixed $value): ?\DateTimeImmutable
    {
        return $value === null ? null : self::utc((string) $value);
    }

    private static function utc(string $value): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'))
            ?: throw new \UnexpectedValueException(sprintf('Ungültiger Zeitstempel: %s', $value));
    }
}
