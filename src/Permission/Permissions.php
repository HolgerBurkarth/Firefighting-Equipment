<?php

declare(strict_types=1);

namespace FirefightingEquipment\Permission;

use FirefightingEquipment\Claude\ClaudeAccess;
use FirefightingEquipment\Device\LocationTask;
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
    /** Bereiche, die es über Claude nie gibt (Benutzer, Einladungscodes, Einstellungen der Stadt). */
    private const array NEVER_FOR_CLAUDE = [Area::Users->value, Area::Settings->value];

    /**
     * @param array<string, AreaLevel> $areas    Bereich (Wert von Area) → Stufe
     * @param list<Action>             $actions
     * @param string|null              $revision Rolle und ihr Stand („<id>:<version>“), null ohne Rolle
     * @param string|null              $roleName Name der Rolle (für auth/me), null ohne Rolle
     */
    private function __construct(
        private bool $all,
        private array $areas,
        private array $actions,
        private DeviceVisibility $visibility,
        private ?string $revision = null,
        private ?string $roleName = null,
        /** @var list<Restriction> */
        private array $restrictions = [],
        /** @var list<LocationTask>|null freigeschaltete Aufgaben der Stadt; null = nicht begrenzt (Bereiche mit Aufgabe, Area::requiredTasks) */
        private ?array $enabledTasks = null,
    ) {
    }

    public static function all(): self
    {
        return new self(true, [], [], DeviceVisibility::all());
    }

    /** Keine Rechte und keine Sichtbarkeit (Konto ohne Rolle). */
    public static function none(): self
    {
        return new self(false, [], [], DeviceVisibility::none());
    }

    /**
     * Rechte ohne Datenfilter; den Filter der Rolle setzt ofRole().
     *
     * @param list<array{Area, AreaLevel}> $areas
     * @param list<Action>                $actions
     */
    public static function of(array $areas, array $actions): self
    {
        $byArea = [];
        foreach ($areas as [$area, $level]) {
            $byArea[$area->value] = $level;
        }

        return new self(false, $byArea, array_values(array_unique($actions, SORT_REGULAR)), DeviceVisibility::all());
    }

    public static function fromRole(Role $role): self
    {
        return $role->systemRole === SystemRole::Admin
            ? self::all()
            : self::fromRows($role->areas, $role->actions);
    }

    /**
     * Wie fromRole(), mit dem Datenfilter der Rolle (Standorte der Ebene 1 ohne ihre Fächer; genügt
     * für covers() und F3, nicht für Abfragen).
     */
    public static function fromRoleWithFilter(Role $role): self
    {
        return self::fromRole($role)->ofRole($role->id, $role->version, DeviceVisibility::restricted(
            $role->filterLocations ? $role->locationIds : null,
            $role->filterDeviceTypes ? $role->deviceTypeIds : null,
        ));
    }

    /**
     * Ob diese Rechte alle von $other enthalten (Plan Phase 1, Abschnitt 5, F5: wer eine Rolle
     * zuweist, braucht alle ihre Rechte, ihr Datenfilter liegt in seinem). Verglichen werden die
     * wirksamen Rechte (F3, Aktionen nur mit ihrem Bereich). Alle Rechte (Rolle Admin) enthält nur
     * die Rolle Admin, auch wenn eine andere Rolle heute jedes Recht des Katalogs hat.
     */
    public function covers(self $other): bool
    {
        if ($this->all) {
            return true;
        }
        if ($other->all || !$this->visibility->includes($other->visibility)) {
            return false;
        }
        foreach ($other->areas() as $area => $level) {
            if (!$this->allows(Area::from($area), $level)) {
                return false;
            }
        }

        return array_all($other->actions(), $this->can(...));
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
    public function ofRole(int $roleId, int $roleVersion, DeviceVisibility $visibility, ?string $roleName = null): self
    {
        if ($this->all && !$visibility->isAll()) {
            throw new \LogicException('Die Rolle Admin hat keinen Datenfilter.');
        }

        return new self($this->all, $this->areas, $this->actions, $visibility, $roleId . ':' . $roleVersion, $roleName, $this->restrictions, $this->enabledTasks);
    }

    /**
     * Dieselben Rechte mit den Einschränkungen der Rolle (Phase 2); die Rolle Admin hat keine.
     *
     * @param list<Restriction> $restrictions
     */
    public function withRestrictions(array $restrictions): self
    {
        if ($this->all && $restrictions !== []) {
            throw new \LogicException('Die Rolle Admin hat keine Einschränkungen.');
        }

        return new self($this->all, $this->areas, $this->actions, $this->visibility, $this->revision, $this->roleName, array_values(array_filter(
            Restriction::cases(),
            static fn (Restriction $r): bool => in_array($r, $restrictions, true),
        )), $this->enabledTasks);
    }

    /**
     * Dieselben Rechte für eine Stadt mit diesen freigeschalteten Aufgaben: Bereiche, die eine Aufgabe
     * brauchen (Area::requiredTasks), gibt es nur, wenn eine davon freigeschaltet ist (Phase 2, P2.4).
     *
     * @param list<LocationTask> $tasks
     */
    public function withEnabledTasks(array $tasks): self
    {
        return new self($this->all, $this->areas, $this->actions, $this->visibility, $this->revision, $this->roleName, $this->restrictions, $tasks);
    }

    /**
     * Einschränkungen der Rolle in der Reihenfolge des Katalogs.
     *
     * @return list<Restriction>
     */
    public function restrictions(): array
    {
        return $this->restrictions;
    }

    public function restricted(Restriction $restriction): bool
    {
        return in_array($restriction, $this->restrictions, true);
    }

    /**
     * Wirksame Rechte über eine Claude-Verbindung (docs/MCP-KONZEPT.md, Abschnitt 2): die wirksamen
     * Rechte der Rolle (samt F3 und Datenfilter) ohne Benutzer, Einstellungen und Log-Mails; mit
     * Zugang „Lesen“ alle Bereiche höchstens Ansehen und keine Aktionen. Auch für die Rolle Admin
     * ausdrücklich aufgezählt (nie alle Rechte, auch keine künftigen).
     */
    public function forClaude(ClaudeAccess $access): self
    {
        $areas = [];
        foreach ($this->areas() as $area => $level) {
            if (in_array($area, self::NEVER_FOR_CLAUDE, true) || $access === ClaudeAccess::Off) {
                continue;
            }
            $areas[$area] = $access === ClaudeAccess::Write ? $level : AreaLevel::Read;
        }
        $actions = $access === ClaudeAccess::Write
            ? array_values(array_filter($this->actions(), static fn (Action $a): bool => $a !== Action::MailLog))
            : [];

        return new self(false, $areas, $actions, $this->visibility, $this->revision, $this->roleName, $this->restrictions, $this->enabledTasks);
    }

    /** Name der Rolle, aus der die Rechte stammen (Liste „Konto wechseln“); null ohne Rolle. */
    public function roleName(): ?string
    {
        return $this->roleName;
    }

    public function isAll(): bool
    {
        return $this->all;
    }

    public function level(Area $area): ?AreaLevel
    {
        $needed = $area->requiredTasks();
        if ($this->enabledTasks !== null && $needed !== [] && !array_any($needed, fn (LocationTask $task): bool => in_array($task, $this->enabledTasks ?? [], true))) {
            return null;
        }
        if ($this->all) {
            return $area->maxLevel();
        }
        $level = $this->areas[$area->value] ?? null;
        $excluded = self::excludedWithFilter($area);
        if ($level === null || $this->visibility->isAll() || $excluded === null || !$level->includes($excluded)) {
            return $level;
        }

        // F3: mit Datenfilter keine Verwaltung.
        return $excluded === AreaLevel::Write ? AreaLevel::Read : null;
    }

    /**
     * F3 (Plan Phase 1): kleinste Stufe eines Bereichs, die es mit Datenfilter nicht gibt; null =
     * keine Grenze. Gilt hier zur Laufzeit und beim Speichern einer Rolle (RoleRules).
     */
    public static function excludedWithFilter(Area $area): ?AreaLevel
    {
        return match ($area) {
            Area::Users => AreaLevel::Read,
            Area::Locations, Area::DeviceTypes => AreaLevel::Write,
            // Die Orte der Wartung liegen außerhalb jedes Filters (Sammelstelle, Werkstatt, Lager).
            Area::Maintenance => AreaLevel::Read,
            default => null,
        };
    }

    /** F3: Aktionen, die es mit Datenfilter nicht gibt. */
    public static function excludedActionWithFilter(Action $action): bool
    {
        return $action === Action::CsvImport;
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
        if (!in_array($action, $this->actions, true) || (self::excludedActionWithFilter($action) && $this->isFiltered())) {
            return false;
        }
        $required = $action->requires();

        return $required === null || $this->allows($required[0], $required[1]);
    }
}
