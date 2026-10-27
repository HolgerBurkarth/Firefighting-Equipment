<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Notice;

/**
 * Beteiligtes Konto in einem Hinweis: Anzeigename und E-Mail (Hinweise sehen nur Admins);
 * anonymisiert „Gelöschter Benutzer #ID“ ohne E-Mail.
 */
final readonly class NoticeUser
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $email,
    ) {
    }

    /**
     * @param array<string, mixed> $row Spalten <prefix>id, <prefix>status, <prefix>display_name,
     *                                  <prefix>name, <prefix>email
     */
    public static function fromRow(array $row, string $prefix): ?self
    {
        if ($row[$prefix . 'id'] === null) {
            return null;
        }
        $id = (int) $row[$prefix . 'id'];
        if ($row[$prefix . 'status'] === 'deleted') {
            return new self($id, sprintf('Gelöschter Benutzer #%d', $id), null);
        }
        $email = $row[$prefix . 'email'] === null ? null : (string) $row[$prefix . 'email'];

        return new self($id, (string) ($row[$prefix . 'display_name'] ?? $row[$prefix . 'name'] ?? $email ?? sprintf('#%d', $id)), $email);
    }
}
