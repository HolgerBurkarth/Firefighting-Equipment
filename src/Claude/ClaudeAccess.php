<?php

declare(strict_types=1);

namespace FirefightingEquipment\Claude;

/**
 * Claude-Zugang eines Kontos (fe_users.claude_access, docs/MCP-KONZEPT.md, Abschnitt 2).
 */
enum ClaudeAccess: string
{
    /** Keine Verbindung möglich; bestehende enden sofort. */
    case Off = 'off';
    /** Nur die Bereiche der Rolle mit Ansehen, keine Aktionen. */
    case Read = 'read';
    /** Alle Rechte der Rolle (ohne Benutzer, Einstellungen und Log-Mails). */
    case Write = 'write';

    public function label(): string
    {
        return match ($this) {
            self::Off => 'Aus',
            self::Read => 'Lesen',
            self::Write => 'Ändern',
        };
    }

    /**
     * Der geringere Zugang (wirksam ist z. B. das Minimum aus Zustimmung und aktuellem Zugang).
     */
    public function min(self $other): self
    {
        return $this->rank() <= $other->rank() ? $this : $other;
    }

    private function rank(): int
    {
        return match ($this) {
            self::Off => 0,
            self::Read => 1,
            self::Write => 2,
        };
    }
}
