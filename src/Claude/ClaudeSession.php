<?php

declare(strict_types=1);

namespace FirefightingEquipment\Claude;

use FirefightingEquipment\Permission\Permissions;
use FirefightingEquipment\User\User;

/**
 * Ein Aufruf über eine Claude-Verbindung (Route mit Access::Claude): das Konto, die Verbindung, der
 * wirksame Zugang (Minimum aus Zustimmung und aktuellem Zugang des Kontos) und die Rechte der Rolle,
 * alles bei diesem Aufruf frisch gelesen (ClaudeSessions::resume).
 */
final readonly class ClaudeSession
{
    public function __construct(
        public User $user,
        public ClaudeConnection $connection,
        /** nie Off */
        public ClaudeAccess $access,
        /** Rechte der Rolle (samt Datenfilter); wirksam sind effectivePermissions() */
        public Permissions $permissions,
    ) {
    }

    /**
     * Rechte der Rolle ∩ Zugang, ohne Benutzer, Einstellungen und Log-Mails (Permissions::forClaude);
     * damit prüfen die Werkzeuge jede Route (docs/PLAN-MCP.md, Abschnitt 6).
     */
    public function effectivePermissions(): Permissions
    {
        return $this->permissions->forClaude($this->access);
    }
}
