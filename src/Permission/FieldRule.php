<?php

declare(strict_types=1);

namespace FirefightingEquipment\Permission;

/**
 * Feldregel einer Route: Enthält der JSON-Body das Feld (auch mit null), braucht die Anfrage
 * zusätzlich die Aktion. Mit $list gilt die Regel für jeden Eintrag der Liste in diesem Feld
 * (Sammelaktionen: "devices": [{…}, …]).
 */
final readonly class FieldRule
{
    public function __construct(
        public string $field,
        public Action $action,
        public ?string $list = null,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public function appliesTo(array $body): bool
    {
        if ($this->list === null) {
            return array_key_exists($this->field, $body);
        }
        $entries = $body[$this->list] ?? null;
        if (!is_array($entries)) {
            return false;
        }
        foreach ($entries as $entry) {
            if (is_array($entry) && array_key_exists($this->field, $entry)) {
                return true;
            }
        }

        return false;
    }

    public function key(): string
    {
        return ($this->list === null ? '' : $this->list . '[].') . $this->field . ' → ' . $this->action->value;
    }
}
