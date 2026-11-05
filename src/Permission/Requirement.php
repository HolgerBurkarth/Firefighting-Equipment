<?php

declare(strict_types=1);

namespace FirefightingEquipment\Permission;

/**
 * Was eine Route unter einer Stadt verlangt (statt Access::Admin bzw. Access::Authenticated):
 * eine von mehreren Alternativen, jede eine Liste von Rechten, die alle erfüllt sein müssen, dazu
 * Feldregeln für einzelne Felder des Bodys. Geprüft zentral in Application, nach der Sitzung und
 * vor dem Handler (abgelehnt: 403 forbidden).
 */
final readonly class Requirement
{
    /**
     * @param non-empty-list<non-empty-list<Need>> $alternatives
     * @param list<FieldRule>                      $fieldRules
     */
    private function __construct(
        public array $alternatives,
        public array $fieldRules = [],
    ) {
    }

    public static function area(Area $area, AreaLevel $level): self
    {
        return new self([[Need::area($area, $level)]]);
    }

    public static function action(Action $action): self
    {
        return new self([[Need::action($action)]]);
    }

    /** Eine der Anforderungen genügt. */
    public static function anyOf(self $first, self ...$others): self
    {
        $alternatives = $first->alternatives;
        foreach ($others as $other) {
            array_push($alternatives, ...$other->alternatives);
        }

        return new self($alternatives, self::mergeRules($first, ...$others));
    }

    /** Alle Anforderungen zugleich. */
    public static function allOf(self $first, self ...$others): self
    {
        $alternatives = $first->alternatives;
        foreach ($others as $other) {
            $combined = [];
            foreach ($alternatives as $left) {
                foreach ($other->alternatives as $right) {
                    $combined[] = [...$left, ...$right];
                }
            }
            $alternatives = $combined;
        }

        return new self($alternatives, self::mergeRules($first, ...$others));
    }

    /**
     * Enthält der Body $field (bzw. ein Eintrag der Liste $list das Feld), braucht es $action.
     */
    public function withFieldRule(string $field, Action $action, ?string $list = null): self
    {
        return new self($this->alternatives, [...$this->fieldRules, new FieldRule($field, $action, $list)]);
    }

    /**
     * @param array<string, mixed> $body JSON-Body (bei GET leer)
     */
    public function allows(Permissions $permissions, array $body): bool
    {
        $met = false;
        foreach ($this->alternatives as $needs) {
            if (array_all($needs, static fn (Need $need): bool => $need->isMetBy($permissions))) {
                $met = true;
                break;
            }
        }

        return $met && array_all(
            $this->fieldRules,
            static fn (FieldRule $rule): bool => !$rule->appliesTo($body) || $permissions->can($rule->action),
        );
    }

    /**
     * Lesbare Kurzform, z. B. "search:read | devices:read" oder "devices.bulk & due:write; Felder: …".
     */
    public function describe(): string
    {
        $text = implode(' | ', array_map(
            static fn (array $needs): string => implode(' & ', array_map(static fn (Need $n): string => $n->key(), $needs)),
            $this->alternatives,
        ));
        if ($this->fieldRules !== []) {
            $text .= '; Felder: ' . implode(', ', array_map(static fn (FieldRule $r): string => $r->key(), $this->fieldRules));
        }

        return $text;
    }

    /**
     * @return list<FieldRule>
     */
    private static function mergeRules(self ...$requirements): array
    {
        $rules = [];
        foreach ($requirements as $requirement) {
            array_push($rules, ...$requirement->fieldRules);
        }

        return $rules;
    }
}
