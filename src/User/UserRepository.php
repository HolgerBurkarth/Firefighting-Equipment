<?php

declare(strict_types=1);

namespace FirefightingEquipment\User;

use FirefightingEquipment\Auth\OneTimeToken;
use FirefightingEquipment\Auth\TokenPurpose;
use FirefightingEquipment\Auth\UserTokenRepository;
use FirefightingEquipment\Invitation\InvitationCodeNotUsableException;
use FirefightingEquipment\Invitation\InvitationCodeRepository;
use FirefightingEquipment\Invitation\InvitationCodeValue;
use FirefightingEquipment\Tenant\DuplicateTenantSlugException;
use FirefightingEquipment\Tenant\Tenant;
use FirefightingEquipment\Tenant\TenantName;
use FirefightingEquipment\Tenant\TenantRepository;
use FirefightingEquipment\Tenant\TenantSlug;

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
    /** Nie bestätigte Registrierungen werden nach so vielen Tagen anonymisiert. */
    public const int UNVERIFIED_RETENTION_DAYS = 7;

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

    /**
     * Konto mit dieser ID, nur wenn es zur Stadt gehört (sonst null wie bei einer unbekannten ID).
     */
    public function findByTenantAndId(Tenant $tenant, int $id): ?User
    {
        return $this->fetchOne('SELECT ' . self::COLUMNS . ' FROM fe_users WHERE id = ? AND tenant_id = ?', [$id, $tenant->id]);
    }

    public function findSuperAdminByEmail(EmailAddress $email): ?User
    {
        return $this->fetchOne(
            'SELECT ' . self::COLUMNS . ' FROM fe_users WHERE tenant_key = 0 AND email = ?',
            [$email->value],
        );
    }

    /**
     * Passwort-Hash eines Kontos (nur für die Anmeldung; User enthält ihn bewusst nicht).
     *
     * @return string|null null, wenn das Konto fehlt oder kein Passwort hat (invited, deleted)
     */
    public function passwordHash(int $id): ?string
    {
        $stmt = $this->pdo->prepare('SELECT password_hash FROM fe_users WHERE id = ?');
        $stmt->execute([$id]);
        $hash = $stmt->fetchColumn();

        return is_string($hash) ? $hash : null;
    }

    /**
     * Ersetzt den Hash nach erfolgreicher Anmeldung durch einen mit aktuellen Parametern
     * (needsRehash). Das Passwort bleibt gleich, daher bleiben die Sitzungen bestehen. Geschrieben
     * wird nur, wenn noch der alte Hash gespeichert ist; so überschreibt das nie eine gleichzeitige
     * Passwortänderung. Braucht deshalb keine Sperre der Stadt.
     */
    public function rehashPassword(int $id, #[\SensitiveParameter] string $oldHash, #[\SensitiveParameter] string $newHash): void
    {
        $this->pdo->prepare("UPDATE fe_users SET password_hash = ? WHERE id = ? AND status <> 'deleted' AND password_hash = ?")
            ->execute([$newHash, $id, $oldHash]);
    }

    /**
     * @param UserStatus|null $status nur Konten mit diesem Status; null = alle
     *
     * @return list<User> nach ID sortiert, ohne Filter einschließlich anonymisierter Konten
     */
    public function listByTenant(Tenant $tenant, ?UserStatus $status = null): array
    {
        return $status === null
            ? $this->fetchAll('SELECT ' . self::COLUMNS . ' FROM fe_users WHERE tenant_id = ? ORDER BY id', [$tenant->id])
            : $this->fetchAll('SELECT ' . self::COLUMNS . ' FROM fe_users WHERE tenant_id = ? AND status = ? ORDER BY id', [$tenant->id, $status->value]);
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
     * Legt eine Stadt mit eingeladenem Admin an, in einer Transaktion: Stadt (aktiv), Konto (Rolle
     * admin, Status invited, ohne Passwort und Namen) und Link set_password. Schlägt ein Teil fehl,
     * bleibt nichts davon bestehen.
     *
     * @throws DuplicateTenantSlugException
     * @throws DuplicateUserEmailException
     */
    public function createTenantWithInvitedAdmin(TenantSlug $slug, TenantName $name, EmailAddress $email): AdminInvitation
    {
        return $this->atomically(function () use ($slug, $name, $email): AdminInvitation {
            $tenant = new TenantRepository($this->pdo)->create($slug, $name);
            $this->lockGroup($tenant->id);

            return $this->insertInvitedAdmin($tenant, $email);
        });
    }

    /**
     * Lädt einen Admin in eine bestehende Stadt ein (Konto invited ohne Passwort und Namen, dazu der
     * Link set_password, in einer Transaktion). Ob die Stadt gesperrt ist, prüft der Aufrufer.
     *
     * @throws DuplicateUserEmailException die Adresse hat in der Stadt schon ein Konto
     */
    public function inviteAdmin(Tenant $tenant, EmailAddress $email): AdminInvitation
    {
        return $this->tenantTransaction($tenant, fn (): AdminInvitation => $this->insertInvitedAdmin($tenant, $email));
    }

    /**
     * Neuer Link set_password für eine offene Einladung; der alte wird ungültig.
     *
     * @throws UserNotFoundException
     * @throws UserRuleViolationException keine offene Einladung oder Konto gesperrt
     */
    public function renewInvitation(int $id): OneTimeToken
    {
        return $this->transaction($id, function (User $user): OneTimeToken {
            if ($user->status !== UserStatus::Invited) {
                throw UserRuleViolationException::notInvited($user->id);
            }
            if ($user->isLocked()) {
                throw UserRuleViolationException::invitationLocked($user->id);
            }

            return new UserTokenRepository($this->pdo)->replace($user->id, TokenPurpose::SetPassword);
        });
    }

    /**
     * Einladung annehmen über den Einmal-Link: löst den Link (set_password, nicht abgelaufen) ein
     * und setzt in derselben Transaktion Name und Anzeigename, Passwort-Hash, bestätigte E-Mail und
     * den Status active. Wird der Link gleichzeitig zweimal eingelöst, gewinnt nur einer. Die
     * Passwortregeln prüft der Aufrufer.
     *
     * @param string $tokenHash SHA-256 des Tokens (32 Byte)
     *
     * @return bool false, wenn der Link nicht (mehr) gültig ist oder das Konto nicht invited bzw.
     *              gesperrt ist; dann bleibt alles unverändert
     *
     * @throws UserNotFoundException
     */
    public function acceptInvitation(int $id, PersonName $name, #[\SensitiveParameter] string $passwordHash, string $tokenHash): bool
    {
        return $this->transaction($id, function (User $user) use ($name, $passwordHash, $tokenHash): bool {
            if ($user->status !== UserStatus::Invited || $user->isLocked()) {
                return false;
            }

            $stmt = $this->pdo->prepare(
                "DELETE FROM fe_user_tokens WHERE user_id = ? AND purpose = 'set_password' AND token_hash = ?"
                . ' AND expires_at > UTC_TIMESTAMP()',
            );
            $stmt->execute([$user->id, $tokenHash]);
            if ($stmt->rowCount() !== 1) {
                return false;
            }

            $this->update(
                $user->id,
                "status = 'active', name = ?, display_name = ?, password_hash = ?, email_verified_at = UTC_TIMESTAMP()",
                [$name->value, $name->value, $passwordHash],
            );

            return true;
        });
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
     * Freischalten durch einen Admin: nur aus pending (E-Mail bestätigt), setzt Anzeigename und Rolle
     * (admin oder user) und den Status active.
     *
     * @throws UserNotFoundException
     * @throws UserRuleViolationException nicht pending, Rolle Super-Admin
     */
    public function approve(int $id, PersonName $displayName, UserRole $role): User
    {
        $this->transaction($id, function (User $user) use ($displayName, $role): void {
            if ($role === UserRole::SuperAdmin) {
                throw UserRuleViolationException::superAdminRole();
            }
            if ($user->status !== UserStatus::Pending) {
                throw UserRuleViolationException::notPending($user->id);
            }

            $this->update($user->id, "status = 'active', display_name = ?, role = ?", [$displayName->value, $role->value]);
        });

        return $this->findById($id) ?? throw UserNotFoundException::forId($id);
    }

    /**
     * Registrierung ablehnen: nur unverified oder pending; das Konto wird anonymisiert.
     *
     * @return User das Konto vor dem Anonymisieren (für die Benachrichtigung)
     *
     * @throws UserNotFoundException
     * @throws UserRuleViolationException keine offene Registrierung
     */
    public function reject(int $id): User
    {
        return $this->transaction($id, function (User $user): User {
            if ($user->status !== UserStatus::Unverified && $user->status !== UserStatus::Pending) {
                throw UserRuleViolationException::notARegistration($user->id);
            }

            $this->anonymizeLocked($user->id);

            return $user;
        });
    }

    /**
     * @return bool ob sich etwas geändert hat (false: hatte den Anzeigenamen bereits)
     *
     * @throws UserNotFoundException
     * @throws UserRuleViolationException gelöschtes Konto
     */
    public function changeDisplayName(int $id, PersonName $displayName): bool
    {
        return $this->transaction($id, function (User $user) use ($displayName): bool {
            self::assertNotDeleted($user);
            if ($user->displayName?->value === $displayName->value) {
                return false;
            }

            $this->update($user->id, 'display_name = ?', [$displayName->value]);

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
     * Passwort zurücksetzen über einen Einmal-Link: löst den Link (reset_password, nicht abgelaufen)
     * ein und setzt den Hash in derselben Transaktion; beendet alle Sitzungen. Wird der Link
     * gleichzeitig zweimal eingelöst, gewinnt nur einer (die Zeile kann nur einmal gelöscht werden).
     * Die Passwortregeln prüft der Aufrufer.
     *
     * @param string $tokenHash SHA-256 des Tokens (32 Byte)
     *
     * @return bool false, wenn der Link nicht (mehr) gültig ist oder das Konto nicht aktiv bzw.
     *              gesperrt ist; dann bleibt alles unverändert
     *
     * @throws UserNotFoundException
     */
    public function resetPassword(int $id, #[\SensitiveParameter] string $passwordHash, string $tokenHash): bool
    {
        return $this->transaction($id, function (User $user) use ($passwordHash, $tokenHash): bool {
            if ($user->status !== UserStatus::Active || $user->isLocked()) {
                return false;
            }

            $stmt = $this->pdo->prepare(
                "DELETE FROM fe_user_tokens WHERE user_id = ? AND purpose = 'reset_password' AND token_hash = ?"
                . ' AND expires_at > UTC_TIMESTAMP()',
            );
            $stmt->execute([$user->id, $tokenHash]);
            if ($stmt->rowCount() !== 1) {
                return false;
            }

            $this->update($user->id, 'password_hash = ?', [$passwordHash]);
            $this->endSessions($user->id);

            return true;
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

            $this->anonymizeLocked($user->id);

            return true;
        });
    }

    /**
     * Registrierung mit Einladungscode, in einer Transaktion: Stadt sperren, Code sperren und prüfen,
     * dann entweder (E-Mail schon vergeben) nichts ändern oder eine Nutzung zählen und das Konto
     * anlegen (Status unverified, Rolle user). Gleichzeitige Registrierungen können einen Code so
     * nicht über max_uses hinaus nutzen. Die Passwortregeln prüft der Aufrufer.
     *
     * @throws InvitationCodeNotUsableException Code unbekannt, aus einer anderen Stadt, abgelaufen,
     *                                          aufgebraucht oder widerrufen (auch wenn die E-Mail
     *                                          schon vergeben ist)
     */
    public function register(
        Tenant $tenant,
        InvitationCodeValue $code,
        EmailAddress $email,
        PersonName $name,
        #[\SensitiveParameter] string $passwordHash,
    ): Registration {
        return $this->tenantTransaction($tenant, function (array $group) use ($tenant, $code, $email, $name, $passwordHash): Registration {
            $codes = new InvitationCodeRepository($this->pdo);
            $codeId = $codes->lockUsableId($tenant, $code) ?? throw InvitationCodeNotUsableException::create();

            $existing = array_find($group, static fn (User $u): bool => $u->email?->value === $email->value);
            if ($existing !== null) {
                return new Registration($existing, false);
            }

            $codes->countUse($codeId);
            $this->pdo->prepare(
                'INSERT INTO fe_users (tenant_id, role, status, email, password_hash, name, invitation_code_id,'
                . " created_at, updated_at) VALUES (?, 'user', 'unverified', ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            )->execute([$tenant->id, $email->value, $passwordHash, $name->value, $codeId]);
            $id = (int) $this->pdo->lastInsertId();

            return new Registration($this->findById($id) ?? throw new \LogicException('Angelegtes Konto nicht gefunden.'), true);
        });
    }

    /**
     * E-Mail-Adresse bestätigen über einen Einmal-Link: löst den Link (verify_email, nicht
     * abgelaufen) ein und setzt in derselben Transaktion den Status pending (wartet auf
     * Freischaltung). Wird der Link gleichzeitig zweimal eingelöst, gewinnt nur einer.
     *
     * @param string $tokenHash SHA-256 des Tokens (32 Byte)
     *
     * @return bool false, wenn der Link nicht (mehr) gültig ist oder das Konto nicht unverified
     *              bzw. gesperrt ist; dann bleibt alles unverändert
     *
     * @throws UserNotFoundException
     */
    public function verifyEmail(int $id, string $tokenHash): bool
    {
        return $this->transaction($id, function (User $user) use ($tokenHash): bool {
            if ($user->status !== UserStatus::Unverified || $user->isLocked()) {
                return false;
            }

            $stmt = $this->pdo->prepare(
                "DELETE FROM fe_user_tokens WHERE user_id = ? AND purpose = 'verify_email' AND token_hash = ?"
                . ' AND expires_at > UTC_TIMESTAMP()',
            );
            $stmt->execute([$user->id, $tokenHash]);
            if ($stmt->rowCount() !== 1) {
                return false;
            }

            $this->update($user->id, "status = 'pending', email_verified_at = UTC_TIMESTAMP()");

            return true;
        });
    }

    /**
     * @return list<User> aktive, nicht gesperrte Admins der Stadt (Empfänger von Hinweisen), nach ID
     */
    public function listActiveAdmins(Tenant $tenant): array
    {
        return $this->fetchAll(
            'SELECT ' . self::COLUMNS . " FROM fe_users WHERE tenant_id = ? AND role = 'admin' AND status = 'active'"
            . ' AND locked_at IS NULL ORDER BY id',
            [$tenant->id],
        );
    }

    /**
     * @return list<User> aktive, nicht gesperrte Super-Admins (Empfänger von Hinweisen), nach ID
     */
    public function listActiveSuperAdmins(): array
    {
        return $this->fetchAll(
            'SELECT ' . self::COLUMNS . " FROM fe_users WHERE tenant_key = 0 AND status = 'active'"
            . ' AND locked_at IS NULL ORDER BY id',
            [],
        );
    }

    /**
     * Anonymisiert Konten der Stadt, deren E-Mail-Adresse UNVERIFIED_RETENTION_DAYS nach der
     * Registrierung noch nicht bestätigt ist (Ersatz für einen Cronjob). Danach ist die Adresse
     * wieder frei. Die Nutzung des Einladungscodes wird nicht zurückgegeben.
     *
     * @return int Anzahl anonymisierter Konten
     */
    public function anonymizeStaleUnverified(Tenant $tenant): int
    {
        $stale = "SELECT id FROM fe_users WHERE tenant_id = ? AND status = 'unverified'"
            . ' AND created_at < UTC_TIMESTAMP() - INTERVAL ? DAY';
        // Erst ohne Sperre nachsehen: Meist gibt es nichts zu tun, dann bleibt die Stadt ungesperrt.
        $stmt = $this->pdo->prepare($stale);
        $stmt->execute([$tenant->id, self::UNVERIFIED_RETENTION_DAYS]);
        if ($stmt->fetchColumn() === false) {
            return 0;
        }

        return $this->tenantTransaction($tenant, function () use ($stale, $tenant): int {
            $stmt = $this->pdo->prepare($stale);
            $stmt->execute([$tenant->id, self::UNVERIFIED_RETENTION_DAYS]);
            $ids = array_map(intval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN));
            foreach ($ids as $id) {
                $this->anonymizeLocked($id);
            }

            return count($ids);
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
        return $this->atomically(function () use ($id, $work): mixed {
            // tenant_key ändert sich nie, daher darf er vor dem Sperren gelesen werden.
            $stmt = $this->pdo->prepare('SELECT tenant_key FROM fe_users WHERE id = ?');
            $stmt->execute([$id]);
            $tenantKey = $stmt->fetchColumn();
            if ($tenantKey === false) {
                throw UserNotFoundException::forId($id);
            }

            $group = $this->lockGroup((int) $tenantKey);
            $user = array_find($group, static fn (User $u): bool => $u->id === $id)
                ?? throw UserNotFoundException::forId($id);

            return $work($user, $group);
        });
    }

    /**
     * Wie transaction(), aber ohne Zielkonto: sperrt alle Konten der Stadt (auch wenn es noch keine
     * gibt, sperrt das den Indexbereich der Stadt) und übergibt sie an $work.
     *
     * @template T
     *
     * @param \Closure(list<User>): T $work
     *
     * @return T
     */
    private function tenantTransaction(Tenant $tenant, \Closure $work): mixed
    {
        return $this->atomically(fn (): mixed => $work($this->lockGroup($tenant->id)));
    }

    /**
     * Immer dieselbe Abfrage über denselben Index: gleichzeitige Aufrufe sperren in derselben
     * Reihenfolge, und es werden nur Konten dieser Stadt gesperrt (kein Tabellen-Scan).
     *
     * @param int $tenantKey ID der Stadt, 0 = Super-Admins
     *
     * @return list<User>
     */
    private function lockGroup(int $tenantKey): array
    {
        return $this->fetchAll(
            'SELECT ' . self::COLUMNS . ' FROM fe_users FORCE INDEX (uq_fe_users_tenant_email)'
            . ' WHERE tenant_key = ? ORDER BY id FOR UPDATE',
            [$tenantKey],
        );
    }

    /**
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private function atomically(\Closure $work): mixed
    {
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('UserRepository startet eigene Transaktionen; keine offene Transaktion erlaubt.');
        }

        $this->pdo->beginTransaction();
        try {
            $result = $work();
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

    /**
     * Entfernt persönliche Daten, Sitzungen und Einmal-Links (Konto ist bereits gesperrt, Regeln geprüft).
     */
    private function anonymizeLocked(int $id): void
    {
        $this->endSessions($id);
        $this->pdo->prepare('DELETE FROM fe_user_tokens WHERE user_id = ?')->execute([$id]);
        $this->update(
            $id,
            "status = 'deleted', email = NULL, email_verified_at = NULL, password_hash = NULL, name = NULL,"
            . ' display_name = NULL, deleted_at = UTC_TIMESTAMP()',
        );
    }

    /**
     * Konto invited und Link set_password (die Konten der Stadt sind bereits gesperrt).
     *
     * @throws DuplicateUserEmailException
     */
    private function insertInvitedAdmin(Tenant $tenant, EmailAddress $email): AdminInvitation
    {
        $user = $this->insert(
            'INSERT INTO fe_users (tenant_id, role, status, email, created_at, updated_at)'
            . " VALUES (?, 'admin', 'invited', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            [$tenant->id, $email->value],
            $email,
        );
        $token = new UserTokenRepository($this->pdo)->replace($user->id, TokenPurpose::SetPassword);

        return new AdminInvitation($tenant, $user, $token);
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
