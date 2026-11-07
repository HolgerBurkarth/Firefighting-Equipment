<?php

declare(strict_types=1);

namespace FirefightingEquipment\Auth;

use FirefightingEquipment\Claude\ClaudeSession;
use FirefightingEquipment\Permission\Permissions;
use FirefightingEquipment\User\User;

/**
 * Angemeldetes Konto mit der Sitzung der aktuellen Anfrage und den Rechten seiner Rolle.
 */
final readonly class AuthSession
{
    public function __construct(
        public User $user,
        public Session $session,
        /** Rechte der Rolle; null für Super-Admins (Bereich service, keine Rolle). */
        public ?Permissions $permissions,
        /** Claude-Verbindung, wenn ein Werkzeug des Claude-Zugangs die Route aufruft; sonst null (App). */
        public ?ClaudeSession $claude = null,
    ) {
    }

    /**
     * Sitzung für den Aufruf einer Route durch ein Werkzeug des Claude-Zugangs (mcp): das Konto der
     * Verbindung mit den wirksamen Rechten (Rolle ∩ Zugang). Die Verbindung steht für die Sitzung;
     * Routen der eigenen Sitzungen erreicht kein Werkzeug.
     */
    public static function viaClaude(ClaudeSession $claude): self
    {
        $connection = $claude->connection;

        return new self(
            $claude->user,
            new Session(
                $connection->id,
                $claude->user->id,
                $connection->createdAt,
                $connection->lastSeenAt,
                $connection->createdAt->modify(sprintf('+%d seconds', $connection->ageSeconds + $connection->remainingSeconds)),
                'Claude',
            ),
            $claude->effectivePermissions(),
            $claude,
        );
    }

    public function sessionClass(): SessionClass
    {
        return self::classOf($this->permissions);
    }

    /**
     * @param Permissions|null $permissions null = Super-Admin
     */
    public static function classOf(?Permissions $permissions): SessionClass
    {
        return $permissions === null ? SessionClass::SuperAdmin : SessionClass::forPermissions($permissions);
    }
}
