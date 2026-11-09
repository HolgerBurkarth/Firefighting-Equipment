<?php

declare(strict_types=1);

namespace FirefightingEquipment\Mcp\Tool;

use FirefightingEquipment\Http\Router;
use FirefightingEquipment\Permission\Permissions;

/**
 * Alle Werkzeuge des Claude-Zugangs. Sichtbar (tools/list) und aufrufbar ist ein Werkzeug nur, wenn
 * das Erfordernis seiner ersten Route mit den wirksamen Rechten erfüllt ist (Rolle ∩ Zugang,
 * Permissions::forClaude); jede Route prüft ToolApi beim Aufruf zusätzlich samt Feldregeln.
 */
final readonly class ToolRegistry
{
    /** @var array<string, ToolDefinition> */
    private array $tools;

    /**
     * @param list<ToolDefinition> $tools
     */
    public function __construct(array $tools)
    {
        $byName = [];
        foreach ($tools as $tool) {
            if (isset($byName[$tool->name])) {
                throw new \LogicException(sprintf('Werkzeug doppelt: %s', $tool->name));
            }
            $byName[$tool->name] = $tool;
        }
        $this->tools = $byName;
    }

    public static function standard(): self
    {
        return new self([...ReadTools::all(), ...WriteTools::all()]);
    }

    /**
     * @return list<ToolDefinition>
     */
    public function all(): array
    {
        return array_values($this->tools);
    }

    /**
     * Werkzeuge, die diese Rechte wirksam erlauben, in fester Reihenfolge.
     *
     * @return list<ToolDefinition>
     */
    public function visible(Router $router, Permissions $permissions): array
    {
        return array_values(array_filter(
            $this->tools,
            static fn (ToolDefinition $tool): bool => self::isAvailable($tool, $router, $permissions),
        ));
    }

    /** null: unbekannt oder für diese Rechte nicht sichtbar (wie unbekannt). */
    public function find(string $name, Router $router, Permissions $permissions): ?ToolDefinition
    {
        $tool = $this->tools[$name] ?? null;

        return $tool !== null && self::isAvailable($tool, $router, $permissions) ? $tool : null;
    }

    /** Kennt der Server das Werkzeug überhaupt (unabhängig von Rechten und Aufgaben)? */
    public function exists(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    private static function isAvailable(ToolDefinition $tool, Router $router, Permissions $permissions): bool
    {
        [$method, $pattern] = explode(' ', $tool->primaryRoute(), 2);

        return ToolApi::requirementOf($router, $method, $pattern)->allows($permissions, [])
            && ($tool->requires?->allows($permissions, []) ?? true);
    }
}
