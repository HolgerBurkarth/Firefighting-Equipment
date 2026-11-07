<?php

declare(strict_types=1);

namespace FirefightingEquipment\OAuth;

use FirefightingEquipment\Auth\AuthSession;
use FirefightingEquipment\Auth\SessionLifetime;
use FirefightingEquipment\Auth\SessionManager;
use FirefightingEquipment\Claude\ClaudeAccess;
use FirefightingEquipment\Claude\ClaudeAvailability;
use FirefightingEquipment\Claude\ClaudeConnection;
use FirefightingEquipment\Claude\ClaudeConnectionRepository;
use FirefightingEquipment\Claude\ClaudeSessions;
use FirefightingEquipment\Permission\PermissionRepository;
use FirefightingEquipment\Tenant\Tenant;
use FirefightingEquipment\User\UserRepository;

/**
 * Token-Endpunkt (docs/PLAN-MCP.md, Abschnitte 5 und 9a): Code einlösen (PKCE S256, einmal; ein
 * zweites Einlösen löscht die daraus entstandene Verbindung), Refresh mit Rotation (das vorige
 * Refresh-Token gilt nach einer Rotation noch 60 s einmal, später wird die Verbindung gelöscht) und
 * Widerruf. Jede Methode öffnet eine eigene Transaktion (eine offene ist ein Fehler); auch eine
 * Ablehnung wird committet, damit ein eingelöster Code und gelöschte Verbindungen weg bleiben.
 */
