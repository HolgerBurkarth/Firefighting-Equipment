<?php

declare(strict_types=1);

namespace FirefightingEquipment\Auth;

use FirefightingEquipment\Permission\PermissionRepository;
use FirefightingEquipment\Permission\Permissions;
use FirefightingEquipment\Tenant\Tenant;
use FirefightingEquipment\User\User;
use FirefightingEquipment\User\UserRepository;
use FirefightingEquipment\User\UserRole;
use FirefightingEquipment\User\UserStatus;

/**
 * Legt Sitzungen an und prüft sie bei jeder Anfrage: Höchstdauer und Inaktivität je Klasse
 * (SessionClass, unter einer Stadt aus den Rechten der Rolle), Konto aktiv und nicht gesperrt,
 * Konto gehört zum Bereich der Adresse (Super-Admins nur unter service, Stadt-Konten nur unter
 * ihrer Stadt). Die Rechte lädt resume() einmal je Anfrage (AuthSession::$permissions).
 */
final readonly class SessionManager
{
    private SessionRepository $sessions;
    private UserRepository $users;
    private PermissionRepository $permissions;

    public function __construct(\PDO $pdo)
    {
        $this->sessions = new SessionRepository($pdo);
        $this->users = new UserRepository($pdo);
        $this->permissions = new PermissionRepository($pdo);
    }

    /**
     * @param Tenant|null $tenant null = Bereich service
     *
     * @return AuthSession|null null, wenn die Sitzung fehlt, abgelaufen ist oder nicht zum Bereich passt
     */
    public function resume(SessionToken $token, ?Tenant $tenant): ?AuthSession
    {
        $found = $this->sessions->findByTokenHash($token->hash());
        if ($found === null) {
            return null;
        }

        $session = $found['session'];
        $user = $this->users->findById($session->userId);
        if ($user === null || !self::mayUse($user, $tenant)) {
            return null;
        }

        // Klasse der aktuellen Rechte: Wird eine Rolle strenger, gilt die kürzere Höchstdauer sofort
        // (ab dem Anmelden gerechnet); eine mildere verlängert expires_at nicht.
        $permissions = $tenant === null ? null : $this->permissions->forUser($tenant, $user->id);
        $class = AuthSession::classOf($permissions);
        if (
            $found['expired']
            || $found['ageSeconds'] >= SessionLifetime::maxSeconds($class)
            || $found['idleSeconds'] >= SessionLifetime::idleSeconds($class)
        ) {
            $this->sessions->delete($session->id);

            return null;
        }

        if ($found['idleSeconds'] >= SessionLifetime::TOUCH_INTERVAL_SECONDS) {
            $this->sessions->touch($session->id);
            $session = $this->sessions->findById($session->id) ?? $session;
        }

        return new AuthSession($user, $session, $permissions);
    }

    /**
     * Legt eine Sitzung an (Höchstdauer der Klasse aus den Rechten der Rolle). Der Aufrufer prüft
     * vorher, ob das Konto sich anmelden darf.
     */
    public function start(User $user, string $deviceLabel): StartedSession
    {
        $permissions = $this->permissionsOf($user);
        $token = SessionToken::generate();
        $session = $this->sessions->create(
            $user->id,
            $token->hash(),
            SessionLifetime::maxSeconds(AuthSession::classOf($permissions)),
            $deviceLabel,
        );

        return new StartedSession(new AuthSession($user, $session, $permissions), $token);
    }

    public function end(SessionToken $token): void
    {
        $this->sessions->deleteByTokenHash($token->hash());
    }

    /**
     * @return Permissions|null null für Super-Admins (keine Stadt, keine Rolle)
     */
    private function permissionsOf(User $user): ?Permissions
    {
        return $user->tenantId === null ? null : $this->permissions->forTenantUser($user->tenantId, $user->id);
    }

    /**
     * Ob das Konto sich im Bereich anmelden bzw. dort eine Sitzung nutzen darf.
     *
     * @param Tenant|null $tenant null = Bereich service
     */
    public static function mayUse(User $user, ?Tenant $tenant): bool
    {
        if ($user->status !== UserStatus::Active || $user->isLocked()) {
            return false;
        }

        return $tenant === null
            ? $user->role === UserRole::SuperAdmin && $user->tenantId === null
            : $user->role !== UserRole::SuperAdmin && $user->tenantId === $tenant->id;
    }
}
