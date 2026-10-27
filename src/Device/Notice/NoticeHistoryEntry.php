<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Notice;

/**
 * Eintrag im Verlauf eines Hinweises: erledigt (mit optionaler Notiz) oder wieder geöffnet.
 */
final readonly class NoticeHistoryEntry
{
    public function __construct(
        public int $id,
        public NoticeChange $change,
        public NoticeUser $user,
        public ?string $note,
        /** UTC */
        public \DateTimeImmutable $at,
    ) {
    }
}
