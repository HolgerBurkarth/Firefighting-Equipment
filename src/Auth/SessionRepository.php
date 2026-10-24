<?php

declare(strict_types=1);

namespace FirefightingEquipment\Auth;

use FirefightingEquipment\User\UserRole;

/**
 * Zugriff auf fe_sessions. Alle Zeitvergleiche rechnet die Datenbank mit UTC_TIMESTAMP().
 */
final readonly class SessionRepository
{
    private const string COLUMNS = 'id, user_id, created_at, last_seen_at, expires_at, device_label';

    public function __construct(private \PDO $pdo)
    {
    }

    public function create(int $userId, string $tokenHash, int $maxSeconds, ?string $deviceLabel): Session
    {
        $this->pdo->prepare(
            'INSERT INTO fe_sessions (user_id, token_hash, created_at, last_seen_at, expires_at, device_label)'
            . ' VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL ? SECOND, ?)',
        )->execute([$userId, $tokenHash, $maxSeconds, $deviceLabel]);

        return $this->findById((int) $this->pdo->lastInsertId())
            ?? throw new \LogicException('Angelegte Sitzung nicht gefunden.');
    }

    public function findById(int $id): ?Session
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM fe_sessions WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return is_array($row) ? self::hydrate($row) : null;
    }

    /**
     * @return array{session: Session, idleSeconds: int, expired: bool}|null
     *         idleSeconds: Sekunden seit last_seen_at; expired: Höchstdauer erreicht
     */
    public function findByTokenHash(string $tokenHash): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ', TIMESTAMPDIFF(SECOND, last_seen_at, UTC_TIMESTAMP()) AS idle_seconds,'
            . ' expires_at <= UTC_TIMESTAMP() AS expired FROM fe_sessions WHERE token_hash = ?',
        );
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        return [
            'session' => self::hydrate($row),
            'idleSeconds' => (int) $row['idle_seconds'],
            'expired' => (int) $row['expired'] === 1,
        ];
    }

    /**
     * Gültige Sitzungen eines Kontos, zuletzt benutzte zuerst.
     *
     * @return list<Session>
     */
    public function listValidByUser(int $userId, UserRole $role): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM fe_sessions WHERE user_id = ? AND expires_at > UTC_TIMESTAMP()'
            . ' AND last_seen_at > UTC_TIMESTAMP() - INTERVAL ? SECOND ORDER BY last_seen_at DESC, id DESC',
        );
        $stmt->execute([$userId, SessionLifetime::idleSeconds($role)]);

        return array_map(self::hydrate(...), $stmt->fetchAll());
    }

    public function touch(int $id): void
    {
        $this->pdo->prepare('UPDATE fe_sessions SET last_seen_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$id]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM fe_sessions WHERE id = ?')->execute([$id]);
    }

    public function deleteByTokenHash(string $tokenHash): void
    {
        $this->pdo->prepare('DELETE FROM fe_sessions WHERE token_hash = ?')->execute([$tokenHash]);
    }

    /**
     * @return bool false, wenn es die Sitzung nicht gibt oder sie einem anderen Konto gehört
     */
    public function deleteForUser(int $id, int $userId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM fe_sessions WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);

        return $stmt->rowCount() === 1;
    }

    /**
     * @return int Anzahl beendeter Sitzungen
     */
    public function deleteOthers(int $userId, int $keepId): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM fe_sessions WHERE user_id = ? AND id <> ?');
        $stmt->execute([$userId, $keepId]);

        return $stmt->rowCount();
    }

    /**
     * Löscht Sitzungen nach Höchstdauer oder Inaktivität (Grenze je Rolle). Ersatz für einen Cronjob.
     *
     * @return int Anzahl gelöschter Sitzungen
     */
    public function deleteExpired(): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE s FROM fe_sessions s JOIN fe_users u ON u.id = s.user_id'
            . ' WHERE s.expires_at <= UTC_TIMESTAMP() OR s.last_seen_at <= UTC_TIMESTAMP() - INTERVAL'
            . " (CASE u.role WHEN 'superadmin' THEN ? WHEN 'admin' THEN ? ELSE ? END) SECOND",
        );
        $stmt->execute([
            SessionLifetime::idleSeconds(UserRole::SuperAdmin),
            SessionLifetime::idleSeconds(UserRole::Admin),
            SessionLifetime::idleSeconds(UserRole::User),
        ]);

        return $stmt->rowCount();
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): Session
    {
        return new Session(
            (int) $row['id'],
            (int) $row['user_id'],
            self::utc((string) $row['created_at']),
            self::utc((string) $row['last_seen_at']),
            self::utc((string) $row['expires_at']),
            $row['device_label'] === null ? null : (string) $row['device_label'],
        );
    }

    private static function utc(string $value): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'))
            ?: throw new \UnexpectedValueException(sprintf('Ungültiger Zeitstempel: %s', $value));
    }
}
