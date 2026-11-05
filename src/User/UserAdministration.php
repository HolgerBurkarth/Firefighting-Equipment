<?php

declare(strict_types=1);

namespace FirefightingEquipment\User;

use FirefightingEquipment\Permission\Permissions;
use FirefightingEquipment\Role\Role;
use FirefightingEquipment\Role\RoleNotFoundException;
use FirefightingEquipment\Role\RoleRepository;
use FirefightingEquipment\Role\SystemRole;
use FirefightingEquipment\Tenant\Tenant;

/**
 * Benutzerverwaltung durch ein Konto mit dem Recht „Benutzer: Ändern“ seiner Stadt. Ergänzt
 * UserRepository (dort gelten weiter alle Regeln, z. B. letzter Admin, Sitzungen beenden) um:
 * - Nur Konten der eigenen Stadt; fremde und unbekannte IDs sind nicht unterscheidbar
 *   (UserNotFoundException). Die Stadt eines Kontos ändert sich nie, daher genügt die Prüfung vorab.
 * - Niemand sperrt, löscht sich selbst oder ändert die eigene Rolle.
 * - Schutzregeln F5 (Plan Phase 1), geprüft im gesperrten Stand des Kontos: Die Rolle Admin vergibt
 *   oder entzieht nur ein Admin, und nur ein Admin sperrt, entsperrt, löscht Admins oder ändert ihren
 *   Anzeigenamen. Wer eine Rolle zuweist oder freischaltet, braucht alle ihre Rechte
 *   (Permissions::covers); wer eine Rolle ändert, auch alle Rechte der bisherigen (sonst ließe sich
 *   jemandem mit mehr Rechten die Rolle nehmen, wie bei Admin).
 */
final readonly class UserAdministration
{
    public function __construct(
        private UserRepository $users,
        private RoleRepository $roles,
        private Tenant $tenant,
        private User $actor,
        private Permissions $actorPermissions,
    ) {
    }

    /**
     * @throws UserNotFoundException
     */
    public function find(int $id): User
    {
        return $this->users->findByTenantAndId($this->tenant, $id) ?? throw UserNotFoundException::forId($id);
    }

    /**
     * Freischalten mit einer Rolle der Stadt.
     *
     * @throws UserNotFoundException
     * @throws RoleNotFoundException
     * @throws UserRuleViolationException
     */
    public function approve(int $id, PersonName $displayName, int $roleId): User
    {
        return $this->users->approveWithRole(
            $this->find($id)->id,
            $displayName,
            $roleId,
            fn (User $user, Role $role) => $this->assertMayAssign($role),
        );
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
        $this->users->lock($user->id, $this->assertMayManage(...));

        return $this->find($id);
    }

    /**
     * @throws UserNotFoundException
     * @throws UserRuleViolationException
     */
    public function unlock(int $id): User
    {
        $this->users->unlock($this->find($id)->id, $this->assertMayManage(...));

        return $this->find($id);
    }

    /**
     * Rolle zuweisen; die bisherige Rolle zu behalten ist auch am eigenen Konto erlaubt.
     *
     * @return array{User, bool} Stand danach und ob sich die Rolle geändert hat
     *
     * @throws UserNotFoundException
     * @throws RoleNotFoundException
     * @throws UserRuleViolationException
     */
    public function assignRole(int $id, int $roleId): array
    {
        $changed = $this->users->assignRole($this->find($id)->id, $roleId, function (User $user, Role $role): void {
            if ($user->roleId === $role->id) {
                return;
            }
            if ($user->id === $this->actor->id) {
                throw UserRuleViolationException::selfRole();
            }
            $this->assertMayManage($user);
            $this->assertMayAssign($role);
        });

        return [$this->find($id), $changed];
    }

    /**
     * Ob das Konto die Rolle zuweisen darf (für die Auswahl, GET roles); null = ja, sonst der Grund.
     */
    public function assignBlocker(Role $role): ?string
    {
        try {
            $this->assertMayAssign($role);

            return null;
        } catch (UserRuleViolationException $e) {
            return $e->getMessage();
        }
    }

    /**
     * Prüfung vorab, z. B. für eine Sammelaktion (dieselbe Prüfung läuft je Konto noch einmal im
     * gesperrten Stand).
     *
     * @throws UserRuleViolationException
     */
    public function assertMayAssign(Role $role): void
    {
        if ($this->actorPermissions->isAll()) {
            return;
        }
        if ($role->systemRole === SystemRole::Admin) {
            throw UserRuleViolationException::adminRoleOnlyByAdmins();
        }
        if (!$this->actorPermissions->covers(Permissions::fromRoleWithFilter($role))) {
            throw UserRuleViolationException::roleBeyondOwnRights($role->name);
        }
    }

    /**
     * @throws UserNotFoundException
     * @throws UserRuleViolationException
     */
    public function changeDisplayName(int $id, PersonName $displayName): User
    {
        $this->users->changeDisplayName($this->find($id)->id, $displayName, $this->assertMayManage(...));

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
        $this->users->anonymize($user->id, $this->assertMayManage(...));

        return $this->find($id);
    }

    /**
     * F5: Admins ändern nur Admins; ein Konto, dessen Rolle mehr darf als man selbst, ändert man nicht.
     *
     * @throws UserRuleViolationException
     */
    private function assertMayManage(User $user): void
    {
        if ($this->actorPermissions->isAll()) {
            return;
        }
        if ($user->role === UserRole::Admin) {
            throw UserRuleViolationException::adminOnlyByAdmins();
        }
        $role = $user->roleId === null ? null : $this->roles->find($this->tenant, $user->roleId);
        if ($role !== null && !$this->actorPermissions->covers(Permissions::fromRoleWithFilter($role))) {
            throw UserRuleViolationException::accountBeyondOwnRights($role->name);
        }
    }
}
