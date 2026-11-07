<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tenant;

use FirefightingEquipment\Claude\ClaudeAccess;
use FirefightingEquipment\Claude\ClaudeAvailability;
use FirefightingEquipment\User\AdminInvitation;
use FirefightingEquipment\User\DuplicateUserEmailException;
use FirefightingEquipment\User\EmailAddress;
use FirefightingEquipment\User\User;
use FirefightingEquipment\User\UserNotFoundException;
use FirefightingEquipment\User\UserRepository;
use FirefightingEquipment\User\UserRole;
use FirefightingEquipment\User\UserRuleViolationException;
use FirefightingEquipment\User\UserStatus;

/**
 * Verwaltung der Städte durch den Super-Admin (API und bin/tenant.php). Ergänzt TenantRepository
 * und UserRepository (dort gelten weiter alle Regeln) um:
 * - Städte per ID; unbekannte IDs → TenantNotFoundException.
 * - Konten nur der jeweiligen Stadt; fremde und unbekannte IDs → UserNotFoundException.
 * - In eine gesperrte Stadt wird nicht eingeladen (der Link führte dort zu 403).
 * - Claude-Zugang (F12): nur in der Test-Stadt; der Super-Admin setzt ihn nur für Konten mit der
 *   Rolle Admin, trennt aber die Verbindungen jedes Kontos der Stadt.
 */
final readonly class TenantAdministration
{
    public function __construct(private TenantRepository $tenants, private UserRepository $users)
    {
    }

    /**
     * @throws TenantNotFoundException
     */
    public function find(int $id): Tenant
    {
        return $this->tenants->findById($id) ?? throw TenantNotFoundException::forId($id);
    }

    /**
     * @throws TenantNotFoundException
     */
    public function summary(int $id): TenantSummary
    {
        return $this->tenants->summary($id) ?? throw TenantNotFoundException::forId($id);
    }

    /**
     * @return list<TenantSummary> nach Kürzel sortiert
     */
    public function summaries(): array
    {
        return $this->tenants->summaries();
    }

    /**
     * Neue Stadt mit eingeladenem ersten Admin (eine Transaktion).
     *
     * @throws DuplicateTenantSlugException
     * @throws DuplicateUserEmailException
     */
    public function create(TenantSlug $slug, TenantName $name, EmailAddress $adminEmail): AdminInvitation
    {
        return $this->users->createTenantWithInvitedAdmin($slug, $name, $adminEmail);
    }

    /**
     * @throws TenantNotFoundException
     */
    public function rename(Tenant $tenant, TenantName $name): void
    {
        $this->tenants->rename($tenant->slug, $name);
    }

    /**
     * Sperren beendet alle Sitzungen der Konten dieser Stadt (TenantRepository::setActive).
     *
     * @throws TenantNotFoundException
     */
    public function setActive(Tenant $tenant, bool $active): void
    {
        $this->tenants->setActive($tenant->slug, $active);
    }

    /**
     * @throws UserRuleViolationException Stadt gesperrt
     * @throws DuplicateUserEmailException die Adresse hat in der Stadt schon ein Konto
     */
    public function inviteAdmin(Tenant $tenant, EmailAddress $email): AdminInvitation
    {
        self::assertActive($tenant);

        return $this->users->inviteAdmin($tenant, $email);
    }

    /**
     * Neuer Link für eine offene Einladung; der alte wird ungültig.
     *
     * @throws UserNotFoundException      Konto gehört nicht zur Stadt
     * @throws UserRuleViolationException Stadt gesperrt, keine offene Einladung, Konto gesperrt
     */
    public function renewInvitation(Tenant $tenant, int $userId): AdminInvitation
    {
        self::assertActive($tenant);
        $user = $this->findUser($tenant, $userId);
        $token = $this->users->renewInvitation($user->id);

        return new AdminInvitation($tenant, $user, $token);
    }

    /**
     * @throws UserNotFoundException
     * @throws UserRuleViolationException
     */
    public function setClaudeAccess(Tenant $tenant, int $userId, ClaudeAccess $access): User
    {
        self::assertClaudeAvailable($tenant);
        $user = $this->findUser($tenant, $userId);
        $this->users->setClaudeAccess($user->id, $access, static function (User $locked): void {
            if ($locked->role !== UserRole::Admin) {
                throw UserRuleViolationException::claudeAccessOfAdminsOnly();
            }
        });

        return $this->findUser($tenant, $userId);
    }

    /**
     * @throws UserNotFoundException
     * @throws UserRuleViolationException
     */
    public function endClaudeConnections(Tenant $tenant, int $userId): User
    {
        self::assertClaudeAvailable($tenant);
        $user = $this->findUser($tenant, $userId);
        $this->users->endClaudeConnections($user->id);

        return $this->findUser($tenant, $userId);
    }

    /**
     * @throws UserNotFoundException
     */
    public function findUser(Tenant $tenant, int $userId): User
    {
        return $this->users->findByTenantAndId($tenant, $userId) ?? throw UserNotFoundException::forId($userId);
    }

    /**
     * Konten der Stadt (nur lesend). Ohne Filter alle außer gelöschten (anonymisierten).
     *
     * @return list<User> nach ID sortiert
     */
    public function users(Tenant $tenant, ?UserStatus $status): array
    {
        $list = $this->users->listByTenant($tenant, $status);

        return $status === null ? array_values(array_filter($list, static fn (User $u): bool => !$u->isDeleted())) : $list;
    }

    /**
     * @throws UserRuleViolationException
     */
    private static function assertClaudeAvailable(Tenant $tenant): void
    {
        if (!ClaudeAvailability::for($tenant)) {
            throw UserRuleViolationException::claudeUnavailable();
        }
    }

    /**
     * @throws UserRuleViolationException
     */
    private static function assertActive(Tenant $tenant): void
    {
        if (!$tenant->active) {
            throw UserRuleViolationException::tenantInactive();
        }
    }
}
