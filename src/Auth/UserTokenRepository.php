<?php

declare(strict_types=1);

namespace FirefightingEquipment\Auth;

/**
 * Einmal-Links (fe_user_tokens). Je Konto und Zweck höchstens ein offener Link; ein neuer ersetzt
 * den alten. Gespeichert wird nur der SHA-256 des Tokens. Eingelöst (Zeile gelöscht) wird in
 * derselben Transaktion wie die Änderung des Kontos, z. B. UserRepository::resetPassword.
 */
final readonly class UserTokenRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Legt einen neuen Link an; ein offener Link desselben Kontos und Zwecks wird ungültig.
     */
    public function replace(int $userId, TokenPurpose $purpose): OneTimeToken
    {
        $token = OneTimeToken::generate();
        $this->pdo->prepare(
            'INSERT INTO fe_user_tokens (user_id, purpose, token_hash, created_at, expires_at)'
            . ' VALUES (?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL ? SECOND)'
            . ' ON DUPLICATE KEY UPDATE token_hash = VALUES(token_hash), created_at = VALUES(created_at),'
            . ' expires_at = VALUES(expires_at)',
        )->execute([$userId, $purpose->value, $token->hash(), $purpose->lifetimeSeconds()]);

        return $token;
    }

    /**
     * @return int|null Konto eines gültigen (nicht abgelaufenen) Links mit diesem Zweck
     */
    public function findUserId(TokenPurpose $purpose, OneTimeToken $token): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT user_id FROM fe_user_tokens WHERE token_hash = ? AND purpose = ? AND expires_at > UTC_TIMESTAMP()',
        );
        $stmt->execute([$token->hash(), $purpose->value]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * Löscht abgelaufene Links (aller Zwecke). Ersatz für einen Cronjob.
     *
     * @return int Anzahl gelöschter Links
     */
    public function deleteExpired(): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM fe_user_tokens WHERE expires_at <= UTC_TIMESTAMP()');
        $stmt->execute();

        return $stmt->rowCount();
    }
}
