<?php

declare(strict_types=1);

namespace FirefightingEquipment\Claude;

use FirefightingEquipment\Auth\AuthSession;
use FirefightingEquipment\Auth\SessionLifetime;
use FirefightingEquipment\Auth\SessionManager;
use FirefightingEquipment\OAuth\OAuthSecret;
use FirefightingEquipment\Permission\PermissionRepository;
use FirefightingEquipment\Tenant\Tenant;
use FirefightingEquipment\User\UserRepository;

/**
 * Prüft eine Claude-Verbindung bei jedem Aufruf (mcp) und jedem Erneuern (oauth/token) neu, ohne
 * Zwischenspeicher (docs/MCP-KONZEPT.md, Abschnitt 2): Stadt mit Claude-Zugang, Konto aktiv, nicht
 * gesperrt und in dieser Stadt, Kontoart wie beim Verbinden, Zugang nicht „aus“, Rechte der Rolle,
 * Höchstdauer und Inaktivität nach der Sitzungsklasse der aktuellen Rolle (wie die Sitzungen der
 * App). Eine Verbindung, die das nicht mehr erfüllt, wird gelöscht.
 */
final readonly class ClaudeSessions
{
    private ClaudeConnectionRepository $connections;
    private UserRepository $users;
    private PermissionRepository $permissions;

    public function __construct(\PDO $pdo)
    {
        $this->connections = new ClaudeConnectionRepository($pdo);
        $this->users = new UserRepository($pdo);
        $this->permissions = new PermissionRepository($pdo);
    }

    /**
     * Aufruf mit Zugriffstoken (Authorization: Bearer ffa_…).
     *
     * @return ClaudeSession|null null: Token unbekannt, abgelaufen oder die Verbindung gilt nicht mehr
     */
    public function resume(OAuthSecret $accessToken, Tenant $tenant): ?ClaudeSession
    {
        $connection = $this->connections->findByAccessHash($tenant->id, $accessToken->hash());
        if ($connection === null || !$connection->accessTokenValid) {
            return null;
        }
        $session = $this->check($connection, $tenant);
        if ($session !== null && $connection->idleSeconds >= SessionLifetime::TOUCH_INTERVAL_SECONDS) {
            $this->connections->touch($connection->id);
        }

        return $session;
    }

    /**
     * @return ClaudeSession|null null (und die Verbindung ist gelöscht), wenn sie nicht mehr gilt
     */
    public function check(ClaudeConnection $connection, Tenant $tenant): ?ClaudeSession
    {
        $session = $this->evaluate($connection, $tenant);
        if ($session === null) {
            $this->connections->delete($connection->id);
        }

        return $session;
    }

    private function evaluate(ClaudeConnection $connection, Tenant $tenant): ?ClaudeSession
    {
        if (!ClaudeAvailability::for($tenant) || !$tenant->active || $connection->tenantId !== $tenant->id || $connection->expired) {
            return null;
        }
        $user = $this->users->findById($connection->userId);
        if ($user === null || !SessionManager::mayUse($user, $tenant) || $user->role !== $connection->accountKind) {
            return null;
        }
        $access = $connection->access->min($user->claudeAccess);
        if ($access === ClaudeAccess::Off) {
            return null;
        }
        $permissions = $this->permissions->forUser($tenant, $user->id);
        $class = AuthSession::classOf($permissions);
        if ($connection->ageSeconds >= SessionLifetime::maxSeconds($class) || $connection->idleSeconds >= SessionLifetime::idleSeconds($class)) {
            return null;
        }

        return new ClaudeSession($user, $connection, $access, $permissions);
    }
}
