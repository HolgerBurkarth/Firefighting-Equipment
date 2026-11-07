<?php

declare(strict_types=1);

namespace FirefightingEquipment\Claude;

/**
 * Claude-Verbindungen (fe_claude_connections) und offene Codes der Anmeldung (fe_oauth_codes).
 * Löschen ist ein echtes DELETE (keine Historie, wie die Sitzungen). Bisher nur Löschen und die
 * Übersicht je Konto; Verbinden kommt mit dem OAuth-Server (docs/PLAN-MCP.md, Schritt M3).
 */
final readonly class ClaudeConnectionRepository
{
    public function __construct(private \PDO $pdo)
    {
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
            $summaries[(int) $row['user_id']] = new ClaudeConnectionSummary(
                (int) $row['connections'],
                \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string) $row['last_used_at'], new \DateTimeZone('UTC'))
                    ?: throw new \UnexpectedValueException('Ungültiger Zeitstempel.'),
            );
        }

        return $summaries;
    }
}
