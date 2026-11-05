<?php

declare(strict_types=1);

namespace FirefightingEquipment\Permission;

/**
 * Ein einzelnes Recht: Bereich mit Mindeststufe oder eine Aktion.
 */
final readonly class Need
{
    private function __construct(
        public ?Area $area,
        public ?AreaLevel $level,
        public ?Action $action,
    ) {
    }

    public static function area(Area $area, AreaLevel $level): self
    {
        if ($level === AreaLevel::Write && $area->maxLevel() === AreaLevel::Read) {
            throw new \LogicException(sprintf('Bereich %s kennt nur Ansehen.', $area->value));
        }

        return new self($area, $level, null);
    }

    public static function action(Action $action): self
    {
        return new self(null, null, $action);
    }

    public function isMetBy(Permissions $permissions): bool
    {
        return $this->action !== null
            ? $permissions->can($this->action)
            : $permissions->allows($this->area ?? throw new \LogicException('Kein Bereich.'), $this->level ?? AreaLevel::Read);
    }

    /** Kurzform für Tests und Fehlersuche, z. B. "devices:write" oder "book.move". */
    public function key(): string
    {
        return $this->action?->value ?? sprintf('%s:%s', $this->area?->value, $this->level?->value);
    }
}
