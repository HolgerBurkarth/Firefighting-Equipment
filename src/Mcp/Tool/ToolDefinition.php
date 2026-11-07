<?php

declare(strict_types=1);

namespace FirefightingEquipment\Mcp\Tool;

use FirefightingEquipment\Permission\Requirement;

/**
 * Ein Werkzeug des Claude-Zugangs (docs/MCP-KONZEPT.md, Abschnitt 3): Name (englisch), Titel und
 * Beschreibung (deutsch), inputSchema, Annotationen, die REST-Routen, die es aufrufen darf (die
 * erste bestimmt, ob es in tools/list erscheint), und die Ausführung als Adapter auf diese Routen
 * (ToolApi). Eine zweite Geschäftslogik gibt es nicht.
 */
final readonly class ToolDefinition
{
    /**
     * @param array<string, mixed>                          $inputSchema JSON Schema (siehe ToolArguments)
     * @param non-empty-list<string>                        $routes      z. B. "GET api/v1/devices/{id}"
     * @param \Closure(ToolArguments, ToolApi): ToolResult $run
     */
    public function __construct(
        public string $name,
        public string $title,
        public string $description,
        public array $inputSchema,
        public array $routes,
        public \Closure $run,
        public bool $readOnly = true,
        public bool $destructive = false,
        public bool $idempotent = true,
        /** zusätzlich nötig, damit das Werkzeug erscheint (z. B. device.edit bei einer Route, die auch device.code zulässt) */
        public ?Requirement $requires = null,
    ) {
        if (preg_match('/^[a-z][a-z_]{2,63}\z/', $name) !== 1) {
            throw new \LogicException(sprintf('Ungültiger Name eines Werkzeugs: %s', $name));
        }
        foreach ($routes as $route) {
            if (preg_match('#^(GET|POST|DELETE) api/v1/[a-z0-9_/{}.-]+\z#', $route) !== 1) {
                throw new \LogicException(sprintf('Ungültige Route im Werkzeug %s: %s', $name, $route));
            }
        }
    }

    /** Die Route, deren Erfordernis über die Sichtbarkeit in tools/list entscheidet. */
    public function primaryRoute(): string
    {
        return $this->routes[0];
    }

    /**
     * Eintrag in tools/list.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $schema = $this->inputSchema;
        if (($schema['properties'] ?? []) === []) {
            $schema['properties'] = new \stdClass();
        }

        return [
            'name' => $this->name,
            'title' => $this->title,
            'description' => $this->description,
            'inputSchema' => $schema,
            'annotations' => [
                'title' => $this->title,
                'readOnlyHint' => $this->readOnly,
                'destructiveHint' => $this->destructive,
                'idempotentHint' => $this->idempotent,
                'openWorldHint' => false,
            ],
        ];
    }
}
