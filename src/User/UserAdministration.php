<?php

declare(strict_types=1);

namespace FirefightingEquipment\User;

use FirefightingEquipment\Tenant\Tenant;

/**
 * Benutzerverwaltung durch einen Admin seiner Stadt. Ergänzt UserRepository (dort gelten weiter
 * alle Regeln, z. B. letzter Admin, Sitzungen beenden) um zwei Dinge:
 * - Nur Konten der eigenen Stadt; fremde und unbekannte IDs sind nicht unterscheidbar
 *   (UserNotFoundException). Die Stadt eines Kontos ändert sich nie, daher genügt die Prüfung vorab.
 * - Ein Admin darf sich nicht selbst sperren, herabstufen oder löschen.
 */
final readonly class UserAdministration
{
    public function __construct(private UserRepository $users, private Tenant $tenant, private User $actor)
    {
    }

    /**
     * @throws UserNotFoundException
     */
    public function find(int $id): User
    {
        return $this->users->findByTenantAndId($this->tenant, $id) ?? throw UserNotFoundException::forId($id);
    }

    /**
     * @throws UserNotFoundException
     * @throws UserRuleViolationException
     */
    public function approve(int $id, PersonName $displayName, UserRole $role): User
    {
        return $this->users->approve($this->find($id)->id, $displayName, $role);
    }

    /**
     * @return User das Konto vor dem Anonymisieren (für die Benachrichtigung)
     *
     * @throws UserNotFoundException
     * @throws UserRuleViolationException
     */
    public function reject(int $id): User
    {
        return $this->users->reject($this->find($id)->id);
    }

    /**
     * @throws UserNotFoundException
     * @throws UserRuleViolationException
     */
    public function lock(int $id): User
    {
        $user = $this->find($id);
        if ($user->id === $this->actor->id) {
            throw UserRuleViolationException::selfLock();
        }
        $this->users->lock($user->id);

        return $this->find($id);
    }

    /**
     * @throws UserNotFoundException
     * @throws UserRuleViolationException
     */
    public function unlock(int $id): User
    {
        $this->users->unlock($this->find($id)->id);

        return $this->find($id);
    }

    /**
     * @throws UserNotFoundException
     * @throws UserRuleViolationException
     */
    public function changeRole(int $id, UserRole $role): User
    {
        $user = $this->find($id);
        if ($user->id === $this->actor->id && $role !== $user->role) {
            throw UserRuleViolationException::selfDemote();
        }
        $this->users->changeRole($user->id, $role);

        return $this->find($id);
    }

    /**
     * @throws UserNotFoundException
     * @throws UserRuleViolationException
     */
    public function changeDisplayName(int $id, PersonName $displayName): User
    {
        $this->users->changeDisplayName($this->find($id)->id, $displayName);

        return $this->find($id);
    }

    /**
     * Löschen = Anonymisieren.
     *
     * @throws UserNotFoundException
     * @throws UserRuleViolationException
     */
    public function delete(int $id): User
    {
        $user = $this->find($id);
        if ($user->id === $this->actor->id) {
            throw UserRuleViolationException::selfDelete();
        }
        $this->users->anonymize($user->id);

        return $this->find($id);
    }
}
