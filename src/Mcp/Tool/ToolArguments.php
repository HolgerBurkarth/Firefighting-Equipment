<?php

declare(strict_types=1);

namespace FirefightingEquipment\Mcp\Tool;

/**
 * Argumente eines Werkzeugaufrufs (tools/call), geprüft gegen das inputSchema des Werkzeugs. Das
 * Schema nutzt nur einen kleinen Teil von JSON Schema: Objekt mit properties, required und
 * additionalProperties false; Werte string (minLength, maxLength, enum), integer (minimum, maximum),
 * boolean und array (items wie oben, minItems, maxItems, uniqueItems). Fehler sind Fehlerergebnisse
 * des Werkzeugs (Claude kann den Aufruf berichtigen), keine Protokollfehler.
 */
final readonly class ToolArguments
{
    /**
     * @param array<string, mixed> $values
     */
    private function __construct(private array $values)
    {
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @throws ToolFailure invalid_arguments
     */
    public static function validate(mixed $arguments, array $schema): self
    {
        if ($arguments === null || $arguments === []) {
            $arguments = [];
        } elseif (!is_array($arguments) || array_is_list($arguments)) {
            throw ToolFailure::invalidArguments('"arguments" muss ein Objekt sein.');
        }
        /** @var array<string, array<string, mixed>> $properties */
        $properties = $schema['properties'] ?? [];
        foreach (array_keys($arguments) as $name) {
            if (!isset($properties[$name])) {
                throw ToolFailure::invalidArguments(sprintf('Unbekanntes Argument "%s". Erlaubt: %s.', $name, implode(', ', array_keys($properties)) ?: 'keine'));
            }
        }
        /** @var list<string> $required */
        $required = $schema['required'] ?? [];
        foreach ($required as $name) {
            if (!array_key_exists($name, $arguments) || $arguments[$name] === null) {
                throw ToolFailure::invalidArguments(sprintf('Das Argument "%s" fehlt.', $name));
            }
        }
        $values = [];
        foreach ($arguments as $name => $value) {
            if ($value === null) {
                // null = wie nicht angegeben (Claude sendet optionale Argumente manchmal so).
                continue;
            }
            self::check((string) $name, $value, $properties[$name]);
            $values[(string) $name] = $value;
        }

        return new self($values);
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->values);
    }

    public function string(string $name): ?string
    {
        $value = $this->values[$name] ?? null;

        return is_string($value) ? $value : null;
    }

    public function int(string $name): ?int
    {
        $value = $this->values[$name] ?? null;

        return is_int($value) ? $value : null;
    }

    public function bool(string $name, bool $default = false): bool
    {
        $value = $this->values[$name] ?? null;

        return is_bool($value) ? $value : $default;
    }

    /**
     * @return list<mixed>|null
     */
    public function list(string $name): ?array
    {
        $value = $this->values[$name] ?? null;

        return is_array($value) ? array_values($value) : null;
    }

    /**
     * Rohwert (geprüft), z. B. für Objekte in Listen.
     */
    public function raw(string $name): mixed
    {
        return $this->values[$name] ?? null;
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @throws ToolFailure
     */
    private static function check(string $name, mixed $value, array $schema): void
    {
        $type = $schema['type'] ?? null;
        $ok = match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'boolean' => is_bool($value),
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value) && ($value === [] || !array_is_list($value)),
            default => throw new \LogicException(sprintf('Typ %s im Schema nicht unterstützt.', var_export($type, true))),
        };
        if (!$ok) {
            throw ToolFailure::invalidArguments(sprintf('"%s" muss %s sein.', $name, match ($type) {
                'string' => 'ein Text',
                'integer' => 'eine ganze Zahl',
                'boolean' => 'true oder false',
                'array' => 'eine Liste',
                default => 'ein Objekt',
            }));
        }
        if (is_string($value)) {
            $length = mb_strlen($value);
            if (isset($schema['minLength']) && $length < $schema['minLength']) {
                throw ToolFailure::invalidArguments(sprintf('"%s" ist zu kurz.', $name));
            }
            if (isset($schema['maxLength']) && $length > $schema['maxLength']) {
                throw ToolFailure::invalidArguments(sprintf('"%s" ist zu lang (höchstens %d Zeichen).', $name, $schema['maxLength']));
            }
        }
        if (isset($schema['enum']) && is_array($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            throw ToolFailure::invalidArguments(sprintf('"%s" muss einer dieser Werte sein: %s.', $name, implode(', ', array_map(strval(...), $schema['enum']))));
        }
        if (is_int($value) && ((isset($schema['minimum']) && $value < $schema['minimum']) || (isset($schema['maximum']) && $value > $schema['maximum']))) {
            throw ToolFailure::invalidArguments(sprintf('"%s" muss von %d bis %d sein.', $name, $schema['minimum'] ?? PHP_INT_MIN, $schema['maximum'] ?? PHP_INT_MAX));
        }
        if (is_array($value) && $type === 'array') {
            $count = count($value);
            if ((isset($schema['minItems']) && $count < $schema['minItems']) || (isset($schema['maxItems']) && $count > $schema['maxItems'])) {
                throw ToolFailure::invalidArguments(sprintf('"%s" muss %d bis %d Einträge haben.', $name, $schema['minItems'] ?? 0, $schema['maxItems'] ?? PHP_INT_MAX));
            }
            if (($schema['uniqueItems'] ?? false) === true && count(array_unique(array_map(serialize(...), $value))) !== $count) {
                throw ToolFailure::invalidArguments(sprintf('"%s" enthält einen Eintrag doppelt.', $name));
            }
            /** @var array<string, mixed> $items */
            $items = $schema['items'] ?? throw new \LogicException('Liste ohne items im Schema.');
            foreach ($value as $i => $item) {
                self::check(sprintf('%s[%d]', $name, $i), $item, $items);
            }
        }
        if (is_array($value) && $type === 'object') {
            /** @var array<string, array<string, mixed>> $properties */
            $properties = $schema['properties'] ?? [];
            foreach ($value as $key => $item) {
                $key = (string) $key;
                if (!isset($properties[$key])) {
                    throw ToolFailure::invalidArguments(sprintf('Unbekanntes Feld "%s" in "%s".', $key, $name));
                }
                if ($item !== null) {
                    self::check($name . '.' . $key, $item, $properties[$key]);
                }
            }
            /** @var list<string> $required */
            $required = $schema['required'] ?? [];
            foreach ($required as $key) {
                if (($value[$key] ?? null) === null) {
                    throw ToolFailure::invalidArguments(sprintf('Das Feld "%s" fehlt in "%s".', $key, $name));
                }
            }
        }
    }
}
