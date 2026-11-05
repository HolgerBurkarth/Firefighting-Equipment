<?php

declare(strict_types=1);

namespace FirefightingEquipment\Role;

/**
 * Ergebnis von RoleAssignmentCheck.
 */
final readonly class RoleAssignmentReport
{
    /**
     * @param list<RoleAssignmentGroup> $toAssign   Konten, die Migration 0036 einer Rolle zuordnet
     * @param list<RoleAssignmentGroup> $unassigned Konten einer Stadt, die danach ohne Rolle blieben
     */
    public function __construct(
        public array $toAssign,
        public array $unassigned,
        /** Konten einer Stadt, die schon eine Rolle haben (z. B. nach Schritt 1.2 neu angelegt). */
        public int $alreadyAssigned,
        /** Konten, deren Rolle nicht zur Kontoart passt (admin ↔ Rolle Admin). */
        public int $mismatched,
        /** Super-Admins mit Rolle (0037 schlüge fehl). */
        public int $superAdminsWithRole,
        public int $superAdmins,
    ) {
    }

    public function totalToAssign(): int
    {
        return array_sum(array_map(static fn (RoleAssignmentGroup $g): int => $g->accounts, $this->toAssign));
    }

    public function totalUnassigned(): int
    {
        return array_sum(array_map(static fn (RoleAssignmentGroup $g): int => $g->accounts, $this->unassigned));
    }

    /** Läuft 0037 nach 0036 durch, und passen alle Rollen zur Kontoart? */
    public function isConsistentAfterMigration(): bool
    {
        return $this->unassigned === [] && $this->superAdminsWithRole === 0 && $this->mismatched === 0;
    }
}
