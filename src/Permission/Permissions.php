<?php

declare(strict_types=1);

namespace FirefightingEquipment\Permission;

use FirefightingEquipment\Role\Role;
use FirefightingEquipment\Role\SystemRole;

/**
 * Rechte eines Kontos in der aktuellen Anfrage, gebildet aus seiner Rolle. Die Rolle Admin hat
 * immer alle Rechte, auch künftige. Werte der Datenbank, die der Katalog (noch) nicht kennt,
 * geben kein Recht.
 */
final readonly class Permissions
{
    /**
     * @param array<string, AreaLevel> $areas    Bereich (Wert von Area) → Stufe
     * @param list<Action>             $actions
     * @param string|null              $revision Rolle und ihr Stand („<id>:<version>“), null ohne Rolle
     */
    private function __construct(
        private bool $all,
        private array $areas,
        private array $actions,
        private bool $filtered = false,
        private ?string $revision = null,
    ) {
    }

    public static function all(): self
    {
        return new self(true, [], []);
    }

    public static function none(): self
    {
        return new self(false, [], []);
    }

    /**
     * @param list<array{Area, AreaLevel}> $areas
     * @param list<Action>                $actions
     */
    public static function of(array $areas, array $actions): self
    {
        $byArea = [];
        foreach ($areas as [$area, $level]) {
            $byArea[$area->value] = $level;
        }

        return new self(false, $byArea, array_values(array_unique($actions, SORT_REGULAR)));
    }

    public static function fromRole(Role $role): self
    {
        return $role->systemRole === SystemRole::Admin
            ? self::all()
            : self::fromRows($role->areas, $role->actions);
    }

    /**
     * Aus den Zeilen von fe_role_areas/fe_role_actions; unbekannte Werte geben kein Recht.
     *
     * @param array<string, string> $areas   Bereich → Stufe
     * @param list<string>          $actions
     */
    public static function fromRows(array $areas, array $actions): self
    {
        $pairs = [];
        foreach ($areas as $area => $level) {
            $knownArea = Area::tryFrom($area);
            $knownLevel = AreaLevel::tryFrom($level);
            if ($knownArea !== null && $knownLevel !== null) {
                $pairs[] = [$knownArea, $knownLevel];
            }
        }

        return self::of($pairs, array_values(array_filter(array_map(Action::tryFrom(...), $actions))));
    }

    /**
     * Dieselben Rechte mit Angaben zur Rolle, aus der sie stammen (für permissions in auth/me).
     *
     * @param bool $filtered Rolle mit Datenfilter „Sieht nur …“ (wirkt ab Schritt 1.4)
     */
    public function ofRole(int $roleId, int $roleVersion, bool $filtered): self
    {
        return new self($this->all, $this->areas, $this->actions, $filtered, $roleId . ':' . $roleVersion);
    }

    public function isAll(): bool
    {
        return $this->all;
    }

    public function level(Area $area): ?AreaLevel
    {
        return $this->all ? $area->maxLevel() : ($this->areas[$area->value] ?? null);
    }

    public function allows(Area $area, AreaLevel $level): bool
    {
        return $this->level($area)?->includes($level) ?? false;
    }

    public function isFiltered(): bool
    {
        return $this->filtered;
    }

    /** Rolle und ihr Stand („<id>:<version>“); null ohne Rolle. */
    public function revision(): ?string
    {
        return $this->revision;
    }

    /**
     * Wirksame Stufen in der Reihenfolge des Katalogs (Bereiche ohne Zugriff fehlen).
     *
     * @return array<string, AreaLevel> Bereich (Wert von Area) → Stufe
     */
    public function areas(): array
    {
        $levels = [];
        foreach (Area::cases() as $area) {
            $level = $this->level($area);
            if ($level !== null) {
                $levels[$area->value] = $level;
            }
        }

        return $levels;
    }

    /**
     * Wirksame Aktionen (can()) in der Reihenfolge des Katalogs.
     *
     * @return list<Action>
     */
    public function actions(): array
    {
        return array_values(array_filter(Action::cases(), $this->can(...)));
    }

    /** Eine Aktion wirkt nur zusammen mit dem Bereich, den sie braucht. */
    public function can(Action $action): bool
    {
        if ($this->all) {
            return true;
        }
        if (!in_array($action, $this->actions, true)) {
            return false;
        }
        $required = $action->requires();

        return $required === null || $this->allows($required[0], $required[1]);
    }
}
