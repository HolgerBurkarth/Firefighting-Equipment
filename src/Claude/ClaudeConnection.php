<?php

declare(strict_types=1);

namespace FirefightingEquipment\Claude;

use FirefightingEquipment\User\UserRole;

/**
 * Eine Claude-Verbindung (fe_claude_connections) mit den Zeitangaben, die die Datenbank zum
 * Zeitpunkt des Lesens berechnet hat (UTC_TIMESTAMP(), wie bei den Sitzungen).
 */
final readonly class ClaudeConnection
{
    public function __construct(
        public int $id,
        public int $tenantId,
        public int $userId,
        public int $oauthClientId,
        /** bei der Zustimmung gewährt; wirksam ist das Minimum mit dem aktuellen Zugang des Kontos */
        public ClaudeAccess $access,
        /** Kontoart beim Verbinden (ein Wechsel zu oder von Admin beendet die Verbindung) */
        public UserRole $accountKind,
        /** UTC */
        public \DateTimeImmutable $createdAt,
        /** UTC */
        public \DateTimeImmutable $lastSeenAt,
        public int $ageSeconds,
        public int $idleSeconds,
        /** expires_at erreicht */
        public bool $expired,
        /** Sekunden bis expires_at */
        public int $remainingSeconds,
        /** Zugriffstoken vorhanden und noch nicht abgelaufen */
        public bool $accessTokenValid,
        /** Rotation vor höchstens ClaudeConnectionRepository::REFRESH_GRACE_SECONDS */
        public bool $inRefreshGrace,
        /** Name der Registrierung (z. B. "Claude") */
        public ?string $clientName,
        /** erste registrierte Rücksprung-Adresse (für die Anzeige des Ziels) */
        public string $redirectUri,
    ) {
    }
}
