<?php

declare(strict_types=1);

namespace FirefightingEquipment\Role;

/**
 * Eine Änderung an Rollen verletzt eine Regel (Standard-Rollen geschützt, Rolle noch in Gebrauch,
 * F3: Verwaltungsrechte nur ohne Datenfilter). $details enthält maschinenlesbare Angaben für die API
 * (Feld "details" der Fehlerantwort).
 */
final class RoleRuleViolationException extends RoleException
{
    /**
     * @param array<string, int|list<string>>|null $details
     */
    private function __construct(string $message, public readonly ?array $details = null)
    {
        parent::__construct($message);
    }

    public static function adminNotEditable(): self
    {
        return new self('Die Rolle „Admin“ hat immer alle Rechte und lässt sich nicht ändern.');
    }

    public static function systemRoleNotDeletable(Role $role): self
    {
        return new self($role->systemRole === SystemRole::Admin
            ? 'Die Rolle „Admin“ lässt sich nicht löschen.'
            : sprintf('Die Standard-Rolle „%s“ lässt sich nicht löschen; sie bekommt jede neue Registrierung ohne eigene Rolle.', $role->name));
    }

    public static function inUse(Role $role, RoleUsage $usage): self
    {
        $parts = [];
        if ($usage->accounts > 0) {
            $parts[] = $usage->accounts === 1 ? '1 Person' : sprintf('%d Personen', $usage->accounts);
        }
        if ($usage->openInvitationCodes > 0) {
            $parts[] = $usage->openInvitationCodes === 1 ? '1 offener Einladungscode' : sprintf('%d offene Einladungscodes', $usage->openInvitationCodes);
        }

        return new self(
            sprintf(
                'Die Rolle „%s“ wird noch verwendet (%s). Löschen geht erst, wenn niemand sie hat und kein offener Einladungscode auf sie zeigt.',
                $role->name,
                implode(', ', $parts),
            ),
            ['accounts' => $usage->accounts, 'invitation_codes' => $usage->openInvitationCodes],
        );
    }

    /**
     * F3: Mit Datenfilter „Sieht nur …“ gibt es keine Verwaltungsrechte.
     *
     * @param list<string> $areas   Bereiche mit unzulässiger Stufe (Wert von Area)
     * @param list<string> $actions unzulässige Aktionen (Wert von Action)
     * @param list<string> $labels  ihre Bezeichnungen für die Meldung
     */
    public static function administrationWithFilter(array $areas, array $actions, array $labels): self
    {
        return new self(
            sprintf(
                'Rollen mit „Sieht nur …“ dürfen nicht verwalten (%s). Bitte diese Rechte entfernen oder „Alle Standorte“ und „Alle Ausrüstungstypen“ wählen.',
                implode(', ', $labels),
            ),
            ['areas' => $areas, 'actions' => $actions],
        );
    }
}
