<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Claude;

use FirefightingEquipment\Mcp\McpServer;
use FirefightingEquipment\Mcp\Tool\ToolApi;
use FirefightingEquipment\Mcp\Tool\ToolRegistry;
use FirefightingEquipment\Permission\Permissions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Rechte-Matrix der Werkzeuge (docs/PLAN-MCP.md, Abschnitt 8): Admin, Benutzer und eine Rolle mit
 * Datenfilter, je mit Zugang „Lesen“ und „Ändern“: tools/list zeigt genau die Werkzeuge, die die
 * Rolle ∩ Zugang erlaubt; jedes sichtbare Werkzeug läuft, nicht sichtbare sind wie unbekannt; der
 * Datenfilter gilt wie in der REST-API. Dazu Zugang „Aus“ und gesperrtes Konto (401).
 */
#[CoversClass(McpServer::class)]
#[CoversClass(ToolRegistry::class)]
#[CoversClass(ToolApi::class)]
#[CoversClass(Permissions::class)]
final class McpToolMatrixTest extends McpToolsTestCase
{
    private const array ALL_READ = [
        'get_overview', 'list_due', 'search_equipment', 'get_equipment', 'get_equipment_history',
        'list_locations', 'list_equipment_types', 'list_city_history', 'list_notices', 'get_notice',
    ];

    /** Standard-Rolle Benutzer: Suchen und Termine ansehen (kein Dashboard, kein Protokoll). */
    private const array USER_READ = [
        'get_overview', 'list_due', 'search_equipment', 'get_equipment', 'get_equipment_history',
        'list_locations', 'list_equipment_types',
    ];

    private const array ALL_WRITE = [
        'book_equipment', 'bulk_book_equipment', 'set_maintenance_date', 'bulk_set_maintenance_date', 'create_equipment',
        'update_equipment', 'assign_code', 'retire_equipment', 'reinstate_equipment', 'report_used', 'swap_equipment', 'preview_import', 'apply_import',
        'create_location', 'rename_location', 'lock_location', 'unlock_location', 'create_equipment_type',
        'rename_equipment_type', 'deactivate_equipment_type', 'activate_equipment_type', 'resolve_notice', 'reopen_notice',
    ];

    /**
     * Gerätewart mit Datenfilter: alles an der Ausrüstung, kein Import und keine Verwaltung von Standorten
     * und Typen (F3).
     */
    private const array FILTERED_WRITE = [
        'book_equipment', 'bulk_book_equipment', 'set_maintenance_date', 'bulk_set_maintenance_date', 'create_equipment',
        'update_equipment', 'assign_code', 'retire_equipment', 'reinstate_equipment', 'resolve_notice', 'reopen_notice',
    ];

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function matrix(): iterable
    {
        yield 'Admin, Lesen' => [self::ADMIN_EMAIL, 'read', self::ALL_READ];
        yield 'Benutzer, Lesen' => [self::EMAIL, 'read', self::USER_READ];
        yield 'Datenfilter, Lesen' => [self::FILTERED_EMAIL, 'read', self::ALL_READ];
        yield 'Admin, Ändern' => [self::ADMIN_EMAIL, 'read write', [...self::ALL_READ, ...self::ALL_WRITE]];
        yield 'Benutzer, Ändern' => [self::EMAIL, 'read write', [...self::USER_READ, 'book_equipment']];
        yield 'Datenfilter, Ändern' => [self::FILTERED_EMAIL, 'read write', [...self::ALL_READ, ...self::FILTERED_WRITE]];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('matrix')]
    public function testToolListShowsExactlyWhatTheRoleMayAndEveryShownToolWorks(string $email, string $scope, array $expected): void
    {
        $token = $this->tokenFor($email, $scope);

        self::assertSame($expected, $this->toolNames($token));
        foreach (array_intersect($expected, self::ALL_READ) as $name) {
            $result = $this->callTool($token, $name, $this->sampleArguments($name));
            if ($email === self::FILTERED_EMAIL && $name === 'get_notice') {
                // Der Hinweis gehört zu M3 im Lager, außerhalb des Datenfilters.
                self::assertSame('not_found', $result['structuredContent']['error']);
                continue;
            }
            self::assertFalse($result['isError'], $name . ': ' . json_encode($result, JSON_UNESCAPED_UNICODE));
            self::assertNotSame([], $result['content'], $name);
        }
        foreach (array_diff([...self::ALL_READ, ...self::ALL_WRITE], $expected) as $hidden) {
            $response = $this->rpc($token, 'tools/call', ['name' => $hidden, 'arguments' => $this->sampleArguments($hidden)]);
            self::assertSame(McpServer::INVALID_PARAMS, $response['error']['code'], $hidden);
        }
    }

