<?php

declare(strict_types=1);

namespace FirefightingEquipment\User;

use FirefightingEquipment\Auth\OneTimeToken;
use FirefightingEquipment\Auth\TokenPurpose;
use FirefightingEquipment\Auth\UserTokenRepository;
use FirefightingEquipment\Claude\ClaudeAccess;
use FirefightingEquipment\Claude\ClaudeConnectionRepository;
use FirefightingEquipment\Invitation\InvitationCodeNotUsableException;
use FirefightingEquipment\Invitation\InvitationCodeRepository;
use FirefightingEquipment\Invitation\InvitationCodeValue;
use FirefightingEquipment\Permission\Action;
use FirefightingEquipment\Permission\PermissionRepository;
use FirefightingEquipment\Permission\Permissions;
use FirefightingEquipment\Role\Role;
use FirefightingEquipment\Role\RoleNotFoundException;
use FirefightingEquipment\Role\RoleRepository;
use FirefightingEquipment\Role\SystemRole;
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
 * nicht durch zwei parallele Aufrufe umgehen. Sperren, Passwort setzen und Anonymisieren beenden
 * alle Sitzungen des Kontos, ein Rollenwechsel nur, wenn die Rolle Admin dazukommt oder wegfällt (F1).
 * Einige Methoden nehmen eine Prüfung des Aufrufers ($authorize, UserAdministration), die im
 * gesperrten Stand läuft.
 *
 * Geschützte Konten (protected, nur Super-Admins) lehnen Änderungen durch andere ab; erlaubt bleiben
 * Passwort ändern bzw. zurücksetzen und die eigene Benachrichtigung. Super-Admins werden nie
 * anonymisiert. Beides erzwingt zusätzlich die Datenbank (CHECK).
 */