final readonly class TokenService
{
    private AuthorizationCodeRepository $codes;
    private ClaudeConnectionRepository $connections;
    private ClaudeSessions $sessions;
    private OAuthClientRepository $clients;
    private UserRepository $users;
    private PermissionRepository $permissions;

    public function __construct(private \PDO $pdo)
    {
        $this->codes = new AuthorizationCodeRepository($pdo);
        $this->connections = new ClaudeConnectionRepository($pdo);
        $this->sessions = new ClaudeSessions($pdo);
        $this->clients = new OAuthClientRepository($pdo);
        $this->users = new UserRepository($pdo);
        $this->permissions = new PermissionRepository($pdo);
    }

    /**
     * @throws OAuthError invalid_grant
     */
    public function exchangeCode(Tenant $tenant, OAuthClient $client, #[\SensitiveParameter] string $code, string $redirectUri, #[\SensitiveParameter] string $verifier): IssuedTokens
    {
        $secret = OAuthSecret::parse(OAuthSecretKind::Code, $code) ?? throw OAuthError::invalidGrant();

        return $this->transaction(function () use ($tenant, $client, $secret, $redirectUri, $verifier): IssuedTokens {
            $stored = $this->codes->take($tenant->id, $secret);
            if ($stored === null) {
                // Schon eingelöst (oder nie ausgegeben): Was aus dem Code entstand, gilt nicht mehr.
                $used = $this->connections->findByCodeHash($tenant->id, $secret->hash(), lock: true);
                if ($used !== null) {
                    $this->connections->delete($used->id);
                }
                throw OAuthError::invalidGrant();
            }
            if (
                !$stored->valid
                || $stored->oauthClientId !== $client->id
                || $stored->redirectUri !== $redirectUri
                || !Pkce::verify($verifier, $stored->codeChallenge)
            ) {
                throw OAuthError::invalidGrant();
            }

            $user = $this->users->findById($stored->userId);
            if ($user === null || !ClaudeAvailability::for($tenant) || !SessionManager::mayUse($user, $tenant)) {
                throw OAuthError::invalidGrant();
            }
            $access = $stored->access->min($user->claudeAccess);
            if ($access === ClaudeAccess::Off) {
                throw OAuthError::invalidGrant();
            }

            $maxSeconds = SessionLifetime::maxSeconds(AuthSession::classOf($this->permissions->forUser($tenant, $user->id)));
            $refresh = OAuthSecret::generate(OAuthSecretKind::RefreshToken);
            $accessToken = OAuthSecret::generate(OAuthSecretKind::AccessToken);
            $id = $this->connections->create(
                $tenant->id,
                $user->id,
                $client->id,
                $secret->hash(),
                $access,
                $user->role,
                $refresh->hash(),
                $accessToken->hash(),
                $maxSeconds,
            );
            $this->clients->markUsed($client->id);

            return new IssuedTokens($accessToken, $refresh, min(ClaudeConnectionRepository::ACCESS_TOKEN_SECONDS, $maxSeconds), $access, $user, $id, true);
        });
    }

    /**
     * @throws OAuthError invalid_grant
     */
    public function refresh(Tenant $tenant, OAuthClient $client, #[\SensitiveParameter] string $refreshToken): IssuedTokens
    {
        $secret = OAuthSecret::parse(OAuthSecretKind::RefreshToken, $refreshToken) ?? throw OAuthError::invalidGrant();

        return $this->transaction(function () use ($tenant, $client, $secret): IssuedTokens {
            $connection = $this->connections->findByRefreshHash($tenant->id, $secret->hash(), lock: true);
            if ($connection !== null) {
                return $this->rotate($tenant, $client, $connection, $secret->hash());
            }

            $previous = $this->connections->findByPreviousRefreshHash($tenant->id, $secret->hash(), lock: true);
            if ($previous === null || $previous->oauthClientId !== $client->id) {
                throw OAuthError::invalidGrant();
            }
            if (!$previous->inRefreshGrace) {
                // Ein altes Refresh-Token nach Ablauf der Frist: vermutlich gestohlen (O4).
                $this->connections->delete($previous->id);
                throw OAuthError::invalidGrant();
            }

            // Einmal innerhalb der Frist (z. B. Antwort verloren): neues Paar, danach kein voriges mehr.
            return $this->rotate($tenant, $client, $previous, null);
        });
    }

    /**
     * Widerruf (RFC 7009): Zugriffs- oder Refresh-Token einer Verbindung dieses Clients löscht die
     * Verbindung. Unbekannte Tokens sind kein Fehler.
     */
    public function revoke(Tenant $tenant, OAuthClient $client, #[\SensitiveParameter] string $token): void
    {
        $refresh = OAuthSecret::parse(OAuthSecretKind::RefreshToken, $token);
        $access = OAuthSecret::parse(OAuthSecretKind::AccessToken, $token);
        $connection = match (true) {
            $refresh !== null => $this->connections->findByRefreshHash($tenant->id, $refresh->hash())
                ?? $this->connections->findByPreviousRefreshHash($tenant->id, $refresh->hash()),
            $access !== null => $this->connections->findByAccessHash($tenant->id, $access->hash()),
            default => null,
        };
        if ($connection !== null && $connection->oauthClientId === $client->id) {
            $this->connections->delete($connection->id);
        }
    }

    /**
     * @param string|null $previousHash bisheriges Refresh-Token; null nach einer Wiederverwendung
     */
    private function rotate(Tenant $tenant, OAuthClient $client, ClaudeConnection $connection, ?string $previousHash): IssuedTokens
    {
        if ($connection->oauthClientId !== $client->id) {
            throw OAuthError::invalidGrant();
        }
        // Prüft Konto, Zugang und Dauer neu und löscht die Verbindung, wenn sie nicht mehr gilt.
        $session = $this->sessions->check($connection, $tenant) ?? throw OAuthError::invalidGrant();

        $refresh = OAuthSecret::generate(OAuthSecretKind::RefreshToken);
        $accessToken = OAuthSecret::generate(OAuthSecretKind::AccessToken);
        $this->connections->rotate($connection->id, $refresh->hash(), $previousHash, $accessToken->hash());

        $expiresIn = max(1, min(ClaudeConnectionRepository::ACCESS_TOKEN_SECONDS, $connection->remainingSeconds));

        return new IssuedTokens($accessToken, $refresh, $expiresIn, $session->access, $session->user, $connection->id, false);
    }

    /**
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private function transaction(\Closure $work): mixed
    {
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('TokenService öffnet eine eigene Transaktion.');
        }
        $this->pdo->beginTransaction();
        try {
            $result = $work();
        } catch (OAuthError $e) {
            $this->pdo->commit();
            throw $e;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        $this->pdo->commit();

        return $result;
    }
}