    public function testDataFilterAppliesLikeInTheApi(): void
    {
        $token = $this->tokenFor(self::FILTERED_EMAIL, 'read');

        self::assertSame(['M1'], array_column($this->data($token, 'search_equipment')['equipment'], 'number'));
        self::assertSame(['HLF 20'], array_column($this->data($token, 'list_locations')['locations'], 'name'));
        self::assertSame(1, $this->data($token, 'get_overview')['readiness']['total']);
        self::assertSame([], $this->data($token, 'list_due')['overdue']['equipment'], 'M3 liegt im Lager');
        self::assertSame(['M1'], array_column(array_column($this->data($token, 'list_city_history')['events'], 'equipment'), 'number'));

        $hidden = $this->callTool($token, 'get_equipment', ['id' => $this->m2]);
        self::assertTrue($hidden['isError']);
        self::assertSame('not_found', $hidden['structuredContent']['error']);
        self::assertTrue($this->callTool($token, 'get_equipment', ['number' => 'M2'])['isError']);
        self::assertTrue($this->callTool($token, 'get_equipment_history', ['id' => $this->m2])['isError']);
    }

    public function testAdminSeesEverythingButNeverUsersOrSettings(): void
    {
        $token = $this->tokenFor(self::ADMIN_EMAIL);

        self::assertSame(['M1', 'M2', 'M3'], array_column($this->data($token, 'search_equipment')['equipment'], 'number'));
        foreach ($this->toolNames($token) as $name) {
            self::assertDoesNotMatchRegularExpression('/user|role|invitation|setting|appearance|logo|session|password|export/', $name);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function revoked(): iterable
    {
        yield 'Zugang aus' => ["UPDATE fe_users SET claude_access = 'off' WHERE email = '%s'"];
        yield 'Konto gesperrt' => ["UPDATE fe_users SET locked_at = UTC_TIMESTAMP() WHERE email = '%s'"];
    }

    #[DataProvider('revoked')]
    public function testWithoutAccessOrLockedNoToolIsReachable(string $statement): void
    {
        foreach ([self::ADMIN_EMAIL, self::EMAIL, self::FILTERED_EMAIL] as $email) {
            $token = $this->tokenFor($email);
            $this->pdo()->exec(sprintf($statement, $email));

            foreach (['tools/list', 'tools/call'] as $method) {
                $response = $this->mcp($token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => ['name' => 'search_equipment']]);
                self::assertSame(401, $response->statusCode(), $email . ' ' . $method);
            }
        }
    }

    public function testDowngradeToReadWorksFromTheNextCall(): void
    {
        $token = $this->tokenFor(self::EMAIL);
        $this->pdo()->exec("UPDATE fe_users SET claude_access = 'read' WHERE id = " . $this->user);

        self::assertSame(self::USER_READ, $this->toolNames($token));
        $response = $this->rpc($token, 'tools/call', ['name' => 'book_equipment', 'arguments' => ['id' => $this->m1, 'version' => 1, 'status' => 'maintenance']]);
        self::assertSame(McpServer::INVALID_PARAMS, $response['error']['code']);
    }

    /**
     * Gültige Argumente je Werkzeug (für die Matrix).
     *
     * @return array<string, mixed>
     */
    private function sampleArguments(string $name): array
    {
        return match ($name) {
            'get_equipment', 'get_equipment_history' => ['id' => $this->m1],
            'get_notice' => ['id' => $this->notice],
            default => [],
        };
    }
}
