<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Mcp;

use FirefightingEquipment\Claude\ClaudeAccess;
use FirefightingEquipment\Http\Routes;
use FirefightingEquipment\Mcp\Tool\ReadTools;
use FirefightingEquipment\Mcp\Tool\ToolApi;
use FirefightingEquipment\Mcp\Tool\ToolDefinition;
use FirefightingEquipment\Mcp\Tool\ToolRegistry;
use FirefightingEquipment\Permission\Permissions;
use FirefightingEquipment\Permission\Requirement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Jedes Werkzeug nutzt nur erlaubte REST-Routen (docs/PLAN-MCP.md, Abschnitt 6, Risiko
 * „Rechteausweitung“): jede genannte Route steht in der Liste des Konzepts, hat ein Requirement im
 * Router der Stadt, und keine erreicht Benutzer, Einladungscodes, Rollen, Einstellungen, Anmeldung,
 * Sitzungen oder Exporte. Kein Werkzeug ist ohne Rechte sichtbar.
 */
#[CoversClass(ToolRegistry::class)]
#[CoversClass(ToolDefinition::class)]
#[CoversClass(ToolApi::class)]
#[CoversClass(ReadTools::class)]
final class ToolRoutesTest extends TestCase
{
    /** Routen, die Werkzeuge aufrufen dürfen (docs/MCP-KONZEPT.md, Abschnitt 3). */
    public const array ALLOWED = [
        'GET api/v1/device-summary',
        'GET api/v1/inspection-due',
        'GET api/v1/devices',
        'GET api/v1/devices/{id}',
        'GET api/v1/devices/{id}/events',
        'GET api/v1/locations',
        'GET api/v1/device-types',
        'GET api/v1/device-events',
        'GET api/v1/notices',
        'GET api/v1/notices/{id}',
    ];

    private const string FORBIDDEN = '#(users|invitation-codes|roles|tenant/|tenants|auth/|export|client-config|oauth|mcp|superadmins|permission-catalog)#';

    public function testEveryRouteOfEveryToolIsAllowedAndHasARequirement(): void
    {
        $router = Routes::tenant();
        foreach (ToolRegistry::standard()->all() as $tool) {
            foreach ($tool->routes as $route) {
                self::assertContains($route, self::ALLOWED, $tool->name);
                self::assertDoesNotMatchRegularExpression(self::FORBIDDEN, $route, $tool->name);
                [$method, $pattern] = explode(' ', $route, 2);
                self::assertInstanceOf(Requirement::class, ToolApi::requirementOf($router, $method, $pattern));
            }
        }
    }

    public function testReadToolsOnlyRead(): void
    {
        foreach (ReadTools::all() as $tool) {
            self::assertTrue($tool->readOnly, $tool->name);
            foreach ($tool->routes as $route) {
                self::assertStringStartsWith('GET ', $route, $tool->name);
            }
        }
    }

    public function testNothingIsVisibleWithoutRights(): void
    {
        $router = Routes::tenant();
        $registry = ToolRegistry::standard();

        self::assertSame([], $registry->visible($router, Permissions::none()));
        self::assertSame([], $registry->visible($router, Permissions::all()->forClaude(ClaudeAccess::Off)));
        self::assertSame(
            array_map(static fn (ToolDefinition $t): string => $t->name, $registry->all()),
            array_map(static fn (ToolDefinition $t): string => $t->name, $registry->visible($router, Permissions::all()->forClaude(ClaudeAccess::Read))),
        );
    }

    public function testUndeclaredRoutesAreRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Ungültige Route');

        new ToolDefinition('broken_tool', 'X', 'X', ReadTools::schema([]), ['GET mcp'], static fn () => throw new \LogicException());
    }
}
