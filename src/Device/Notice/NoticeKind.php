<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Notice;

use FirefightingEquipment\Device\Booking\BookingReason;

/**
 * Art eines Hinweises an die Admins (Werte wie chk_fe_device_notices_kind).
 */
enum NoticeKind: string
{
    case Overwritten = 'overwritten';
    case HistoryOnly = 'history_only';
    case NotAppliedRetired = 'not_applied_retired';
    case NotAppliedLocationLocked = 'not_applied_location_locked';
    case StaleConfirmed = 'stale_confirmed';

    /**
     * Art zum Ergebnis einer Offline-Buchung; null = kein Hinweis.
     */
    public static function forResult(?BookingReason $reason, bool $staleConfirmed): ?self
    {
        return match ($reason) {
            BookingReason::Overwritten => self::Overwritten,
            BookingReason::Older => self::HistoryOnly,
            BookingReason::Retired => self::NotAppliedRetired,
            BookingReason::LocationLocked => self::NotAppliedLocationLocked,
            null => $staleConfirmed ? self::StaleConfirmed : null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Overwritten => 'Anderen Stand überschrieben',
            self::HistoryOnly => 'Nur Historie (neuerer Stand vorhanden)',
            self::NotAppliedRetired => 'Nicht übernommen (Gerät ausgesondert)',
            self::NotAppliedLocationLocked => 'Nicht übernommen (Standort gesperrt)',
            self::StaleConfirmed => 'Alte Buchung nach Rückfrage gesendet',
        };
    }

    /**
     * Kurzer Grund für den Betreff der Mail („Log <Stadt>: Hinweis – <Gerät> <Grund>“).
     */
    public function subjectReason(): string
    {
        return match ($this) {
            self::Overwritten => 'Stand überschrieben',
            self::HistoryOnly => 'nur Historie',
            self::NotAppliedRetired => 'nicht übernommen (ausgesondert)',
            self::NotAppliedLocationLocked => 'nicht übernommen (Standort gesperrt)',
            self::StaleConfirmed => 'alte Buchung nach Rückfrage',
        };
    }
}
