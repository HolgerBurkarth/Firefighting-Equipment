<?php

declare(strict_types=1);

namespace FirefightingEquipment\Claude;

use FirefightingEquipment\User\UserRole;

/**
 * Claude-Verbindungen (fe_claude_connections) und offene Codes der Anmeldung (fe_oauth_codes).
 * Tokens nur als SHA-256. Löschen ist ein echtes DELETE (keine Historie, wie die Sitzungen). Alle
 * Zeitvergleiche rechnet die Datenbank mit UTC_TIMESTAMP().
 */
final readonly class ClaudeConnectionRepository
{
    /** Höchstens so viele Verbindungen je Konto; die älteste fällt weg (Plan, O9). */
    public const int MAX_PER_USER = 5;
    /** Das vorige Refresh-Token gilt nach einer Rotation noch so lange einmal (Plan, O4). */
    public const int REFRESH_GRACE_SECONDS = 60;
    /** Gültigkeit eines Zugriffstokens (nie länger als die Verbindung). */
    public const int ACCESS_TOKEN_SECONDS = 3600;

    private const string SELECT = 'SELECT c.id, c.tenant_id, c.user_id, c.oauth_client_id, c.access, c.account_kind,'
        . ' c.created_at, c.last_seen_at,'
        . ' TIMESTAMPDIFF(SECOND, c.created_at, UTC_TIMESTAMP()) AS age_seconds,'
        . ' TIMESTAMPDIFF(SECOND, c.last_seen_at, UTC_TIMESTAMP()) AS idle_seconds,'
        . ' c.expires_at <= UTC_TIMESTAMP() AS expired,'
        . ' TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), c.expires_at) AS remaining_seconds,'
        . ' (c.access_expires_at IS NOT NULL AND c.access_expires_at > UTC_TIMESTAMP()) AS access_valid,'
        . ' (c.rotated_at IS NOT NULL AND c.rotated_at >= UTC_TIMESTAMP() - INTERVAL ' . self::REFRESH_GRACE_SECONDS . ' SECOND) AS in_grace,'
        . " cl.client_name, JSON_UNQUOTE(JSON_EXTRACT(cl.redirect_uris, '$[0]')) AS redirect_uri"
        . ' FROM fe_claude_connections c JOIN fe_oauth_clients cl ON cl.id = c.oauth_client_id AND cl.tenant_id = c.tenant_id';

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Neue Verbindung nach dem Einlösen eines Codes (in der Transaktion des Aufrufers). Danach fällt
     * die älteste weg, wenn das Konto mehr als MAX_PER_USER hat.
     *
     * @param int $maxSeconds Höchstdauer der Verbindung (Sitzungsklasse der Rolle, höchstens 90 Tage)
     *
     * @return int ID der Verbindung
     */
    public function create(
        int $tenantId,
        int $userId,
        int $oauthClientId,
        string $codeHash,
        ClaudeAccess $access,
        UserRole $accountKind,
        string $refreshHash,
        string $accessHash,
        int $maxSeconds,
    ): int {
        if ($access === ClaudeAccess::Off) {
            throw new \InvalidArgumentException('Eine Verbindung braucht Zugang.');
        }
        $this->pdo->prepare(
            'INSERT INTO fe_claude_connections (tenant_id, user_id, oauth_client_id, code_hash, access, account_kind,'
            . ' refresh_hash, access_hash, access_expires_at, created_at, last_seen_at, expires_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP() + INTERVAL LEAST(?, ?) SECOND,'
            . ' UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL ? SECOND)',
        )->execute([
            $tenantId, $userId, $oauthClientId, $codeHash, $access->value, $accountKind->value,
            $refreshHash, $accessHash, self::ACCESS_TOKEN_SECONDS, $maxSeconds, $maxSeconds,
        ]);
        $id = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'DELETE FROM fe_claude_connections WHERE user_id = ? AND id NOT IN (SELECT id FROM ('
            . ' SELECT id FROM fe_claude_connections WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . self::MAX_PER_USER
            . ') AS newest)',
        )->execute([$userId, $userId]);

        return $id;
    }

    /**
     * @param bool $lock FOR UPDATE (in einer Transaktion des Aufrufers)
     */
    public function findByRefreshHash(int $tenantId, string $hash, bool $lock = false): ?ClaudeConnection
    {
        return $this->findBy('c.refresh_hash', $tenantId, $hash, $lock);
    }

    public function findByPreviousRefreshHash(int $tenantId, string $hash, bool $lock = false): ?ClaudeConnection
    {
        return $this->findBy('c.previous_refresh_hash', $tenantId, $hash, $lock);
    }

    public function findByAccessHash(int $tenantId, string $hash): ?ClaudeConnection
    {
        return $this->findBy('c.access_hash', $tenantId, $hash, false);
    }

    public function findByCodeHash(int $tenantId, string $hash, bool $lock = false): ?ClaudeConnection
    {
        return $this->findBy('c.code_hash', $tenantId, $hash, $lock);
    }

    /**
     * Neues Paar aus Refresh- und Zugriffstoken (Rotation); last_seen_at = jetzt.
     *
     * @param string|null $previousHash bisheriges Refresh-Token (gilt danach noch kurz, O4); null =
     *                                  keines mehr (nach einer Wiederverwendung)
     */
    public function rotate(int $id, string $refreshHash, ?string $previousHash, string $accessHash): void
    {
        $this->pdo->prepare(
            'UPDATE fe_claude_connections SET refresh_hash = ?, previous_refresh_hash = ?,'
            . ' rotated_at = IF(? IS NULL, NULL, UTC_TIMESTAMP()), access_hash = ?,'
            . ' access_expires_at = LEAST(UTC_TIMESTAMP() + INTERVAL ? SECOND, expires_at), last_seen_at = UTC_TIMESTAMP()'
            . ' WHERE id = ?',
        )->execute([$refreshHash, $previousHash, $previousHash, $accessHash, self::ACCESS_TOKEN_SECONDS, $id]);
    }

    public function touch(int $id): void
    {
        $this->pdo->prepare('UPDATE fe_claude_connections SET last_seen_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$id]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM fe_claude_connections WHERE id = ?')->execute([$id]);
    }

    /**
     * Eigene Verbindung trennen (Profil); fremde und unbekannte sind nicht unterscheidbar.
     */
    public function deleteForUserById(int $id, int $userId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM fe_claude_connections WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Verbindungen eines Kontos, zuletzt genutzte zuerst.
     *
     * @return list<ClaudeConnection>
     */
    public function listForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE c.user_id = ? ORDER BY c.last_seen_at DESC, c.id DESC');
        $stmt->execute([$userId]);

        return array_map(self::fromRow(...), $stmt->fetchAll());
    }

    /**
     * Aufräumen ohne Cronjob (bei jedem Aufruf des Token-Endpunkts der Stadt): abgelaufene Codes und
     * Verbindungen nach ihrer Höchstdauer (die Inaktivität prüft der Code beim Antreffen).
     */
    public function deleteExpired(int $tenantId): void
    {
        $this->pdo->prepare('DELETE FROM fe_oauth_codes WHERE tenant_id = ? AND expires_at <= UTC_TIMESTAMP()')->execute([$tenantId]);
        $this->pdo->prepare('DELETE FROM fe_claude_connections WHERE tenant_id = ? AND expires_at <= UTC_TIMESTAMP()')->execute([$tenantId]);
    }

    /**
     * Läuft in der Transaktion des Aufrufers (z. B. UserRepository, Konto gesperrt).
     *
     * @return int Anzahl der beendeten Verbindungen
     */
    public function deleteForUser(int $userId): int
    {
        $this->pdo->prepare('DELETE FROM fe_oauth_codes WHERE user_id = ?')->execute([$userId]);
        $stmt = $this->pdo->prepare('DELETE FROM fe_claude_connections WHERE user_id = ?');
        $stmt->execute([$userId]);

        return $stmt->rowCount();
    }

    /**
     * Alle Verbindungen und Codes der Stadt (Sperren der Stadt, in deren Transaktion).
     */
    public function deleteForTenant(int $tenantId): void
    {
        $this->pdo->prepare('DELETE FROM fe_oauth_codes WHERE tenant_id = ?')->execute([$tenantId]);
        $this->pdo->prepare('DELETE FROM fe_claude_connections WHERE tenant_id = ?')->execute([$tenantId]);
    }

    /**
     * Verbindungen je Konto der Stadt (für die Benutzerverwaltung); Konten ohne fehlen.
     *
     * @return array<int, ClaudeConnectionSummary> Konto-ID → Übersicht
     */
    public function summariesForTenant(int $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT user_id, COUNT(*) AS connections, MAX(last_seen_at) AS last_used_at'
            . ' FROM fe_claude_connections WHERE tenant_id = ? GROUP BY user_id',
        );
        $stmt->execute([$tenantId]);
        $summaries = [];
        foreach ($stmt->fetchAll() as $row) {
            $summaries[(int) $row['user_id']] = new ClaudeConnectionSummary((int) $row['connections'], self::time($row['last_used_at']));
        }

        return $summaries;
    }

    private function findBy(string $column, int $tenantId, string $hash, bool $lock): ?ClaudeConnection
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE ' . $column . ' = ? AND c.tenant_id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$hash, $tenantId]);
        $row = $stmt->fetch();

        return $row === false ? null : self::fromRow($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function fromRow(array $row): ClaudeConnection
    {
        return new ClaudeConnection(
            (int) $row['id'],
            (int) $row['tenant_id'],
            (int) $row['user_id'],
            (int) $row['oauth_client_id'],
            ClaudeAccess::from((string) $row['access']),
            UserRole::from((string) $row['account_kind']),
            self::time($row['created_at']),
            self::time($row['last_seen_at']),
            (int) $row['age_seconds'],
            (int) $row['idle_seconds'],
            (bool) $row['expired'],
            (int) $row['remaining_seconds'],
            (bool) $row['access_valid'],
            (bool) $row['in_grace'],
            $row['client_name'] === null ? null : (string) $row['client_name'],
            (string) $row['redirect_uri'],
        );
    }

    private static function time(mixed $value): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string) $value, new \DateTimeZone('UTC'))
            ?: throw new \UnexpectedValueException('Ungültiger Zeitstempel.');
    }
}
