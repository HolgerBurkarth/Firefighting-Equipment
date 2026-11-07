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
        /** Rechte der Rolle (samt Datenfilter); die Werkzeuge schränken sie ab Schritt M5 auf den Zugang ein */
        public Permissions $permissions,
    ) {
    }
}
