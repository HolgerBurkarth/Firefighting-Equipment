<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http;

/**
 * Gleicht die Antwortzeit auf eine feste Mindestdauer an, damit sie nicht verrät, ob z. B. eine
 * Mail versendet wurde. Uhr und Warten sind für Tests austauschbar.
 */
final readonly class MinimumDuration
{
    /** @var \Closure(): float */
    private \Closure $now;

    /** @var \Closure(float): void */
    private \Closure $sleep;

    /**
     * @param (\Closure(): float)|null       $now   Sekunden (monoton); Standard hrtime()
     * @param (\Closure(float): void)|null   $sleep Standard usleep()
     */
    public function __construct(public float $seconds, ?\Closure $now = null, ?\Closure $sleep = null)
    {
        $this->now = $now ?? static fn (): float => hrtime(true) / 1e9;
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) ceil($seconds * 1e6));
        };
    }

    public function start(): float
    {
        return ($this->now)();
    }

    /**
     * Wartet, bis seit $start mindestens die Mindestdauer vergangen ist.
     *
     * @return bool false, wenn sie bereits überschritten war (dann verrät die Zeit evtl. etwas);
     *              bei einer Mindestdauer von 0 (nur Tests) immer true
     */
    public function waitSince(float $start): bool
    {
        if ($this->seconds <= 0.0) {
            return true;
        }
        $remaining = $this->seconds - (($this->now)() - $start);
        if ($remaining < 0) {
            return false;
        }
        if ($remaining > 0) {
            ($this->sleep)($remaining);
        }

        return true;
    }
}
