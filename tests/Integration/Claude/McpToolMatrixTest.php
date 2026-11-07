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

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function matrix(): iterable
    {
        foreach (['read' => 'Lesen', 'read write' => 'Ändern'] as $scope => $label) {
            yield 'Admin, ' . $label => [self::ADMIN_EMAIL, $scope, self::ALL_READ];
            yield 'Benutzer, ' . $label => [self::EMAIL, $scope, self::USER_READ];
            yield 'Datenfilter, ' . $label => [self::FILTERED_EMAIL, $scope, self::ALL_READ];
        }
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('matrix')]
    public function testToolListShowsExactlyWhatTheRoleMayAndEveryShownToolWorks(string $email, string $scope, array $expected): void
    {
        $token = $this->tokenFor($email, $scope);

        self::assertSame($expected, $this->toolNames($token));
        foreach ($expected as $name) {
            $result = $this->callTool($token, $name, $this->sampleArguments($name));
            if ($email === self::FILTERED_EMAIL && $name === 'get_notice') {
                // Der Hinweis gehört zu M3 im Lager, außerhalb des Datenfilters.
                self::assertSame('not_found', $result['structuredContent']['error']);
                continue;
            }
            self::assertFalse($result['isError'], $name . ': ' . json_encode($result, JSON_UNESCAPED_UNICODE));
            self::assertNotSame([], $result['content'], $name);
        }
        foreach (array_diff(self::ALL_READ, $expected) as $hidden) {
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
