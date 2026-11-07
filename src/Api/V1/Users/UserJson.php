<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Users;

use FirefightingEquipment\Api\V1\Auth\AuthJson;
use FirefightingEquipment\Claude\ClaudeConnectionRepository;
use FirefightingEquipment\Claude\ClaudeConnectionSummary;
use FirefightingEquipment\Invitation\InvitationCodeRepository;
use FirefightingEquipment\Role\RoleRepository;
use FirefightingEquipment\Tenant\Tenant;
use FirefightingEquipment\User\User;

/**
 * JSON-Darstellung eines Kontos für die Benutzerverwaltung (nie mit Passwort-Hash). Zeiten in UTC.
 * role ist die Kontoart (superadmin, admin, user), role_id/role_name die Rolle der Stadt (Plan
 * Phase 1, Schritt 1.6; bei Super-Admins null), invitation_code der Code der Registrierung (null ohne).
 * claude_access (off/read/write) und claude_connections {count, last_used_at}: Claude-Zugang und
 * bestehende Verbindungen (docs/PLAN-MCP.md, Schritt M1; ohne Verbindung count 0 und null).
 */
final class UserJson
{
    /**
     * @param array<int, string>      $roleNames        Rollen-ID → Name
     * @param array<int, string|null> $invitationLabels Code-ID → Bezeichnung
     * @param array<int, ClaudeConnectionSummary> $claudeConnections Konto-ID → Verbindungen
     *
     * @return array<string, mixed>
     */
    public static function user(User $user, array $roleNames = [], array $invitationLabels = [], array $claudeConnections = []): array
    {
        $connections = $claudeConnections[$user->id] ?? null;

        return [
            'id' => $user->id,
            'label' => $user->label(),
            'email' => $user->email?->value,
            'name' => $user->name?->value,
            'display_name' => $user->displayName?->value,
            'role' => $user->role->value,
            'role_label' => $user->role->label(),
            'role_id' => $user->roleId,
            'role_name' => $user->roleId === null ? null : ($roleNames[$user->roleId] ?? null),
            'status' => $user->status->value,
            'status_label' => $user->status->label(),
            'locked' => $user->isLocked(),
            'email_verified_at' => $user->emailVerifiedAt === null ? null : AuthJson::time($user->emailVerifiedAt),
            'created_at' => AuthJson::time($user->createdAt),
            'invitation_code' => $user->invitationCodeId === null ? null : [
                'id' => $user->invitationCodeId,
                'label' => $invitationLabels[$user->invitationCodeId] ?? null,
            ],
            'claude_access' => $user->claudeAccess->value,
            'claude_access_label' => $user->claudeAccess->label(),
            'claude_connections' => [
                'count' => $connections?->count ?? 0,
                'last_used_at' => $connections === null ? null : AuthJson::time($connections->lastUsedAt),
            ],
        ];
    }

    /**
     * Darstellung für Konten einer Stadt: lädt einmal die Namen ihrer Rollen, die Bezeichnungen
     * ihrer Einladungscodes und die Claude-Verbindungen.
     *
     * @return \Closure(User): array<string, mixed>
     */
    public static function forTenant(\PDO $pdo, Tenant $tenant): \Closure
    {
        $roleNames = [];
        foreach (new RoleRepository($pdo)->listByTenant($tenant) as $role) {
            $roleNames[$role->id] = $role->name;
        }
        $labels = [];
        foreach (new InvitationCodeRepository($pdo)->listByTenant($tenant) as $code) {
            $labels[$code->id] = $code->label?->value;
        }

        $connections = new ClaudeConnectionRepository($pdo)->summariesForTenant($tenant->id);

        return static fn (User $user): array => self::user($user, $roleNames, $labels, $connections);
    }
}
