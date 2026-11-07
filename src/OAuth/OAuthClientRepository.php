<?php

declare(strict_types=1);

namespace FirefightingEquipment\OAuth;

use FirefightingEquipment\Auth\Base64Url;

/**
 * Registrierungen (fe_oauth_clients). Löschen ist ein echtes DELETE; Codes einer Registrierung
 * fallen per CASCADE mit weg.
 */
final readonly class OAuthClientRepository
{
    /** Nie genutzte Registrierungen werden so lange behalten. */
    public const int UNUSED_RETENTION_HOURS = 24;
    /** Genutzte Registrierungen ohne Verbindung werden so lange behalten (seit der letzten Nutzung). */
    public const int USED_RETENTION_DAYS = 90;
    /** Höchstens so viele nie genutzte Registrierungen je Stadt. */
    public const int MAX_UNUSED_PER_TENANT = 200;

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @param list<string> $redirectUris erlaubt nach RedirectUriRules (prüft der Aufrufer)
     */
    public function register(int $tenantId, ?string $clientName, array $redirectUris): OAuthClient
    {
        $clientId = Base64Url::encode(random_bytes(32));
        $this->pdo->prepare(
            'INSERT INTO fe_oauth_clients (tenant_id, client_id, client_name, redirect_uris, created_at)'
            . ' VALUES (?, ?, ?, ?, UTC_TIMESTAMP())',
        )->execute([$tenantId, $clientId, $clientName, json_encode($redirectUris, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);

        return new OAuthClient((int) $this->pdo->lastInsertId(), $tenantId, $clientId, $clientName, $redirectUris);
    }

    public function find(int $tenantId, string $clientId): ?OAuthClient
    {
        $stmt = $this->pdo->prepare('SELECT id, tenant_id, client_id, client_name, redirect_uris FROM fe_oauth_clients WHERE tenant_id = ? AND client_id = ?');
        $stmt->execute([$tenantId, $clientId]);
        $row = $stmt->fetch();

        return $row === false ? null : self::fromRow($row);
    }

    public function markUsed(int $id): void
    {
        $this->pdo->prepare('UPDATE fe_oauth_clients SET last_used_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$id]);
    }

    public function countUnused(int $tenantId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM fe_oauth_clients WHERE tenant_id = ? AND last_used_at IS NULL');
        $stmt->execute([$tenantId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Aufräumen ohne Cronjob (bei jeder Registrierung der Stadt): nie genutzte älter als 24 Stunden,
     * genutzte ohne Verbindung seit 90 Tagen.
     *
     * @return int Anzahl gelöschter Registrierungen
     */
    public function deleteStale(int $tenantId): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM fe_oauth_clients WHERE tenant_id = ? AND ('
            . ' (last_used_at IS NULL AND created_at < UTC_TIMESTAMP() - INTERVAL ? HOUR)'
            . ' OR (last_used_at < UTC_TIMESTAMP() - INTERVAL ? DAY'
            . '     AND NOT EXISTS (SELECT 1 FROM fe_claude_connections c WHERE c.oauth_client_id = fe_oauth_clients.id)))',
        );
        $stmt->execute([$tenantId, self::UNUSED_RETENTION_HOURS, self::USED_RETENTION_DAYS]);

        return $stmt->rowCount();
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function fromRow(array $row): OAuthClient
    {
        $uris = json_decode((string) $row['redirect_uris'], true, 4, JSON_THROW_ON_ERROR);
        if (!is_array($uris) || !array_is_list($uris) || !array_all($uris, static fn (mixed $v): bool => is_string($v))) {
            throw new \UnexpectedValueException('Ungültige redirect_uris.');
        }

        /** @var list<string> $uris */
        return new OAuthClient(
            (int) $row['id'],
            (int) $row['tenant_id'],
            (string) $row['client_id'],
            $row['client_name'] === null ? null : (string) $row['client_name'],
            $uris,
        );
    }
}
