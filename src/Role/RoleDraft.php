<?php

declare(strict_types=1);

namespace FirefightingEquipment\Role;

use FirefightingEquipment\Permission\Action;
use FirefightingEquipment\Permission\Area;
use FirefightingEquipment\Permission\AreaLevel;
use FirefightingEquipment\Permission\Permissions;

/**
 * Gewünschter Stand einer Rolle (anlegen, speichern, kopieren, übernehmen), geprüft: Name 1–50 und
 * Beschreibung höchstens 200 Zeichen (getrimmt, ohne Steuerzeichen, leere Beschreibung = keine),
 * Bereiche und Aktionen nur aus dem Katalog (Stufe höchstens Area::maxLevel), Datenfilter null =
 * alle, sonst mindestens ein Eintrag. Ob Standorte und Ausrüstungstypen zur Stadt gehören, prüft
 * RoleRepository; F3 (keine Verwaltung mit Datenfilter) siehe filterConflicts().
 *
 * Aktionen ohne den Bereich, den sie brauchen, bleiben gespeichert (sie wirken erst mit dem Bereich,
 * Mockup „Rolle bearbeiten“).
 */
final readonly class RoleDraft
{
    public const int MAX_NAME_LENGTH = 50;
    public const int MAX_DESCRIPTION_LENGTH = 200;

    /**
     * @param array<string, AreaLevel> $areas         Bereich (Wert von Area) → Stufe, Reihenfolge des Katalogs
     * @param list<Action>             $actions       Reihenfolge des Katalogs
     * @param list<int>|null           $locationIds   null = alle Standorte; aufsteigend
     * @param list<int>|null           $deviceTypeIds null = alle Ausrüstungstypen; aufsteigend
     */
    private function __construct(
        public string $name,
        public ?string $description,
        public array $areas,
        public array $actions,
        public ?array $locationIds,
        public ?array $deviceTypeIds,
        /** Einschränkung „Bei Einmalgeräten nur benutzt melden“ (Restriction::SingleUseUsedOnly) */
        public bool $restrictSingleUse = false,
    ) {
    }

    /**
     * @param array<string, AreaLevel> $areas   Bereich (Wert von Area) → Stufe
     * @param list<Action>             $actions
     * @param list<int>|null           $locationIds
     * @param list<int>|null           $deviceTypeIds
     *
     * @throws InvalidRoleValueException
     */
    public static function of(
        string $name,
        ?string $description,
        array $areas,
        array $actions,
        ?array $locationIds,
        ?array $deviceTypeIds,
        bool $restrictSingleUse = false,
    ): self {
        $ordered = [];
        foreach (Area::cases() as $area) {
            $level = $areas[$area->value] ?? null;
            if ($level === null) {
                continue;
            }
            if (!$area->maxLevel()->includes($level)) {
                throw new InvalidRoleValueException(sprintf(
                    'Für „%s“ gibt es nur „%s“.',
                    $area->label(),
                    $area->maxLevel()->label(),
                ));
            }
            $ordered[$area->value] = $level;
        }
        if (count($ordered) !== count($areas)) {
            throw new InvalidRoleValueException('Unbekannter Bereich.');
        }
        if ($locationIds === []) {
            throw new InvalidRoleValueException('Bitte mindestens einen Standort wählen oder „Alle Standorte“.');
        }
        if ($deviceTypeIds === []) {
            throw new InvalidRoleValueException('Bitte mindestens einen Ausrüstungstyp wählen oder „Alle Ausrüstungstypen“.');
        }

        return new self(
            self::name($name),
            self::description($description),
            $ordered,
            array_values(array_filter(Action::cases(), static fn (Action $a): bool => in_array($a, $actions, true))),
            self::ids($locationIds),
            self::ids($deviceTypeIds),
            $restrictSingleUse,
        );
    }

    /**
     * Kopie einer Rolle unter neuem Namen, ohne $withFilters ohne Datenfilter (Rolle aus einer
     * anderen Stadt übernehmen, F10). Eine Kopie der Rolle Admin ist eine gewöhnliche Rolle mit allen
     * Rechten des heutigen Katalogs (künftige bekommt sie nicht). Werte, die der Katalog nicht kennt,
     * fallen weg.
     *
     * @throws InvalidRoleValueException
     */
    public static function copyOf(Role $role, string $name, bool $withFilters = true): self
    {
        $permissions = Permissions::fromRole($role);
        $areas = [];
        foreach (Area::cases() as $area) {
            $level = $permissions->isAll() ? $area->maxLevel() : AreaLevel::tryFrom($role->areas[$area->value] ?? '');
            if ($level !== null) {
                $areas[$area->value] = $level;
            }
        }
        $actions = $permissions->isAll()
            ? Action::cases()
            : array_values(array_filter(array_map(Action::tryFrom(...), $role->actions)));

        return self::of(
            $name,
            // Die Beschreibung der Rolle Admin („nicht änderbar“) passt nicht zur Kopie.
            $permissions->isAll() ? null : $role->description,
            $areas,
            $actions,
            $withFilters && $role->filterLocations ? $role->locationIds : null,
            $withFilters && $role->filterDeviceTypes ? $role->deviceTypeIds : null,
            !$permissions->isAll() && $role->restrictSingleUse,
        );
    }

    public function isFiltered(): bool
    {
        return $this->locationIds !== null || $this->deviceTypeIds !== null;
    }

    /**
     * F3: Rechte, die es mit Datenfilter nicht gibt (leer ohne Datenfilter).
     *
     * @return array{areas: list<string>, actions: list<string>, labels: list<string>}
     */
    public function filterConflicts(): array
    {
        $conflicts = ['areas' => [], 'actions' => [], 'labels' => []];
        if (!$this->isFiltered()) {
            return $conflicts;
        }
        foreach ($this->areas as $key => $level) {
            $area = Area::from($key);
            $excluded = Permissions::excludedWithFilter($area);
            if ($excluded !== null && $level->includes($excluded)) {
                $conflicts['areas'][] = $key;
                $conflicts['labels'][] = sprintf('„%s: %s“', $area->label(), $level->label());
            }
        }
        foreach ($this->actions as $action) {
            if (Permissions::excludedActionWithFilter($action)) {
                $conflicts['actions'][] = $action->value;
                $conflicts['labels'][] = sprintf('„%s“', $action->label());
            }
        }

        return $conflicts;
    }

    /** Gleicher Stand wie die gespeicherte Rolle (Texte binär verglichen)? */
    public function sameAs(Role $role): bool
    {
        $areas = array_map(static fn (AreaLevel $level): string => $level->value, $this->areas);
        $storedAreas = $role->areas;
        ksort($areas);
        ksort($storedAreas);
        $actions = array_map(static fn (Action $action): string => $action->value, $this->actions);
        $storedActions = $role->actions;
        sort($actions);
        sort($storedActions);

        return $this->name === $role->name
            && $this->description === $role->description
            && $areas === $storedAreas
            && $actions === $storedActions
            && $this->locationIds === ($role->filterLocations ? $role->locationIds : null)
            && $this->deviceTypeIds === ($role->filterDeviceTypes ? $role->deviceTypeIds : null)
            && $this->restrictSingleUse === $role->restrictSingleUse;
    }

    /**
     * @throws InvalidRoleValueException
     */
    public static function name(string $value): string
    {
        return self::text($value, self::MAX_NAME_LENGTH, 'Der Name der Rolle')
            ?? throw new InvalidRoleValueException('Der Name der Rolle darf nicht leer sein.');
    }

    /**
     * @throws InvalidRoleValueException
     */
    private static function description(?string $value): ?string
    {
        return $value === null ? null : self::text($value, self::MAX_DESCRIPTION_LENGTH, 'Die Kurzbeschreibung');
    }

    /**
     * @return string|null getrimmt; null, wenn leer
     *
     * @throws InvalidRoleValueException
     */
    private static function text(string $value, int $maxLength, string $subject): ?string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidRoleValueException(sprintf('%s ist kein gültiger UTF-8-Text.', $subject));
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $length = mb_strlen($value);
        if ($length > $maxLength) {
            throw new InvalidRoleValueException(sprintf(
                '%s darf höchstens %d Zeichen lang sein (angegeben: %d).',
                $subject,
                $maxLength,
                $length,
            ));
        }
        if (preg_match('/\p{Cc}/u', $value) === 1) {
            throw new InvalidRoleValueException(sprintf('%s darf keine Steuerzeichen (z. B. Zeilenumbrüche) enthalten.', $subject));
        }

        return $value;
    }

    /**
     * @param list<int>|null $ids
     *
     * @return list<int>|null
     */
    private static function ids(?array $ids): ?array
    {
        if ($ids === null) {
            return null;
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }
}
