<?php

declare(strict_types=1);

namespace FirefightingEquipment\OAuth;

use FirefightingEquipment\Claude\ClaudeAccess;

/**
 * Codes der Anmeldung (fe_oauth_codes): nur der SHA-256, 5 Minuten gültig (CHECK höchstens 10),
 * gebunden an Stadt, Konto, Registrierung, Rücksprung-Adresse, Zugang und PKCE-Challenge. Einlösen
 * löscht die Zeile in jedem Fall (auch wenn danach eine Prüfung scheitert): nur einmal nutzbar.
 */
final readonly class AuthorizationCodeRepository
{
    public const int LIFETIME_SECONDS = 300;

    public function __construct(private \PDO $pdo)
    {
    }

    public function create(int $tenantId, int $userId, AuthorizationRequest $request, ClaudeAccess $access, OAuthSecret $code): void
    {
        if ($access === ClaudeAccess::Off) {
            throw new \InvalidArgumentException('Ein Code braucht Zugang.');
        }
        $this->pdo->prepare(
            'INSERT INTO fe_oauth_codes (tenant_id, user_id, oauth_client_id, code_hash, redirect_uri, code_challenge, access, created_at, expires_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL ? SECOND)',
        )->execute([
            $tenantId, $userId, $request->client->id, $code->hash(), $request->redirectUri, $request->codeChallenge,
            $access->value, self::LIFETIME_SECONDS,
        ]);
    }

    /**
     * Sperrt den Code (FOR UPDATE, in der Transaktion des Aufrufers) und löscht ihn.
     *
     * @return StoredAuthorizationCode|null null: unbekannt (nie ausgegeben oder schon eingelöst)
     */
    public function take(int $tenantId, OAuthSecret $code): ?StoredAuthorizationCode
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, oauth_client_id, redirect_uri, code_challenge, access, expires_at > UTC_TIMESTAMP() AS valid'
            . ' FROM fe_oauth_codes WHERE code_hash = ? AND tenant_id = ? FOR UPDATE',
        );
        $stmt->execute([$code->hash(), $tenantId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $this->pdo->prepare('DELETE FROM fe_oauth_codes WHERE id = ?')->execute([$row['id']]);

        return new StoredAuthorizationCode(
            (int) $row['user_id'],
            (int) $row['oauth_client_id'],
            (string) $row['redirect_uri'],
            (string) $row['code_challenge'],
            ClaudeAccess::from((string) $row['access']),
            (bool) $row['valid'],
        );
    }
}
