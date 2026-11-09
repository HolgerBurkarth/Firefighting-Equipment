<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Mcp;

use FirefightingEquipment\Claude\ClaudeAccess;
use FirefightingEquipment\Http\Routes;
use FirefightingEquipment\Mcp\Tool\ReadTools;
use FirefightingEquipment\Mcp\Tool\ToolApi;
use FirefightingEquipment\Mcp\Tool\ToolDefinition;
use FirefightingEquipment\Mcp\Tool\ToolRegistry;
use FirefightingEquipment\Mcp\Tool\WriteTools;
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
#[CoversClass(WriteTools::class)]
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
        'GET api/v1/loans',
        'GET api/v1/maintenance',
        'POST api/v1/devices',
        'POST api/v1/devices/{id}/state',
        'POST api/v1/devices/{id}/used',
        'POST api/v1/devices/swap',
        'POST api/v1/loans',
        'POST api/v1/devices/{id}/fields',
        'POST api/v1/devices/{id}/inspection-due',
        'POST api/v1/devices/{id}/retire',
        'POST api/v1/devices/{id}/reinstate',
        'POST api/v1/devices/bulk/state',
        'POST api/v1/devices/bulk/inspection-due',
        'POST api/v1/devices/import/preview',
        'POST api/v1/devices/import',
        'POST api/v1/locations',
        'POST api/v1/locations/{id}/rename',
        'POST api/v1/locations/{id}/lock',
        'POST api/v1/locations/{id}/unlock',
        'POST api/v1/device-types',
        'POST api/v1/device-types/{id}/rename',
        'POST api/v1/device-types/{id}/deactivate',
        'POST api/v1/device-types/{id}/activate',
        'POST api/v1/notices/{id}/resolve',
        'POST api/v1/notices/{id}/reopen',
    ];

    /** Werkzeuge mit destructiveHint: nur diese drei (Holger 08.11.2026, docs/MCP-KONZEPT.md). */
    private const array DESTRUCTIVE = ['retire_equipment', 'apply_import', 'lock_location'];

    /** Werkzeuge ohne idempotentHint (ein zweiter Aufruf legt neu an bzw. übernimmt neu). */
    private const array NOT_IDEMPOTENT = ['create_equipment', 'apply_import', 'create_location', 'create_equipment_type'];

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

    public function testWriteToolsHaveTheirHints(): void
    {
        foreach (WriteTools::all() as $tool) {
            self::assertSame($tool->name !== 'preview_import', !$tool->readOnly, $tool->name);
            self::assertSame(in_array($tool->name, self::DESTRUCTIVE, true), $tool->destructive, $tool->name);
            self::assertSame(!in_array($tool->name, self::NOT_IDEMPOTENT, true), $tool->idempotent, $tool->name);
            self::assertSame(['title', 'readOnlyHint', 'destructiveHint', 'idempotentHint', 'openWorldHint'], array_keys($tool->toArray()['annotations']));
        }
    }

    public function testWriteToolsNeedWriteAccess(): void
    {
        $router = Routes::tenant();
        $visible = array_map(static fn (ToolDefinition $t): string => $t->name, ToolRegistry::standard()->visible($router, Permissions::all()->forClaude(ClaudeAccess::Read)));

        self::assertSame(array_map(static fn (ToolDefinition $t): string => $t->name, ReadTools::all()), $visible);
    }

    public function testNoToolForUsersRolesSettingsOrTheOwnAccount(): void
    {
        foreach (ToolRegistry::standard()->all() as $tool) {
            self::assertDoesNotMatchRegularExpression('/user|role|invitation|setting|appearance|logo|session|password|notification|account|tenant|export/', $tool->name);
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
            array_map(static fn (ToolDefinition $t): string => $t->name, $registry->visible($router, Permissions::all()->forClaude(ClaudeAccess::Write))),
        );
    }

    public function testUndeclaredRoutesAreRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Ungültige Route');

        new ToolDefinition('broken_tool', 'X', 'X', ReadTools::schema([]), ['GET mcp'], static fn () => throw new \LogicException());
    }
}
