<?php

declare(strict_types=1);

namespace FirefightingEquipment\Offline;

use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Time\Clock;
use FirefightingEquipment\Time\GermanFormat;

/**
 * Prüft die Aktionszeit einer Offline-Buchung gegen die Serverzeit (POST devices/{id}/state, /used und
 * devices/swap): mehr als MAX_CLOCK_SKEW_SECONDS in der Zukunft → 422 clock_implausible (genau die Grenze ist
 * erlaubt); älter als offline.queue_max_age_days (genau die Grenze ist erlaubt) ohne confirmed_stale → 409
 * stale_action.
 */
final readonly class ActionTimeGuard
{
    public function __construct(private Clock $clock, private OfflineSettings $offline)
    {
    }

    /**
     * @return bool alte Buchung, nach Rückfrage gesendet
     *
     * @throws HttpException
     */
    public function check(ActionTime $occurredAt, bool $confirmedStale): bool
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $maxSkew = OfflineSettings::MAX_CLOCK_SKEW_SECONDS;
        if ($occurredAt->value->getTimestamp() - $now->getTimestamp() > $maxSkew) {
            throw HttpException::clockImplausible(
                sprintf(
                    'Die Aktionszeit der Buchung (%s) liegt mehr als %d Minuten in der Zukunft. Bitte Datum und Uhrzeit des Geräts prüfen.',
                    GermanFormat::dateTime($occurredAt->value),
                    intdiv($maxSkew, 60),
                ),
                ['server_time' => ActionTime::format($now), 'occurred_at' => ActionTime::format($occurredAt->value), 'max_skew_seconds' => $maxSkew],
            );
        }

        $days = $this->offline->config()->queueMaxAgeDays;
        if ($occurredAt->value >= $now->modify(sprintf('-%d days', $days))) {
            return false;
        }
        if (!$confirmedStale) {
            throw HttpException::staleAction(
                sprintf(
                    'Die Buchung ist älter als %d Tage (Aktionszeit %s). Soll sie trotzdem gesendet werden? Dann erneut mit "confirmed_stale": true senden.',
                    $days,
                    GermanFormat::dateTime($occurredAt->value),
                ),
                ['occurred_at' => ActionTime::format($occurredAt->value), 'queue_max_age_days' => $days, 'server_time' => ActionTime::format($now)],
            );
        }

        return true;
    }
}
