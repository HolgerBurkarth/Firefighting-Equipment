<?php

declare(strict_types=1);

namespace FirefightingEquipment\Auth;

use FirefightingEquipment\Tenant\Tenant;
use FirefightingEquipment\User\User;
use FirefightingEquipment\User\UserRepository;
use FirefightingEquipment\User\UserRole;
use FirefightingEquipment\User\UserStatus;

/**
 * Legt Sitzungen an und prüft sie bei jeder Anfrage: Höchstdauer und Inaktivität je Rolle, Konto
 * aktiv und nicht gesperrt, Konto gehört zum Bereich der Adresse (Super-Admins nur unter service,
 * Stadt-Konten nur unter ihrer Stadt).
 */
final readonly class SessionManager
{
    private SessionRepository $sessions;
    private UserRepository $users;

    public function __construct(\PDO $pdo)
    {
        $this->sessions = new SessionRepository($pdo);
        $this->users = new UserRepository($pdo);
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

        if ($found['expired'] || $found['idleSeconds'] >= SessionLifetime::idleSeconds($user->role)) {
            $this->sessions->delete($session->id);

            return null;
        }

        if ($found['idleSeconds'] >= SessionLifetime::TOUCH_INTERVAL_SECONDS) {
            $this->sessions->touch($session->id);
            $session = $this->sessions->findById($session->id) ?? $session;
        }

        return new AuthSession($user, $session);
    }

    /**
     * Legt eine Sitzung an (Höchstdauer je Rolle). Der Aufrufer prüft vorher, ob das Konto sich
     * anmelden darf.
     */
    public function start(User $user, string $deviceLabel): StartedSession
    {
        $token = SessionToken::generate();
        $session = $this->sessions->create(
            $user->id,
            $token->hash(),
            SessionLifetime::maxSeconds($user->role),
            $deviceLabel,
        );

        return new StartedSession(new AuthSession($user, $session), $token);
    }

    public function end(SessionToken $token): void
    {
        $this->sessions->deleteByTokenHash($token->hash());
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
