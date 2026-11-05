<?php

declare(strict_types=1);

namespace FirefightingEquipment\Permission;

use FirefightingEquipment\Role\Role;
use FirefightingEquipment\Role\SystemRole;

/**
 * Rechte eines Kontos in der aktuellen Anfrage, gebildet aus seiner Rolle, mit ihrem Datenfilter
 * (DeviceVisibility). Die Rolle Admin hat immer alle Rechte, auch künftige, und keinen Filter. Werte
 * der Datenbank, die der Katalog (noch) nicht kennt, geben kein Recht.
 *
 * F3 (Plan Phase 1): Mit Datenfilter wirken Standorte und Gerätetypen nur bis Ansehen, Benutzer gar
 * nicht und der CSV-Import nicht, auch wenn die Rolle sie in der Datenbank hätte (der Editor der
 * Rollen lässt das ab Schritt 1.5 nicht speichern).
 */
final readonly class Permissions
{
    private DeviceVisibility $visibility;

    /**
     * @param array<string, AreaLevel> $areas      Bereich (Wert von Area) → Stufe
     * @param list<Action>             $actions
     * @param DeviceVisibility|null    $visibility null = alle
     * @param string|null              $revision   Rolle und ihr Stand („<id>:<version>“), null ohne Rolle
     */
    private function __construct(
        private bool $all,
        private array $areas,
        private array $actions,
        ?DeviceVisibility $visibility = null,
        private ?string $revision = null,
    ) {
        $this->visibility = $visibility ?? DeviceVisibility::all();
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
     * Dieselben Rechte mit Angaben zur Rolle, aus der sie stammen (für permissions in auth/me), und
     * ihrem Datenfilter.
     */
    public function ofRole(int $roleId, int $roleVersion, DeviceVisibility $visibility): self
    {
        if ($this->all && !$visibility->isAll()) {
            throw new \LogicException('Die Rolle Admin hat keinen Datenfilter.');
        }

        return new self($this->all, $this->areas, $this->actions, $visibility, $roleId . ':' . $roleVersion);
    }

    public function isAll(): bool
    {
        return $this->all;
    }

    public function level(Area $area): ?AreaLevel
    {
        if ($this->all) {
            return $area->maxLevel();
        }
        $level = $this->areas[$area->value] ?? null;
        if ($level === null || $this->visibility->isAll()) {
            return $level;
        }

        // F3: mit Datenfilter keine Verwaltung.
        return match ($area) {
            Area::Users => null,
            Area::Locations, Area::DeviceTypes => AreaLevel::Read,
            default => $level,
        };
    }

    public function allows(Area $area, AreaLevel $level): bool
    {
        return $this->level($area)?->includes($level) ?? false;
    }

    public function isFiltered(): bool
    {
        return !$this->visibility->isAll();
    }

    /** Welche Geräte, Standorte und Gerätetypen das Konto sieht. */
    public function visibility(): DeviceVisibility
    {
        return $this->visibility;
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
        if (!in_array($action, $this->actions, true) || ($action === Action::CsvImport && $this->isFiltered())) {
            return false;
        }
        $required = $action->requires();

        return $required === null || $this->allows($required[0], $required[1]);
    }
}