final readonly class UserRepository
{
    /** Nie bestätigte Registrierungen werden nach so vielen Tagen anonymisiert. */
    public const int UNVERIFIED_RETENTION_DAYS = 7;

    private const int ER_DUP_ENTRY = 1062;
    private const int ER_NO_REFERENCED_ROW = 1452;

    /**
     * Rolle zur Kontoart (fe_users.role admin/user → Standard-Rolle mit diesem system_key) beim
     * Anlegen, damit role und role_id in derselben Anweisung zusammenpassen (Plan Phase 1,
     * Abschnitt 1). Parameter: Stadt, Kontoart. Beim Zuweisen setzt updateRole() beides.
     */
    private const string SYSTEM_ROLE_ID_OF_TENANT = 'SELECT r.id FROM fe_roles r WHERE r.tenant_id = ? AND r.system_key = ?';

    private const string COLUMNS = 'id, tenant_id, role, status, email, name, display_name, email_verified_at,'
        . ' locked_at, deleted_at, created_at, updated_at, protected, notify_device_log, role_id, invitation_code_id,'
        . ' claude_access';

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
     * @throws UserRuleViolationException letzter Admin bzw. Super-Admin, gelöschtes oder geschütztes Konto
     */
    public function lock(int $id, ?\Closure $authorize = null): bool
    {
        return $this->transaction($id, function (User $user, array $group) use ($authorize): bool {
            self::authorize($user, $authorize);
            self::assertNotDeleted($user);
            self::assertNotProtected($user);
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
    public function unlock(int $id, ?\Closure $authorize = null): bool
    {
        return $this->transaction($id, function (User $user) use ($authorize): bool {
            self::authorize($user, $authorize);
            self::assertNotDeleted($user);
            if (!$user->isLocked()) {
                return false;
            }

            $this->update($user->id, 'locked_at = NULL');

            return true;
        });
    }

    /**
     * Wechselt zwischen den Standard-Rollen Admin und Benutzer (Kontoart admin bzw. user), sonst wie
     * assignRole(). Die Rolle Super-Admin wird weder vergeben noch entzogen.
     *
     * @param (\Closure(User, Role): void)|null $authorize wie bei assignRole()
     *
     * @return bool ob sich etwas geändert hat (false: hatte die Rolle bereits)
     *
     * @throws UserNotFoundException
     * @throws UserRuleViolationException
     */
    public function changeRole(int $id, UserRole $role, ?\Closure $authorize = null): bool
    {
        return $this->assign($id, fn (User $user): Role => $this->systemRoleFor($user, $role), $authorize);
    }

    /**
     * Weist einem Konto eine Rolle seiner Stadt zu (Plan Phase 1, Schritt 1.6). Die Kontoart folgt
     * der Rolle (admin genau mit der Rolle Admin). Sitzungen enden nur, wenn sich dabei die Kontoart
     * ändert (F1); sonst gilt die neue Rolle ab der nächsten Anfrage. Hat die neue Rolle kein
     * mail.log, wird die Benachrichtigung „Log“ ausgeschaltet (ein späterer Wechsel schaltet sie
     * nicht ungefragt wieder ein).
     *
     * @param (\Closure(User, Role): void)|null $authorize prüft im gesperrten Stand (Konto und neue
     *                                                   Rolle, z. B. F5) und wirft bei einem Verstoß
     *
     * @return bool ob sich etwas geändert hat (false: hatte die Rolle bereits)
     *
     * @throws UserNotFoundException
     * @throws RoleNotFoundException      Rolle nicht aus der Stadt des Kontos
     * @throws UserRuleViolationException
     */
    public function assignRole(int $id, int $roleId, ?\Closure $authorize = null): bool
    {
        return $this->assign($id, fn (User $user): Role => $this->roleOf($user, $roleId), $authorize);
    }

    /**
     * @param \Closure(User): Role               $resolve
     * @param (\Closure(User, Role): void)|null $authorize
     *
     * @throws UserNotFoundException
     * @throws RoleNotFoundException
     * @throws UserRuleViolationException
     */
    private function assign(int $id, \Closure $resolve, ?\Closure $authorize): bool
    {
        return $this->transaction($id, function (User $user, array $group) use ($resolve, $authorize): bool {
            self::assertNotDeleted($user);
            self::assertNotProtected($user);
            if ($user->role === UserRole::SuperAdmin) {
                throw UserRuleViolationException::superAdminRole();
            }
            $role = $resolve($user);
            if ($authorize !== null) {
                $authorize($user, $role);
            }
            if ($user->roleId === $role->id) {
                return false;
            }
            $kind = self::kindOf($role);
            if ($kind !== $user->role) {
                self::assertNotLastAdministrator($user, $group);
            }

            $notify = $user->notifyDeviceLog && Permissions::fromRole($role)->can(Action::MailLog);
            // Wechsel zu oder von Admin: Claude-Zugang aus (docs/MCP-KONZEPT.md, Abschnitt 2); die
            // Verbindungen enden mit den Sitzungen.
            $claude = $kind !== $user->role ? ClaudeAccess::Off : $user->claudeAccess;
            $this->updateRole(
                $user->id,
                $role,
                'notify_device_log = ?, claude_access = ?',
                [$notify ? '1' : '0', $claude->value],
            );
            if ($kind !== $user->role) {
                $this->endSessions($user->id);
            }

            return true;
        });
    }

    /**
     * Freischalten durch einen Admin: nur aus pending (E-Mail bestätigt), setzt Anzeigename und eine
     * der Standard-Rollen Admin bzw. Benutzer und den Status active.
     *
     * @param (\Closure(User, Role): void)|null $authorize wie bei assignRole()
     *
     * @throws UserNotFoundException
     * @throws UserRuleViolationException nicht pending, Rolle Super-Admin
     */
    public function approve(int $id, PersonName $displayName, UserRole $role, ?\Closure $authorize = null): User
    {
        return $this->approveAs($id, $displayName, fn (User $user): Role => $this->systemRoleFor($user, $role), $authorize);
    }

    /**
     * Wie approve(), mit einer Rolle der Stadt (z. B. der des Einladungscodes, Plan Phase 1,
     * Schritt 1.6).
     *
     * @param (\Closure(User, Role): void)|null $authorize wie bei assignRole()
     *
     * @throws UserNotFoundException
     * @throws RoleNotFoundException
     * @throws UserRuleViolationException
     */
    public function approveWithRole(int $id, PersonName $displayName, int $roleId, ?\Closure $authorize = null): User
    {
        return $this->approveAs($id, $displayName, fn (User $user): Role => $this->roleOf($user, $roleId), $authorize);
    }

    /**
     * @param \Closure(User): Role               $resolve
     * @param (\Closure(User, Role): void)|null $authorize
     */
    private function approveAs(int $id, PersonName $displayName, \Closure $resolve, ?\Closure $authorize): User
    {
        $this->transaction($id, function (User $user) use ($displayName, $resolve, $authorize): void {
            if ($user->role === UserRole::SuperAdmin) {
                throw UserRuleViolationException::superAdminRole();
            }
            $role = $resolve($user);
            if ($authorize !== null) {
                $authorize($user, $role);
            }
            if ($user->status !== UserStatus::Pending) {
                throw UserRuleViolationException::notPending($user->id);
            }

            $this->updateRole($user->id, $role, "status = 'active', display_name = ?", [$displayName->value]);
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
    public function changeDisplayName(int $id, PersonName $displayName, ?\Closure $authorize = null): bool
    {
        return $this->transaction($id, function (User $user) use ($displayName, $authorize): bool {
            self::authorize($user, $authorize);
            self::assertNotDeleted($user);
            self::assertNotProtected($user);
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
     * @throws UserRuleViolationException letzter Admin, Super-Admin (nie löschbar)
     */
    public function anonymize(int $id, ?\Closure $authorize = null): bool
    {
        return $this->transaction($id, function (User $user, array $group) use ($authorize): bool {
            self::authorize($user, $authorize);
            if ($user->role === UserRole::SuperAdmin) {
                throw UserRuleViolationException::superAdminNotDeletable();
            }
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
     * anlegen (Status unverified, Kontoart user, Rolle des Codes bzw. ohne Rolle am Code Benutzer).
     * Gleichzeitige Registrierungen können einen Code so nicht über max_uses hinaus nutzen. Die
     * Passwortregeln prüft der Aufrufer.
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
            // Rolle des Codes (nie Admin, InvitationCodeRepository::create), ohne Rolle Benutzer.
            $this->pdo->prepare(
                'INSERT INTO fe_users (tenant_id, role, role_id, status, email, password_hash, name, invitation_code_id,'
                . " created_at, updated_at) VALUES (?, 'user', COALESCE((SELECT c.role_id FROM fe_invitation_codes c"
                . ' WHERE c.id = ? AND c.tenant_id = ?), (' . self::SYSTEM_ROLE_ID_OF_TENANT . ")), 'unverified', ?, ?, ?, ?,"
                . ' UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            )->execute([$tenant->id, $codeId, $tenant->id, $tenant->id, UserRole::User->value, $email->value, $passwordHash, $name->value, $codeId]);
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
     * Schützt einen Super-Admin (nur per Kommandozeile). Nur aktive, nicht gesperrte Super-Admins.
     *
     * @return bool ob sich etwas geändert hat (false: war bereits geschützt)
     *
     * @throws UserNotFoundException
     * @throws UserRuleViolationException kein aktiver, nicht gesperrter Super-Admin
     */
    public function protect(int $id): bool
    {
        return $this->transaction($id, function (User $user): bool {
            if ($user->protected) {
                return false;
            }
            if ($user->role !== UserRole::SuperAdmin || $user->status !== UserStatus::Active || $user->isLocked()) {
                throw UserRuleViolationException::notProtectable($user->id);
            }

            $this->update($user->id, 'protected = 1');

            return true;
        });
    }

    /**
     * Entfernt den Schutz (nur per Kommandozeile mit Bestätigung).
     *
     * @return bool ob sich etwas geändert hat (false: war nicht geschützt)
     *
     * @throws UserNotFoundException
     */
    public function unprotect(int $id): bool
    {
        return $this->transaction($id, function (User $user): bool {
            if (!$user->protected) {
                return false;
            }

            $this->update($user->id, 'protected = 0');

            return true;
        });
    }

    /**
     * Setzt den Claude-Zugang (docs/MCP-KONZEPT.md, Abschnitt 2). Nie für Super-Admins und gelöschte
     * Konten; Lesen bzw. Ändern nur für aktive Konten (gesperrte behalten den Wert, gelten aber als
     * ohne Zugang). „Aus“ löscht alle Claude-Verbindungen und Codes des Kontos; „Ändern“ → „Lesen“
     * lässt sie bestehen (wirkt ab dem nächsten Aufruf). Wer den Zugang setzen darf (F12), prüft
     * $authorize im gesperrten Stand.
     *
     * @param (\Closure(User): void)|null $authorize
     *
     * @return bool ob sich etwas geändert hat
     *
     * @throws UserNotFoundException
     * @throws UserRuleViolationException
     */
    public function setClaudeAccess(int $id, ClaudeAccess $access, ?\Closure $authorize = null): bool
    {
        return $this->transaction($id, function (User $user) use ($access, $authorize): bool {
            if ($user->role === UserRole::SuperAdmin) {
                throw UserRuleViolationException::claudeAccessSuperAdmin();
            }
            self::assertNotDeleted($user);
            self::authorize($user, $authorize);
            if ($access !== ClaudeAccess::Off && $user->status !== UserStatus::Active) {
                throw UserRuleViolationException::claudeAccessNotActive($user->id);
            }
            if ($user->claudeAccess === $access) {
                return false;
            }

            $this->update($user->id, 'claude_access = ?', [$access->value]);
            if ($access === ClaudeAccess::Off) {
                new ClaudeConnectionRepository($this->pdo)->deleteForUser($user->id);
            }

            return true;
        });
    }

    /**
     * „Claude-Verbindungen trennen“: löscht alle Verbindungen und offenen Codes des Kontos; der Zugang
     * bleibt (eine neue Verbindung ist möglich). Wiederholbar.
     *
     * @param (\Closure(User): void)|null $authorize
     *
     * @return int Anzahl der beendeten Verbindungen
     *
     * @throws UserNotFoundException
     * @throws UserRuleViolationException Super-Admin
     */
    public function endClaudeConnections(int $id, ?\Closure $authorize = null): int
    {
        return $this->transaction($id, function (User $user) use ($authorize): int {
            if ($user->role === UserRole::SuperAdmin) {
                throw UserRuleViolationException::claudeAccessSuperAdmin();
            }
            self::authorize($user, $authorize);

            return new ClaudeConnectionRepository($this->pdo)->deleteForUser($user->id);
        });
    }

    /**
     * Schaltet die Benachrichtigung „Log“ des Kontos ein oder aus (einschalten nur Super-Admins und
     * Konten, deren Rolle die Aktion mail.log hat, z. B. die Rolle Admin; auch für geschützte Konten,
     * es ist eine Einstellung des eigenen Kontos).
     *
     * @return bool ob sich etwas geändert hat
     *
     * @throws UserNotFoundException
     * @throws UserRuleViolationException Einschalten ohne mail.log der Rolle oder gelöschtes Konto
     */
    public function setDeviceLogNotification(int $id, bool $enabled): bool
    {
        return $this->transaction($id, function (User $user) use ($enabled): bool {
            // Ausschalten geht immer (auch nachdem die Rolle mail.log verloren hat), Einschalten nur mit.
            if ($user->isDeleted() || ($enabled && $user->role !== UserRole::SuperAdmin && !$this->roleGrantsDeviceLog($user->id))) {
                throw UserRuleViolationException::deviceLogNotAllowed($user->id);
            }
            if ($user->notifyDeviceLog === $enabled) {
                return false;
            }

            $this->update($user->id, 'notify_device_log = ?', [$enabled ? '1' : '0']);

            return true;
        });
    }

    /**
     * Empfänger der Benachrichtigung „Log“ für Vorgänge einer Stadt: aktive, nicht gesperrte Konten
     * mit eingeschalteter Option, und zwar die Konten dieser Stadt, deren Rolle die Aktion mail.log
     * hat (Rolle Admin immer, die Standard-Rolle Benutzer nie), und alle Super-Admins (diese
     * zuletzt), je nach ID. Konten anderer Städte nie.
     *
     * @return list<User>
     */
    public function listDeviceLogRecipients(Tenant $tenant): array
    {
        [$grants, $params] = PermissionRepository::grantsActionSql('u', Action::MailLog);

        return $this->fetchAll(
            'SELECT ' . self::COLUMNS . " FROM fe_users u WHERE notify_device_log = 1 AND status = 'active' AND locked_at IS NULL"
            . " AND ((tenant_id = ? AND role <> 'superadmin' AND " . $grants . ") OR (tenant_key = 0 AND role = 'superadmin'))"
            . ' ORDER BY tenant_key = 0, id',
            [$tenant->id, ...$params],
        );
    }

    /** Ob die Rolle des Kontos einer Stadt die Aktion mail.log hat. */
    private function roleGrantsDeviceLog(int $id): bool
    {
        [$grants, $params] = PermissionRepository::grantsActionSql('u', Action::MailLog);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM fe_users u WHERE u.id = ? AND ' . $grants);
        $stmt->execute([$id, ...$params]);

        return (int) $stmt->fetchColumn() === 1;
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
     * Prüfung des Aufrufers im gesperrten Stand (UserAdministration, F5); wirft bei einem Verstoß.
     *
     * @param (\Closure(User): void)|null $authorize
     */
    private static function authorize(User $user, ?\Closure $authorize): void
    {
        if ($authorize !== null) {
            $authorize($user);
        }
    }

    private static function kindOf(Role $role): UserRole
    {
        return $role->systemRole === SystemRole::Admin ? UserRole::Admin : UserRole::User;
    }

    /**
     * @throws UserRuleViolationException Rolle Super-Admin
     */
    private function systemRoleFor(User $user, UserRole $kind): Role
    {
        $systemRole = match ($kind) {
            UserRole::Admin => SystemRole::Admin,
            UserRole::User => SystemRole::User,
            UserRole::SuperAdmin => throw UserRuleViolationException::superAdminRole(),
        };

        return new RoleRepository($this->pdo)->findSystemRoleOfTenant(self::tenantIdOf($user), $systemRole)
            ?? throw new \LogicException(sprintf('Standard-Rolle %s fehlt.', $systemRole->value));
    }

    /**
     * @throws RoleNotFoundException
     */
    private function roleOf(User $user, int $roleId): Role
    {
        return new RoleRepository($this->pdo)->findOfTenant(self::tenantIdOf($user), $roleId)
            ?? throw RoleNotFoundException::forId($roleId);
    }

    private static function tenantIdOf(User $user): int
    {
        return $user->tenantId ?? throw UserRuleViolationException::superAdminRole();
    }

    /**
     * Setzt Rolle und Kontoart in derselben Anweisung (Regel Kontoart ↔ Rolle) und weitere Spalten.
     * Wurde die Rolle inzwischen gelöscht, scheitert der Fremdschlüssel: wie unbekannt.
     *
     * @param string            $assignments SQL aus diesem Code, nie aus Eingaben
     * @param list<string|null> $params
     *
     * @throws RoleNotFoundException
     */
    private function updateRole(int $id, Role $role, string $assignments, array $params): void
    {
        try {
            $this->update($id, $assignments . ', role = ?, role_id = ?', [...$params, self::kindOf($role)->value, (string) $role->id]);
        } catch (\PDOException $e) {
            throw ($e->errorInfo[1] ?? null) === self::ER_NO_REFERENCED_ROW ? RoleNotFoundException::forId($role->id) : $e;
        }
    }

    /**
     * @throws UserRuleViolationException
     */
    private static function assertNotProtected(User $user): void
    {
        if ($user->protected) {
            throw UserRuleViolationException::protectedAccount($user->id);
        }
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
            . " display_name = NULL, notify_device_log = 0, claude_access = 'off', deleted_at = UTC_TIMESTAMP()",
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
            'INSERT INTO fe_users (tenant_id, role, role_id, status, email, created_at, updated_at)'
            . " VALUES (?, 'admin', (" . self::SYSTEM_ROLE_ID_OF_TENANT . "), 'invited', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            [$tenant->id, $tenant->id, UserRole::Admin->value, $email->value],
            $email,
        );
        $token = new UserTokenRepository($this->pdo)->replace($user->id, TokenPurpose::SetPassword);

        return new AdminInvitation($tenant, $user, $token);
    }

    /**
     * Beendet alle Sitzungen des Kontos und mit ihnen seine Claude-Verbindungen und offenen Codes der
     * Anmeldung über „Claude verbinden“ (docs/PLAN-MCP.md, Abschnitt 2, Punkt 4: jeder Weg, der die
     * Sitzungen beendet, beendet auch die Verbindungen).
     */
    private function endSessions(int $userId): void
    {
        $this->pdo->prepare('DELETE FROM fe_sessions WHERE user_id = ?')->execute([$userId]);
        new ClaudeConnectionRepository($this->pdo)->deleteForUser($userId);
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
            (int) $row['protected'] === 1,
            (int) $row['notify_device_log'] === 1,
            $row['role_id'] === null ? null : (int) $row['role_id'],
            $row['invitation_code_id'] === null ? null : (int) $row['invitation_code_id'],
            ClaudeAccess::from((string) $row['claude_access']),
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
